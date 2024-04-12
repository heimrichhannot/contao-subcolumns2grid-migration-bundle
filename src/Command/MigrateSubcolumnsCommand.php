<?php /** @noinspection PhpUnusedAliasInspection */

namespace HeimrichHannot\Subcolumns2Grid\Command;

use Contao\Config;
use Contao\CoreBundle\ContaoCoreBundle;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\LayoutModel;
use Contao\StringUtil;
use Contao\ThemeModel;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\Exception;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\ParameterType;
use FelixPfeiffer\Subcolumns\ModuleSubcolumns;
use HeimrichHannot\Subcolumns2Grid\Config\ClassName;
use HeimrichHannot\Subcolumns2Grid\Config\ColSetDefinition;
use HeimrichHannot\Subcolumns2Grid\Config\ColumnDefinition;
use HeimrichHannot\Subcolumns2Grid\Config\MigrationConfig;
use HeimrichHannot\Subcolumns2Grid\Config\ContentElementDTO;
use HeimrichHannot\SubColumnsBootstrapBundle\SubColumnsBootstrapBundle;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

class MigrateSubcolumnsCommand extends Command
{
    protected const CE_TYPE_COLSET_START = 'colsetStart';
    protected const CE_TYPE_COLSET_PART = 'colsetPart';
    protected const CE_TYPE_COLSET_END = 'colsetEnd';
    protected const CE_TYPES = [
        self::CE_TYPE_COLSET_START,
        self::CE_TYPE_COLSET_PART,
        self::CE_TYPE_COLSET_END,
    ];
    protected const CE_RENAME = [
        'colsetStart' => 'bs_gridStart',
        'colsetPart'  => 'bs_gridSeparator',
        'colsetEnd'   => 'bs_gridStop',
    ];
    protected const BREAKPOINTS = ['xs', 'sm', 'md', 'lg', 'xl', 'xxl'];
    protected const UNSPECIFIC_PLACEHOLDER = '#{._.}#';

    protected Connection $connection;
    protected ContaoFramework $framework;
    protected array $mapMigratedGlobalSetsToId = [];
    protected array $mapMigratedDBSetsToId = [];
    protected int $parentThemeId;
    protected bool $skipConfirmations = false;
    protected int $gridVersion;

