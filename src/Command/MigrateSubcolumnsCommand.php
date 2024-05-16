<?php /** @noinspection PhpUnusedAliasInspection */

namespace HeimrichHannot\Subcolumns2Grid\Command;

use Contao\Config;
use Contao\Controller;
use Contao\CoreBundle\ContaoCoreBundle;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\LayoutModel;
use Contao\StringUtil;
use Contao\System;
use Contao\ThemeModel;
use ContaoBootstrap\Grid\ContaoBootstrapGridBundle;
use ContaoBootstrap\Grid\Model\GridModel;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DBALException as DBALDBALException;
use Doctrine\DBAL\Driver\Exception as DBALDriverException;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Result;
use FelixPfeiffer\Subcolumns\ModuleSubcolumns;
use HeimrichHannot\Subcolumns2Grid\Config\BreakpointDTO;
use HeimrichHannot\Subcolumns2Grid\Config\ClassName;
use HeimrichHannot\Subcolumns2Grid\Config\ColsetDefinition;
use HeimrichHannot\Subcolumns2Grid\Config\ColumnDefinition;
use HeimrichHannot\Subcolumns2Grid\Config\MigrationConfig;
use HeimrichHannot\Subcolumns2Grid\Config\ColsetElementDTO;
use HeimrichHannot\Subcolumns2Grid\Exception\ConfigException;
use HeimrichHannot\Subcolumns2Grid\Exception\Sub2ColException;
use HeimrichHannot\Subcolumns2Grid\HeimrichHannotSubcolumns2GridMigrationBundle;
use HeimrichHannot\Subcolumns2Grid\Manager\MigrationManager;
use HeimrichHannot\Subcolumns2Grid\Util\Constants;
use HeimrichHannot\Subcolumns2Grid\Util\Helper;
use HeimrichHannot\SubColumnsBootstrapBundle\SubColumnsBootstrapBundle;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Throwable;

class MigrateSubcolumnsCommand extends Command
{
    protected Connection $connection;
    protected ContaoFramework $framework;
    protected KernelInterface $kernel;
    protected MigrationManager $migrationManager;
    protected ParameterBagInterface $parameterBag;