    public function __construct(Connection $connection, ContaoFramework $framework, ?string $name = null)
    {
        $this->connection = $connection;
        $this->framework = $framework;
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
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->skipConfirmations = $input->getOption('skip-confirmations') ?? false;

        $this->framework->initialize();

        $io = new SymfonyStyle($input, $output);
        $io->title('Migrating subcolumns to grid columns');

        $io->note('This command will migrate existing subcolumns to grid columns. Please make sure to backup your database before running this command.');
        if (!$this->skipConfirmations && !$io->confirm('Continue with the migration?')) {
            return Command::SUCCESS;
        }

        try {
            $io->comment('Fetching migration sources and preparing migration.');
            $config = $this->autoConfig($input);

            if ($from = $config->getFrom()) {
                $io->info(
                    sprintf('You are migrating from %s.',
                        $from === MigrationConfig::FROM_SUBCOLUMNS_MODULE
                            ? 'the legacy SubColumns module'
                            : 'SubColumnsBootstrapBundle')
                );
            }

            if (!$config->hasAnySource()) {
                $io->error('No migration source found.');
                return Command::FAILURE;
            }

            $this->initGridVersion($input, $io);
            $this->initParentThemeId($input, $io);

            if ($config->hasSource(MigrationConfig::SOURCE_GLOBALS))
            {
                $io->comment('Fetching definitions from globals.');
                $globalSubcolumns = $this->fetchGlobalSetDefinitions();

                $io->comment('Migrating global subcolumn definitions.');
                $newlyMigratedSubcolumns = $this->migrateGlobalSubcolumns($globalSubcolumns);
                if (empty($newlyMigratedSubcolumns)) {
                    $io->info('No new global subcolumn definitions had to be migrated.');
                } else {
                    $io->success('Migrated global subcolumn definitions successfully.');
                }

                $io->comment('Checking for module content elements.');
                if ($this->checkIfModuleContentElementsExist()) {
                    $io->comment('Migrating module content elements.');
                    $this->transformModuleContentElements();
                    $io->success('Migrated module content elements successfully.');
                } else {
                    $io->info('No module content elements found.');
                }
            }

            if ($config->hasSource(MigrationConfig::SOURCE_DB))
            {
                $io->success('Fetching definitions from database.');
                try {
                    $dbSubcolumns = $this->fetchDBSetDefinitions();
                } catch (\Throwable $e) {
                    $io->comment('Please make sure that the SubColumnsBootstrapBundle is installed and migrated to the latest version.');
                }
            }
        }
        catch (\Throwable $e)
        {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->success('Migration completed successfully.');

        return Command::SUCCESS;
    }

    /**
     * Check if there are module content elements in the database.
     *
     * Module content elements can be differentiated from subcolumn-bootstrap-bundle content elements,
     *   because the latter have a sc_columnset field that is not empty.
     *
     * @throws DBALException
     */
    protected function checkIfModuleContentElementsExist(): bool
    {
        $stmt = $this->connection->prepare(<<<'SQL'
            SELECT COUNT(*)
              FROM information_schema.COLUMNS
             WHERE TABLE_NAME = 'tl_content'
               AND COLUMN_NAME = 'sc_columnset'
        SQL);

        $result = $stmt->executeQuery();

        if (((int) $result->fetchOne()) < 1)
            // sc_columnset does not exist in tl_content,
            // therefore we can assume that only module content elements exist
        {
            return true;
        }

        $stmt = $this->connection->prepare(<<<'SQL'
            SELECT COUNT(id)
              FROM tl_content
             WHERE type LIKE "colset%"
               AND sc_columnset = ""
               AND sc_type != ""
        SQL);

        $result = $stmt->executeQuery();

        // if there are colset elements with a sc_type but no sc_columnset,
        // they have to be module content elements
        return ((int) $result->fetchOne()) > 0;
    }

    /**
     * @throws DBALException
     * @throws Throwable
     */
    protected function transformModuleContentElements(): void
    {
        $currentProfile = Config::get('subcolumns');

        if (empty($currentProfile)) {
            throw new \DomainException('No subcolumns profile found in the configuration.');
        }

        if (\strpos($currentProfile, 'yaml') !== false) {
            throw new \DomainException('YAML profiles are not supported. Please check your site configuration.');
        }

        if ($currentProfile === 'bootstrap') {
            $currentProfile = 'bootstrap3';
        }

        $stmt = $this->connection->prepare(<<<'SQL'
            SELECT id, type, sc_childs, sc_parent, sc_type, sc_name
              FROM tl_content
             WHERE type LIKE "colset%"
               AND sc_columnset = ""
        SQL);
        $result = $stmt->executeQuery();

        $contentElements = [];

        while ($row = $result->fetchAssociative())
        {
            $ce = ContentElementDTO::fromRow($row);
            $ce->setIdentifier(\sprintf('globals.%s.%s', $currentProfile, $ce->getScType()));
            $contentElements[$ce->getScParent()][] = $ce;
        }

        $this->transformContentElements($contentElements);
    }

    /**
     * @throws Throwable
     * @throws DBALException
     */
    protected function transformContentElements(array $contentElements): void
    {
        $this->connection->beginTransaction();

        try {
            foreach ($contentElements as $parentId => $rows)
            {
                $this->transformSubcolumnSetIntoGrid($parentId, $rows);
            }
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        $this->connection->commit();
    }

    /**
     * @throws DBALException
     */
    protected function transformSubcolumnSetIntoGrid(int $parentId, array $rows): void
    {
        if (empty($rows) || \count($rows) < 2) {
            throw new \DomainException('No or not enough content elements found for valid subcolumn set.');
        }

        $gridId = $this->mapMigratedGlobalSetsToId[$rows[0]->getIdentifier()] ?? null;
        if (!$gridId) {
            throw new \DomainException('No migrated global set found for content element.');
        }

        $start = \array_filter($rows, static function (ContentElementDTO $row) use ($parentId) {
            return $row->getScType() === self::CE_TYPE_COLSET_START && $row->getId() === $parentId;
        })[0] ?? null;

        if (!$start) {
            throw new \DomainException('No start element found for subcolumn set.');
        }

        $stmt = $this->connection->prepare(<<<'SQL'
            UPDATE tl_content
               SET bs_grid_parent = :parentId,
                   bs_grid_name = :name,
                   bs_grid = :gridId,
                   type = :renameType
             WHERE id = :id
        SQL);

        $stmt->bindValue('parentId', 0);
        $stmt->bindValue('name', $start->getScName());
        $stmt->bindValue('gridId', $gridId);
        $stmt->bindValue('renameType', self::CE_RENAME[self::CE_TYPE_COLSET_START]);
        $stmt->bindValue('id', $start->getId());

        $stmt->executeStatement();

        $childIds = \array_filter(\array_map(static function (ContentElementDTO $row) {
            return $row->getId();
        }, \array_slice($rows, 1)));

        $placeholders = [];
        foreach ($childIds as $index => $childId) {
            $placeholders[] = ':child_' . $index;
        }
        $placeholders = \implode(', ', $placeholders);

        $stmt = $this->connection->prepare(<<<SQL
            UPDATE tl_content
               SET bs_grid_parent = :parentId,
                   type = REPLACE(REPLACE(type, :oPart, :rPart), :oStop, :rStop)
             WHERE id IN ($placeholders)
               AND type LIKE "colset%"
        SQL);

        $stmt->bindValue('parentId', $start->getId());
        $stmt->bindValue('oPart', self::CE_TYPE_COLSET_PART);
        $stmt->bindValue('rPart', self::CE_RENAME[self::CE_TYPE_COLSET_PART]);
        $stmt->bindValue('oStop', self::CE_TYPE_COLSET_END);
        $stmt->bindValue('rStop', self::CE_RENAME[self::CE_TYPE_COLSET_END]);

        foreach ($childIds as $index => $childId) {
            $stmt->bindValue('child_' . $index, $childId, ParameterType::INTEGER);
        }

        $result = $stmt->executeStatement();
    }

    /**
     * @param array<ColSetDefinition> $globalSubcolumns
     * @return void
     * @throws DBALException
     */
    protected function migrateGlobalSubcolumns(array $globalSubcolumns): array
    {
        $stmt = $this->connection
            ->prepare('SELECT id, description FROM tl_bs_grid WHERE pid = ? AND description LIKE "[sub2col:%"');
        $stmt->bindValue(1, $this->parentThemeId);
        $migratedResult = $stmt->executeQuery();

        $migrated = [];
        while ($row = $migratedResult->fetchAssociative()) {
            $identifier = \substr($row['description'], 9, -1);
            $migrated[] = $identifier;
            $this->mapMigratedGlobalSetsToId[$identifier] = (int) $row['id'];
        }

        $newlyMigrated = [];

        $this->connection->beginTransaction();
        foreach ($globalSubcolumns as $colset)
        {
            if (\in_array($colset->getIdentifier(), $migrated, true)) {
                continue;
            }
            $id = $this->migrateGlobalSubcolumn($colset);
            $colset->setMigratedId($id);
            $this->mapMigratedGlobalSetsToId[$colset->getIdentifier()] = $id;
            $newlyMigrated[] = $colset->getIdentifier();
        }
        $this->connection->commit();

        return $newlyMigrated;
    }

    /**
     * @throws DBALException
     */
    protected function migrateGlobalSubcolumn(ColSetDefinition $colset): int
    {
        $breakpointOrder = \array_flip(self::BREAKPOINTS);

        $sizes = \array_filter($colset->getSizes(), static function ($size) {
            return \in_array($size, self::BREAKPOINTS);
        });

        \uasort($sizes, static function ($a, $b) use ($breakpointOrder) {
            return $breakpointOrder[$a] <=> $breakpointOrder[$b];
        });

        $arrColset = $colset->asArray($this->gridVersion === 3 ? 1 : 0);
        $serializeCol = function ($breakpoint) use ($arrColset) {
            if (isset($arrColset[$breakpoint])) {
                return \serialize($arrColset[$breakpoint]);
            }
            return '';
        };

        $stmt = $this->connection->prepare(<<<'SQL'
            INSERT INTO tl_bs_grid
                ( pid,  tstamp,  title,  description,  sizes,  rowClass,  xsSize,  smSize,  mdSize,  lgSize,  xlSize,  xxlSize)
            VALUES
                (:pid, :tstamp, :title, :description, :sizes, :rowClass, :xsSize, :smSize, :mdSize, :lgSize, :xlSize, :xxlSize); 
        SQL);

        $stmt->bindValue('pid', $this->parentThemeId);
        $stmt->bindValue('tstamp', time());
        $stmt->bindValue('title', $colset->getTitle());
        $stmt->bindValue('description', \sprintf('[sub2col:%s]', $colset->getIdentifier()));
        $stmt->bindValue('sizes', \serialize($sizes));
        $stmt->bindValue('rowClass', '');
        $stmt->bindValue('xsSize', $serializeCol('xs'));
        $stmt->bindValue('smSize', $serializeCol('sm'));
        $stmt->bindValue('mdSize', $serializeCol('md'));
        $stmt->bindValue('lgSize', $serializeCol('lg'));
        $stmt->bindValue('xlSize', $serializeCol('xl'));
        $stmt->bindValue('xxlSize', $serializeCol('xxl'));
        $stmt->executeStatement();

        return $this->connection->lastInsertId();
    }

    protected function fetchDBSetDefinitions(): ?array
    {
        # todo: maybe we don't have to fetch the sources but can directly execute database operations?
    }

    /**
     * @return array<ColSetDefinition>
     */
    protected function fetchGlobalSetDefinitions(): array
    {
        if (empty($GLOBALS['TL_SUBCL']) || !is_array($GLOBALS['TL_SUBCL'])) {
            throw new \OutOfBoundsException('No subcolumns found in $GLOBALS["TL_SUBCL"].');
        }

        $colsetDefinitions = [];

        foreach ($GLOBALS['TL_SUBCL'] as $profileName => $profile)
        {
            if (!is_array($profile)
                || \strpos($profileName, 'yaml') !== false
                || empty($profile['sets']))
            {
                continue;
            }

            $label = $profile['label'] ?? null;
            $scClass = $profile['scclass'] ?? null;
            $equalize = $profile['equalize'] ?? null;
            $inside = $profile['inside'] ?? null;
            $gap = $profile['gap'] ?? null;
            $files = $profile['files'] ?? null;

            foreach ($profile['sets'] as $setName => $globalColsConfig)
            {
                $sizes = $this->getSetDefinitionsFromArray($globalColsConfig);

                $idSource = $profileName === 'bootstrap' ? 'bootstrap3' : $profileName;
                $identifier = \sprintf('globals.%s.%s', $idSource, $setName);

                $colset = ColSetDefinition::create()
                    ->setIdentifier($identifier)
                    ->setTitle("$label: $setName [global]")
                    ->setPublished(true)
                    ->setSizeDefinitions($sizes)
                ;

                $colsetDefinitions[] = $colset;
            }
        }

        return $colsetDefinitions;
    }

    /**
     * @param array $colsConfig
     * @return array<string, array<int, ColumnDefinition>>
     */
    protected function getSetDefinitionsFromArray(array $colsConfig): array
    {
        /** @var array<string, array<int, ColumnDefinition>> $sizes */
        $sizes = [];

        $colIndex = 0;
        foreach ($colsConfig as $singleCol)
        {
            if (\count($singleCol) < 1) {
                continue;
            }

            $customClasses = [];
            $classNames = \explode(' ', \preg_replace('/\s+/i', ' ', $singleCol[0]));
            $colClasses = $this->filterClassNames($classNames, $customClasses);

            foreach ($colClasses as $objColClass)
            {
                $breakpoint = $objColClass->breakpoint ?: self::UNSPECIFIC_PLACEHOLDER;

                if (!isset($sizes[$breakpoint]))
                {
                    $sizes[$breakpoint] = [];
                }

                $sizes[$breakpoint][$colIndex] ??= ColumnDefinition::create()
                    ->setCustomClasses(\implode(' ', $customClasses));

                $col = $sizes[$breakpoint][$colIndex];

                switch ($objColClass->type)
                {
                    case ClassName::CLASS_TYPE_COL:
                        $col->setSpan($objColClass->width);
                        break;
                    case ClassName::CLASS_TYPE_OFFSET:
                        $col->setOffset($objColClass->width);
                        break;
                    case ClassName::CLASS_TYPE_ORDER:
                        $col->setOrder($objColClass->width);
                        break;
                }
            }

            $colIndex++;
        }

        $this->applyUnspecificSizes($sizes);

        return $sizes;
    }

    /**
     * An unspecific css class is a class, that does not specify a breakpoint,
     *   e.g. col-12, col-offset-8, offset-4, order-3
     *
     * If no breakpoint is specified, these classes are applied equivalently to the columns with the smallest
     *   breakpoint which are already present.
     *
     * Unspecific classes do not overwrite their specific counterparts. New column definitions are created in case
     *   there is no equivalent column defined due to missing specific classes.
     *
     * @param array $sizes
     * @return void
     */
    protected function applyUnspecificSizes(array &$sizes)
    {
        $unspecificCols = $sizes[self::UNSPECIFIC_PLACEHOLDER] ?? [];
        unset($sizes[self::UNSPECIFIC_PLACEHOLDER]);

        if (empty($unspecificCols)) {
            return;
        }

        $breakpointValues = \array_flip(self::BREAKPOINTS);

        $smallestKnownBreakpoint = 'xxl';
        foreach (\array_keys($sizes) as $breakpoint)
        {
            if ($breakpointValues[$breakpoint] < $breakpointValues[$smallestKnownBreakpoint])
            {
                $smallestKnownBreakpoint = $breakpoint;
            }
        }

        if ($breakpointValues[$smallestKnownBreakpoint] - 1 >= 0)
            // the smallest available breakpoint has not yet been set,
            // therefore we just assign the unspecific classes to the smallest breakpoint (xs)
        {
            $sizes[self::BREAKPOINTS[0]] = $unspecificCols;
            return;
        }

        // otherwise, the smallest breakpoint is already defined with css classes incorporating specific sizes,
        // therefore we need to apply unspecific size as fallback to the smallest breakpoint

        $specificCols = $sizes[self::BREAKPOINTS[0]] ?? [];

        $sizes[self::BREAKPOINTS[0]] = $unspecificCols;

        foreach ($specificCols as $colIndex => $specificCol)
        {
            $unspecificCol = $sizes[self::BREAKPOINTS[0]][$colIndex] ?? null;

            if (null === $unspecificCol) {
                $sizes[self::BREAKPOINTS[0]][$colIndex] = $specificCol;
                continue;
            }

            if ($span = $specificCol->getSpan())     $unspecificCol->setSpan($span);
            if ($offset = $specificCol->getOffset()) $unspecificCol->setOffset($offset);
            if ($order = $specificCol->getOrder())   $unspecificCol->setOrder($order);
        }
    }

    protected function filterClassNames(array $classNames, array &$customClasses = []): array
    {
        return \array_filter(
            \array_map(static function ($strClass) use (&$customClasses) {
                return ClassName::create($strClass, $customClasses);
            }, $classNames)
        );
    }

    protected function fetchDatabaseSubcolumns(): void
    {
        if (empty($GLOBALS['TL_DCA']['tl_content']['fields']['sc_columnset'])) {
            throw new \DomainException('No sc_columnset field found in tl_content.');
        }
    }

    //<editor-fold desc="Grid version handling">

    /**
     * @throws \Exception
     */
    protected function initGridVersion(InputInterface $input, SymfonyStyle $io)
    {
        $gridVersion = $input->getOption('grid-version');
        if ($gridVersion !== null && \in_array($gridVersion = (int) \ltrim($gridVersion, ' :='), [2, 3])) {
            $this->gridVersion = $gridVersion;
            $io->info("Migrating to contao-bootstrap/grid version $gridVersion.");
            return;
        }

        if (!isset($this->gridVersion)) {
            $version = ContaoCoreBundle::getVersion();
            $isContao5 = \version_compare($version, '5', '>=');
            if ($isContao5) {
                $io->info("Detected Contao version $version. Migrating to contao-bootstrap/grid version 3.");
                $this->gridVersion = 3;
            } else {
                $io->comment("Detected Contao version $version.");
                $this->gridVersion = $this->askForGridVersion($io);
            }
        }

        if ($this->gridVersion === 3 && \version_compare(\phpversion(), '8.1', '<')) {
            throw new \Exception('Grid version 3 is incompatible with php version < 8.1');
        }

        if (empty($this->gridVersion)) {
            throw new \Exception('No grid version defined.');
        }
    }

    protected function askForGridVersion(SymfonyStyle $io): int
    {
        $options = [
            2 => 'contao-bootstrap/grid: ^2',
            3 => 'contao-bootstrap/grid: ^3',
        ];
        $version = $io->choice('Select the version of contao-bootstrap/grid to migrate to', $options);
        return (int) \array_search($version, $options);
    }

    //</editor-fold>

    //<editor-fold desc="Parent theme handling">

    /**
     * @throws \Exception
     */
    protected function initParentThemeId(InputInterface $input, SymfonyStyle $io): void
    {
        $parentLayoutId = $input->getOption('parent-theme');  // caution: can be "0"
        if ($parentLayoutId !== null && \is_numeric($parentLayoutId = \ltrim($parentLayoutId, ' :='))) {
            $this->parentThemeId = (int) $parentLayoutId;
        }

        if (!isset($this->parentThemeId)) {
            $this->parentThemeId = $this->askForParentTheme($io);
        }

        if ($this->parentThemeId === 0) {
            $this->parentThemeId = $this->createNewTheme();
        }

        if (!$this->parentThemeId || $this->parentThemeId < 1) {
            throw new \Exception('No parent theme defined.');
        }
    }

    protected function askForParentTheme(SymfonyStyle $io): int
    {
        $layouts = ThemeModel::findAll();
        $layoutOptions = [
            'new' => 'Create a new theme',
        ];
        foreach ($layouts as $layout) {
            $layoutOptions[$layout->id] = $layout->name;
        }
        $id = $io->choice('Select the parent theme to assign the new grid columns to', $layoutOptions);
        return (int) $id;  // (int) "new" === 0
    }

    protected function createNewTheme(): int
    {
        $layout = new ThemeModel();
        $layout->setRow([
            'tstamp' => time(),
            'name' => 'Grids migrated from SubColumns',
            'author' => 'Subcolumns2Grid',
            'vars' => \serialize([])
        ]);
        $layout->save();
        return $layout->id;
    }

    //</editor-fold>

    /**
     * @throws DBALException
     */
    protected function autoConfig(InputInterface $input): MigrationConfig
    {
        $config = new MigrationConfig();

        $from = $this->smartGetFrom($input);
        $config->setFrom($from);

        switch ($from)
        {
            case MigrationConfig::FROM_SUBCOLUMNS_MODULE:
                $config->addSource(MigrationConfig::SOURCE_GLOBALS);
                break;

            case MigrationConfig::FROM_SUBCOLUMNS_BOOTSTRAP_BUNDLE:

                throw new \InvalidArgumentException('Migrating from the SubColumnsBootstrapBundle is not supported yet.');

                // $config->addFetch(MigrationConfig::FETCH_DB);
                //
                // if (\class_exists(ModuleSubcolumns::class)) {
                //     $config->addFetch(MigrationConfig::FETCH_GLOBALS);
                //     break;
                // }
                //
                // $stmt = $this->connection->prepare(<<<'SQL'
                //     SELECT id FROM tl_content WHERE type IN :types AND sc_columnset LIKE "globals.%" LIMIT 1
                // SQL);
                // $stmt->bindValue('types', static::CE_TYPES);
                //
                // $res = $stmt->executeQuery();
                // if ($res->rowCount() > 0) {
                //     $config->addFetch(MigrationConfig::FETCH_GLOBALS);
                // }

                break;
        }

        return $config;
    }

    //<editor-fold desc="Get option 'from'">

    /**
     * @throws DBALException
     */
    protected function smartGetFrom(InputInterface $input): int
    {
        return $this->getFrom($input) ?? $this->autoDetectFrom();
    }

    protected function getFrom(InputInterface $input): ?int
    {
        $from = ($from = $input->getOption('from')) ? \ltrim($from, ' :=') : null;

        switch ($from) {
            case null: return null;
            case 'm': return MigrationConfig::FROM_SUBCOLUMNS_MODULE;
            case 'b': return MigrationConfig::FROM_SUBCOLUMNS_BOOTSTRAP_BUNDLE;
            default: throw new \InvalidArgumentException('Invalid source.');
        }
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

        return null;
    }

    //</editor-fold>
}