    public function __construct(
        Connection            $connection,
        ContaoFramework       $framework,
        KernelInterface       $kernel,
        MigrationManager      $migrationManager,
        ParameterBagInterface $parameterBag,
        ?string               $name = null
    ) {
        $this->connection = $connection;
        $this->framework = $framework;
        $this->kernel = $kernel;
        $this->migrationManager = $migrationManager;
        $this->parameterBag = $parameterBag;
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('sub2grid:migrate')
            ->setDescription('Migrates existing subcolumns to grid columns.')
            ->addOption(
                'from',
                'f',
                InputOption::VALUE_REQUIRED,
                'The source to migrate from. Can be "m" for the SubColumns module or "b" for the SubColumnsBootstrapBundle.'
            )
            ->addOption(
                'skip-confirmations',
                'y',
                InputOption::VALUE_NONE,
                'Skip all confirmations and proceed with the migration.'
            )
            ->addOption(
                'parent-theme',
                't',
                InputOption::VALUE_REQUIRED,
                'The parent theme id to assign the new grid columns to. May be 0 to create a new layout.'
            )
            ->addOption(
                'grid-version',
                'g',
                InputOption::VALUE_REQUIRED,
                'The version of contao-bootstrap/grid to migrate to. Must be 2 or 3.'
            )
            ->addOption(
                'subcolumn-profile',
                'p',
                InputOption::VALUE_REQUIRED,
                'The profile to migrate. Must be the name of a profile in the SubColumns module.'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Do not execute the migration, but show what would be done.'
            )
            ->addOption(
                'force-global',
                null,
                InputOption::VALUE_NONE,
                'Force the migration of sub-column sets defined in $GLOBALS["TL_SUBCL"].'
            )
            ->addOption(
                'force-db',
                null,
                InputOption::VALUE_NONE,
                'Force the migration of sub-column sets defined in the database.'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->framework->initialize();

        $io = new SymfonyStyle($input, $output);

        try
        {
            $this->loadGridBundle($io);

            $isDryRun = $this->initDryRun($input, $io);

            $migrationConfig = $this->createMigrationConfig($input, $io);

            $this->connection->beginTransaction();

            try
            {
                // this is where the magic happens
                $this->migrationManager->migrate($io, $migrationConfig);
            }
            catch (Throwable $t)
            {
                $this->connection->rollBack();
                throw $t;
            }

            $isDryRun
                ? $this->connection->rollBack()
                : $this->connection->commit();

            $this->printNotes($io, $migrationConfig);

            $io->success($isDryRun
                ? 'Migration dry run completed successfully. No changes were made.'
                : 'Migration completed successfully.'
            );

            return Command::SUCCESS;
        }
        catch (Sub2ColException $e)
        {
            if (isset($migrationConfig)) {
                $this->printNotes($io, $migrationConfig);
            }

            $io->error($e->getMessage());
            return Command::FAILURE;
        }
        catch (\Throwable $e)
        {
            $io->error($e->getMessage());
            $io->getErrorStyle()->block($e->getTraceAsString());
            return Command::FAILURE;
        }
    }

    /**
     * @throws ConfigException|Throwable
     */
    protected function initDryRun(InputInterface $input, SymfonyStyle $io): bool
    {
        if (!($input->getOption('dry-run') ?? false))
        {
            return false;
        }

        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS `tl_s2g_transaction_test` (`id` INT PRIMARY KEY)');

        $this->connection->beginTransaction();
        $this->connection->executeStatement('INSERT INTO `tl_s2g_transaction_test` (`id`) VALUES (1)');
        $this->connection->rollBack();

        $supportsTransactions = $this->connection->executeQuery('SELECT COUNT(*) FROM `tl_s2g_transaction_test`')->fetchOne() === 0;

        $this->connection->executeStatement('DROP TABLE `tl_s2g_transaction_test`');

        if (!$supportsTransactions) {
            throw new ConfigException('The database does not support transactions. Cannot run in dry-run mode.');
        }

        $io->warning('Running in dry-run mode. No changes will be made to the database.');

        return true;
    }

    protected function printNotes(SymfonyStyle $io, MigrationConfig $config)
    {
        foreach ($config->getNotes() as $note) {
            $io->note($note);
        }
    }

    /**
     * @throws ConfigException
     */
    protected function loadGridBundle(SymfonyStyle $io): void
    {
        if (!\class_exists(ContaoBootstrapGridBundle::class)) {
            throw new ConfigException('The contao-bootstrap/grid bundle is not installed.');
        }

        Controller::loadDataContainer(GridModel::getTable());
    }

    /**
     * @throws DBALDriverException|DBALDBALException|DBALException
     * @throws ConfigException
     */
    protected function createMigrationConfig(InputInterface $input, SymfonyStyle $io): MigrationConfig
    {
        $io->title('Setting up migration configuration');
        $config = new MigrationConfig();

        $config->setSourceDBForced($input->getOption('force-db') ?? false);
        $config->setSourceGlobalForced($input->getOption('force-global') ?? false);

        $io->text('Assessing default sub-column profile...');
        $profile = $this->smartGetSubcolumnProfile($input);
        if ($profile === null) {
            throw new ConfigException('No default profile specified. Please set one in your site\'s configuration or provide the --subcolumn-profile option.');
        }
        $config->setProfile($profile);

        if ($profile = $config->getProfile()) {
            $io->info("The default profile is set to \"$profile\".");
        } else {
            throw new ConfigException('No default profile specified. Please set one in your site\'s configuration or provide the --profile option.');
        }

        $io->text('Assessing the package to migrate from...');
        $from = $this->smartGetFrom($input);
        if ($from === null) {
            throw new ConfigException('No package to migrate from specified and could not be detected automatically. Please check your database or provide the --from option.');
        }
        $config->setFrom($from);

        if ($from = $config->getFrom()) {
            $io->info(
                sprintf('You are migrating from %s.',
                    $from === MigrationConfig::FROM_SUBCOLUMNS_MODULE
                        ? 'the legacy SubColumns module'
                        : 'SubColumnsBootstrapBundle')
            );
        } else {
            $io->warning('No package to migrate from specified.');
        }

        $io->text('Fetching migration sources...');
        $this->autoConfigSources($config);

        if (!$config->hasAnySource()) {
            throw new ConfigException('No migration source found.');
        }

        $io->info(\sprintf(
            'Found sources for migration in: %s.',
            implode(', ', $config->getValidSourcesNamed())
        ));

        $config->setGridVersion(self::initGridVersion($input, $io, $info));
        $io->info($info ?? "Migrating to contao-bootstrap/grid version {$config->getGridVersion()}.");

        $config->setParentThemeId(self::initParentThemeId($input, $io));
        $io->info("Assigning new grid columns to parent theme with ID {$config->getParentThemeId()}.");

        $io->text('Fetching already migrated sub-column sets...');
        $migratedIdentifiers = $this->migrationManager->getMigratedIdentifiers($config->getParentThemeId());
        $config->setMigratedIdentifiers($migratedIdentifiers);

        if (!empty($migratedIdentifiers)) {
            $io->text("Already migrated sub-column identifiers on theme {$config->getParentThemeId()}:");
            $io->listing($migratedIdentifiers);
        }
        $io->info(\sprintf(
            'Found %s already migrated sub-column sets on theme %s%s.',
            $migratedIdentifiersCount = \count($migratedIdentifiers),
            $config->getParentThemeId(),
            $migratedIdentifiersCount > 0 ? ', which will be skipped' : ''
        ));

        return $config;
    }

    /**
     * @throws DBALException
     */
    protected function dbColumnExists(string $table, string $column): bool
    {
        return Helper::dbColumnExists($this->connection, $table, $column);
    }

    /**
     * @throws DBALDriverException|DBALDBALException|DBALException
     * @throws ConfigException
     */
    protected function autoConfigSources(MigrationConfig $config): void
    {
        if ($config->isSourceGlobalForced()) {
            $config->addSource(MigrationConfig::SOURCE_GLOBALS);
        }

        if ($config->isSourceDBForced()) {
            $config->addSource(MigrationConfig::SOURCE_DB);
        }

        if ($config->isSourceGlobalForced() || $config->isSourceDBForced()) {
            return;
        }

        $from = $config->getFrom();

        if (!$config->hasFrom())
        {
            throw new ConfigException(
                'Not specified from which package to migrate. '
                . 'Either provide --from [m/b] to perform automatic checks on what needs migration, '
                . 'or force the migration of $GLOBALS["TL_SUBCL"] with --force-global, '
                . 'or force the migration of database defined sub-columns with --force-db.'
            );
        }

        switch ($from)
        {
            case MigrationConfig::FROM_SUBCOLUMNS_MODULE:
                $config->addSource(MigrationConfig::SOURCE_GLOBALS);
                break;

            case MigrationConfig::FROM_SUBCOLUMNS_BOOTSTRAP_BUNDLE:

                $config->addSource(MigrationConfig::SOURCE_DB);

                $needFetchGlobals = function (string $table, array $types) {
                    if (!$this->dbColumnExists($table, 'sc_columnset')) {
                        return false;
                    }

                    $inTypes = \implode(', ', \array_map(fn($type) => "'$type'", $types));
                    $stmt = $this->connection->prepare(<<<SQL
                        SELECT `id` FROM $table WHERE `type` IN ($inTypes) AND `sc_columnset` LIKE "globals.%" LIMIT 1
                    SQL);

                    return $stmt->executeQuery()->rowCount() > 0;
                };

                if ($needFetchGlobals('tl_content', Constants::CE_TYPES)
                    || $needFetchGlobals('tl_form_field', Constants::FF_TYPES))
                {
                    $config->addSource(MigrationConfig::SOURCE_GLOBALS);
                }

                break;

            default:
                throw new \InvalidArgumentException('Invalid "from".');
        }
    }

    //<editor-fold desc="Options">

    /**
     * @throws DBALException
     */
    protected function smartGetFrom(InputInterface $input): ?int
    {
        return self::getOptionFrom($input) ?? $this->autoDetectFrom();
    }

    protected function smartGetSubcolumnProfile(InputInterface $input): ?string
    {
        return $this->getOptionProfile($input) ?? Config::get('subcolumns');
    }

    protected static function getOptionFrom(InputInterface $input): ?int
    {
        $from = ($from = $input->getOption('from')) ? \ltrim($from, ' :=') : null;

        switch ($from) {
            case null: return null;
            case 'm': return MigrationConfig::FROM_SUBCOLUMNS_MODULE;
            case 'b': return MigrationConfig::FROM_SUBCOLUMNS_BOOTSTRAP_BUNDLE;
        }

        throw new \InvalidArgumentException('Invalid source.');
    }

    protected function getOptionProfile(InputInterface $input): ?string
    {
        $profile = ($profile = $input->getOption('subcolumn-profile')) ? \ltrim($profile, ' :=') : null;

        if ($profile === null) {
            return null;
        }

        return $profile;
    }

    /**
     * @throws DBALException
     */
    protected function autoDetectFrom(): ?int
    {
        if (class_exists( SubColumnsBootstrapBundle::class)) {
            return MigrationConfig::FROM_SUBCOLUMNS_BOOTSTRAP_BUNDLE;
        }

        if (class_exists(ModuleSubcolumns::class)) {
            return MigrationConfig::FROM_SUBCOLUMNS_MODULE;
        }

        $table = $this->connection
            ->prepare('SHOW TABLES LIKE "tl_columnset"')
            ->executeQuery()
            ->fetchOne();

        if ($table === 'tl_columnset')
        {
            return MigrationConfig::FROM_SUBCOLUMNS_BOOTSTRAP_BUNDLE;
        }

        if ($this->dbColumnExists('tl_content', 'sc_columnset'))
        {
            return MigrationConfig::FROM_SUBCOLUMNS_BOOTSTRAP_BUNDLE;
        }

        if ($this->dbColumnExists('tl_content', 'sc_type'))
        {
            return MigrationConfig::FROM_SUBCOLUMNS_MODULE;
        }

        return null;
    }

    protected static function askForFrom(SymfonyStyle $io): int
    {
        $options = [
            MigrationConfig::FROM_SUBCOLUMNS_MODULE => 'heimrichhannot/subcolumns',
            MigrationConfig::FROM_SUBCOLUMNS_BOOTSTRAP_BUNDLE => 'heimrichhannot/contao-subcolumns-bootstrap-bundle',
        ];
        $from = $io->choice('Select which package to migrate from', $options);
        return (int) $from;
    }

    //</editor-fold>

    //<editor-fold desc="Grid version configuration">

    /**
     * @throws \Exception
     */
    protected static function initGridVersion(InputInterface $input, SymfonyStyle $io, string &$info = null): int
    {
        $gridVersion = $input->getOption('grid-version');
        if ($gridVersion !== null && \in_array($gridVersion = (int) \ltrim($gridVersion, ' :='), [2, 3])) {
            return $gridVersion;
        }

        if (!$gridVersion) {
            $version = ContaoCoreBundle::getVersion();
            $phpVers = \implode('.', \array_slice(\explode('.', \explode('-', \phpversion())[0]), 0, 3));
            $isContao5 = \version_compare($version, '5', '>=');
            $io->text("Detected Contao version $version on PHP $phpVers.");
            if ($isContao5) {
                $info = "Necessarily migrating to contao-bootstrap/grid version 3.";
                $gridVersion = 3;
            } else {
                $gridVersion = self::askForGridVersion($io);
            }
        }

        if ($gridVersion === 3 && \version_compare(\phpversion(), '8.1', '<')) {
            throw new \Exception('Grid version 3 is incompatible with php version < 8.1');
        }

        if (empty($gridVersion)) {
            throw new \Exception('No grid version defined.');
        }

        return $gridVersion;
    }

    protected static function askForGridVersion(SymfonyStyle $io): int
    {
        $options = [
            2 => 'contao-bootstrap/grid: ^2',
            3 => 'contao-bootstrap/grid: ^3',
        ];
        $version = $io->choice('Select the version of contao-bootstrap/grid to migrate to', $options);
        return (int) \array_search($version, $options);
    }

    //</editor-fold>

    //<editor-fold desc="Parent theme configuration">

    /**
     * @throws \Exception
     */
    protected static function initParentThemeId(InputInterface $input, SymfonyStyle $io): int
    {
        $parentThemeId = $input->getOption('parent-theme');  // caution: can be "0"
        if ($parentThemeId !== null && \is_numeric($parentThemeId = \ltrim($parentThemeId, ' :='))) {
            return (int) $parentThemeId;
        }

        if ($parentThemeId === null) {
            $parentThemeId = self::askForParentTheme($io);
        }

        if ($parentThemeId === 0) {
            $parentThemeId = self::createNewTheme();
        }

        if (!$parentThemeId || $parentThemeId < 1) {
            throw new \Exception('No parent theme defined.');
        }

        return $parentThemeId;
    }

    protected static function askForParentTheme(SymfonyStyle $io): int
    {
        $layouts = ThemeModel::findAll();
        $layoutOptions = [
            'new' => 'Create a new theme',
        ];
        foreach ($layouts as $layout) {
            $layoutOptions[$layout->id] = $layout->name;
        }
        $id = $io->choice('Select the parent theme to assign the new grid columns to', $layoutOptions);
        return (int) $id;
    }

    protected static function createNewTheme(): int
    {
        $layout = new ThemeModel();
        $layout->setRow([
            'tstamp' => time(),
            'name' => 'Grids migrated from SubColumns',
            'author' => 'Subcolumns2Grid',
            'vars' => \serialize([]),
        ]);
        $layout->save();
        return $layout->id;
    }

    //</editor-fold>
}