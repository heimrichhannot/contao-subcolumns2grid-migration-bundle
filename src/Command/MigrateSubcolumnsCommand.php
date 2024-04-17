<?php /** @noinspection PhpUnusedAliasInspection */

namespace HeimrichHannot\Subcolumns2Grid\Command;

use Contao\Config;
use Contao\CoreBundle\ContaoCoreBundle;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\LayoutModel;
use Contao\StringUtil;
use Contao\System;
use Contao\ThemeModel;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Result;
use FelixPfeiffer\Subcolumns\ModuleSubcolumns;
use HeimrichHannot\Subcolumns2Grid\Config\ClassName;
use HeimrichHannot\Subcolumns2Grid\Config\ColsetDefinition;
use HeimrichHannot\Subcolumns2Grid\Config\ColumnDefinition;
use HeimrichHannot\Subcolumns2Grid\Config\MigrationConfig;
use HeimrichHannot\Subcolumns2Grid\Config\ColsetElementDTO;
use HeimrichHannot\Subcolumns2Grid\HeimrichHannotSubcolumns2GridMigrationBundle;
use HeimrichHannot\SubColumnsBootstrapBundle\SubColumnsBootstrapBundle;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpKernel\KernelInterface;

class MigrateSubcolumnsCommand extends Command
{
    protected const CE_TYPE_COLSET_START = 'colsetStart';
    protected const CE_TYPE_COLSET_PART = 'colsetPart';
    protected const CE_TYPE_COLSET_END = 'colsetEnd';

    protected const FF_TYPE_FORMCOL_START = 'formcolstart';
    protected const FF_TYPE_FORMCOL_PART = 'formcolpart';
    protected const FF_TYPE_FORMCOL_END = 'formcolend';
    protected const BS_GRID_START_TYPE = 'bs_gridStart';
    protected const BS_GRID_SEPARATOR_TYPE = 'bs_gridSeparator';
    protected const BS_GRID_STOP_TYPE = 'bs_gridStop';
    protected const RENAME_TYPE = [
        self::CE_TYPE_COLSET_START  => self::BS_GRID_START_TYPE,
        self::CE_TYPE_COLSET_PART   => self::BS_GRID_SEPARATOR_TYPE,
        self::CE_TYPE_COLSET_END    => self::BS_GRID_STOP_TYPE,
        self::FF_TYPE_FORMCOL_START => self::BS_GRID_START_TYPE,
        self::FF_TYPE_FORMCOL_PART  => self::BS_GRID_SEPARATOR_TYPE,
        self::FF_TYPE_FORMCOL_END   => self::BS_GRID_STOP_TYPE,
    ];

    protected const BREAKPOINTS = ['xs', 'sm', 'md', 'lg', 'xl', 'xxl'];
    protected const UNSPECIFIC_PLACEHOLDER = '#{._.}#';

    protected Connection $connection;
    protected ContaoFramework $framework;
    protected KernelInterface $kernel;
    protected ParameterBagInterface $parameterBag;

    protected array $mapMigratedGlobalSubcolumnIdentifiersToBsGridId = [];
    protected bool $skipConfirmations = false;
    protected array $templateCache = [];
    protected array $dbColumnsCache = [];

    public function __construct(
        Connection            $connection,
        ContaoFramework       $framework,
        KernelInterface       $kernel,
        ParameterBagInterface $parameterBag,
        ?string               $name = null
    ) {
        $this->connection = $connection;
        $this->framework = $framework;
        $this->kernel = $kernel;
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
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->skipConfirmations = $input->getOption('skip-confirmations') ?? false;

        $this->framework->initialize();

        $io = new SymfonyStyle($input, $output);
        $config = new MigrationConfig();

        try {
            return $this->migrate($input, $io, $config);
        }
        catch (\Throwable $e)
        {
            $io->error($e->getMessage());
            $io->getErrorStyle()->block($e->getTraceAsString());
            return Command::FAILURE;
        }
    }

    /**
     * @throws \Throwable
     * @throws DBALException
     */
    protected function migrate(InputInterface $input, SymfonyStyle $io, MigrationConfig $config): int
    {
        $io->title('Migrating sub-columns to grid columns');

        $io->note('This command will migrate existing sub-columns to grid columns. Please make sure to backup your database before running this command.');
        if (!$this->skipConfirmations && !$io->confirm('Proceed with the migration?')) {
            return Command::SUCCESS;
        }

        $io->text('Fetching migration sources and preparing migration.');
        $this->autoConfig($input, $config);

        if ($config->getProfile()) {
            $io->info("The default profile is set to \"{$config->getProfile()}\".");
        } else {
            $io->error('No default profile specified. Please set one in your site\'s configuration or provide the --profile option.');
            return Command::FAILURE;
        }

        if ($config->getFrom()) {
            $io->info(
                sprintf('You are migrating from %s.',
                    $config->getFrom() === MigrationConfig::FROM_SUBCOLUMNS_MODULE
                        ? 'the legacy SubColumns module'
                        : 'SubColumnsBootstrapBundle')
            );
        } else {
            $io->warning('No package to migrate from specified.');
        }

        if (!$config->hasAnySource()) {
            $io->error('No migration source found.');
            return Command::FAILURE;
        }

        $config->setGridVersion(self::initGridVersion($input, $io));
        $io->info("Migrating to contao-bootstrap/grid version {$config->getGridVersion()}.");

        $config->setParentThemeId(self::initParentThemeId($input, $io));
        $io->info("Assigning new grid columns to parent theme with ID {$config->getParentThemeId()}.");

        $io->text('Fetching already migrated sub-column sets.');
        $migratedIdentifiers = $this->getMigratedIdentifiers($config->getParentThemeId());
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

        if ($config->hasSource(MigrationConfig::SOURCE_GLOBALS))
        {
            if (!$this->skipConfirmations &&
                !$io->confirm("Migrate globally defined sub-column profiles now?"))
            {
                $io->info("Skipping migration of globally defined sub-column sets.");
            }
            else
            {
                $io->section('Migrating globally defined sub-column sets.');
                $this->migrateGlobal($io, $config);
            }
        }

        // if ($config->hasSource(MigrationConfig::SOURCE_DB))
        // {
        //     $io->success('Fetching definitions from database.');
        //     try {
        //         $dbSubcolumns = $this->fetchDBSetDefinitions();
        //     } catch (\Throwable $e) {
        //         $io->note('Please make sure that the SubColumnsBootstrapBundle is installed and migrated to the latest version.');
        //     }
        // }

        foreach ($config->getNotes() as $note) {
            $io->note($note);
        }

        $io->success('Migration completed successfully.');

        return Command::SUCCESS;
    }

    /**
     * @throws \Throwable
     * @throws DBALException
     */
    protected function migrateGlobal(SymfonyStyle $io, MigrationConfig $config)
    {
        $io->text('Fetching definitions from globals.');
        $globalSubcolumns = $this->fetchGlobalSetDefinitions($config);

        if (empty($globalSubcolumns)) {
            $io->caution('Skipping migration of globally defined sub-column profiles, as none were found.');
            return;
        }

        $config->setGlobalSubcolumnDefinitions($globalSubcolumns);
        $io->listing(\array_map(static function (ColsetDefinition $colset) {
            return $colset->getIdentifier();
        }, $globalSubcolumns));
        $io->info(\sprintf('Evaluated %s globally defined sub-column sets.', \count($globalSubcolumns)));

        $io->text('Preparing templates for missing inside containers.');
        $copiedTemplates = $this->prepareTemplates($globalSubcolumns);

        if (empty($copiedTemplates)) {
            $io->info('No templates had to be copied.');
        } else {
            $io->listing($copiedTemplates);
            $io->success('Copied templates successfully.');
        }

        $io->text('Migrating global sub-column definitions.');
        $newlyMigratedIdentifiers = $this->migrateGlobalSubcolumnDefinitions($config);
        if (empty($newlyMigratedIdentifiers)) {
            $io->info('No globally defined sub-column sets had to be migrated anew.');
        } else {
            $io->listing($newlyMigratedIdentifiers);
            $config->addMigratedIdentifiers(...$newlyMigratedIdentifiers);
            $io->success('Migrated global sub-column definitions successfully.');
        }

        $io->text('Checking for module content elements.');
        if ($this->checkIfModuleContentElementsExist())
        {
            $io->text('Migrating module content elements.');
            $this->transformModuleContentElements($config);

            $io->success('Migrated module content elements successfully.');
        }
        else
        {
            $io->info('No module content elements found.');
        }

        $io->text('Checking for module form fields.');
        if ($this->checkIfModuleFormFieldsExist())
        {
            $io->text('Migrating module form fields.');
            $this->transformModuleFormFields($config);

            $io->success('Migrated module form fields successfully.');
        }
        else
        {
            $io->info('No module form fields found.');
        }
    }

    /**
     * @throws DBALException
     */
    protected function dbColumnExists(string $table, string $column): bool
    {
        if (isset($this->dbColumnsCache[$table . "." . $column])) {
            return $this->dbColumnsCache[$table . "." . $column];
        }

        $stmt = $this->connection->prepare(<<<SQL
            SELECT COUNT(*)
              FROM information_schema.COLUMNS
             WHERE TABLE_NAME = :table
               AND COLUMN_NAME = :column
             LIMIT 1
        SQL);

        $stmt->bindValue('table', $table);
        $stmt->bindValue('column', $column);

        $result = $stmt->executeQuery();

        return $this->dbColumnsCache[$table . "." . $column] = (int)$result->fetchOne() > 0;
    }

    /**
     * @return array<int, ColsetElementDTO[]> A map of parent IDs to their respective colset element data transfer
     *   objects, that may either represent content elements or form fields.
     * @throws DBALException
     */
    protected function dbResult2colsetElementDTOs(
        MigrationConfig $config,
        Result          $rows,
        ?array          $columnsMap = null,
        ?string         $table = null
    ): array {
        $currentProfile = $config->getProfile();

        /** @var array<int, ColsetElementDTO[]> $contentElements */
        $contentElements = [];

        while ($row = $rows->fetchAssociative())
        {
            $ce = ColsetElementDTO::fromRow($row, $columnsMap);
            if ($table !== null) {
                $ce->setTable($table);
            }

            $identifier = \sprintf('globals.%s.%s', $currentProfile, $ce->getScType());
            $ce->setIdentifier($identifier);

            if (!$ce->getCustomTpl())
            {
                $customTpl = $this->getColumnTemplateFromIdentifier($config, $identifier, $ce->getType());
                $ce->setCustomTpl($customTpl ?? '');
            }

            $contentElements[$ce->getScParent()][] = $ce;
        }

        return $contentElements;
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
        $sqlColsetEmpty = $this->dbColumnExists('tl_content', 'sc_columnset')
            ? 'AND sc_columnset = ""'
            : '';

        $stmt = $this->connection->prepare(<<<SQL
            SELECT COUNT(id)
              FROM tl_content
             WHERE type LIKE "colset%"
               $sqlColsetEmpty
               AND sc_type != ""
             LIMIT 1
        SQL);

        $result = $stmt->executeQuery();

        // if there are colset elements with a sc_type but no sc_columnset,
        // they have to be module content elements
        return (int)$result->fetchOne() > 0;
    }

    /**
     * @see self::checkIfModuleContentElementsExist() but for form fields.
     * @throws DBALException
     */
    protected function checkIfModuleFormFieldsExist(): bool
    {
        $sqlColsetEmpty = $this->dbColumnExists('tl_form_field', 'sc_columnset')
            ? 'AND sc_columnset = ""'
            : '';

        $stmt = $this->connection->prepare(<<<SQL
            SELECT COUNT(id)
              FROM tl_form_field
             WHERE type LIKE "formcol%"
               $sqlColsetEmpty
               AND fsc_type != ""
             LIMIT 1
        SQL);

        $result = $stmt->executeQuery();

        // if there are colset elements with a fsc_type but no sc_columnset,
        // they have to be module form fields
        return (int)$result->fetchOne() > 0;
    }

    /**
     * @throws DBALException|\Throwable
     */
    protected function transformModuleContentElements(MigrationConfig $config): void
    {
        $sqlScColumnsetEmpty = $this->dbColumnExists('tl_content', 'sc_columnset')
            ? 'AND sc_columnset = ""'
            : '';

        $stmt = $this->connection->prepare(<<<SQL
            SELECT id, type, customTpl, sc_childs, sc_parent, sc_type, sc_name
              FROM tl_content
             WHERE type LIKE "colset%"
               $sqlScColumnsetEmpty
        SQL);
        $result = $stmt->executeQuery();

        $contentElements = $this->dbResult2colsetElementDTOs($config, $result);

        $this->transformColsetElements($contentElements);
    }

    /**
     * @throws DBALException|\Throwable
     */
    protected function transformModuleFormFields(MigrationConfig $config): void
    {
        $sqlScColumnsetEmpty = $this->dbColumnExists('tl_form_field', 'sc_columnset')
            ? 'AND sc_columnset = ""'
            : '';

        $stmt = $this->connection->prepare(<<<SQL
            SELECT id, type, customTpl, fsc_childs, fsc_parent, fsc_type, fsc_name
              FROM tl_form_field
             WHERE type LIKE "formcol%"
               $sqlScColumnsetEmpty
        SQL);
        $result = $stmt->executeQuery();

        $formFields = $this->dbResult2colsetElementDTOs($config, $result, [
            'scChildren' => 'fsc_childs',
            'scParent'   => 'fsc_parent',
            'scType'     => 'fsc_type',
            'scName'     => 'fsc_name',
        ], 'tl_form_field');

        $this->transformColsetElements($formFields);
    }

    /**
     * @param array<int, ColsetElementDTO[]> $colsetElements
     * @throws DBALException|\Throwable
     */
    protected function transformColsetElements(array $colsetElements): void
    {
        $this->connection->beginTransaction();

        try {
            foreach ($colsetElements as $parentId => $ceDTOs)
            {
                $this->transformColsetIntoGrid($parentId, $ceDTOs);
            }
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        $this->connection->commit();
    }

    /**
     * @param int $parentId
     * @param ColsetElementDTO[] $ceDTOs
     * @throws DBALException|\DomainException
     */
    protected function transformColsetIntoGrid(int $parentId, array $ceDTOs): void
    {
        $errMsg = " Please check manually and re-run the migration.\n"
            . "(SELECT * FROM tl_content WHERE sc_parent=\"$parentId\" AND type LIKE \"colset%\" OR type LIKE \"formcol%\";)";

        if (empty($ceDTOs) || \count($ceDTOs) < 2) {
            throw new \DomainException("Not enough content elements found for colset to be valid." . $errMsg);
        }

        $identifier = $ceDTOs[0]->getIdentifier();
        $gridId = $this->mapMigratedGlobalSubcolumnIdentifiersToBsGridId[$identifier] ?? null;
        if (!$gridId) {
            throw new \DomainException("No migrated set \"$identifier\" found." . $errMsg);
        }

        $start = null;
        $parts = [];
        $stop = null;

        foreach ($ceDTOs as $ce) {
            switch ($ce->getType()) {
                case self::CE_TYPE_COLSET_START:
                case self::FF_TYPE_FORMCOL_START:
                    if ($ce->getId() !== $parentId) {
                        throw new \DomainException(
                            "Start element's id does not match its sc_parent id ({$ce->getId()} !== $parentId)." . $errMsg
                        );
                    }
                    if ($start !== null) {
                        throw new \DomainException('Multiple start elements found for sub-column set.' . $errMsg);
                    }
                    $start = $ce;
                    break;
                case self::CE_TYPE_COLSET_PART:
                case self::FF_TYPE_FORMCOL_PART:
                    $parts[] = $ce;
                    break;
                case self::CE_TYPE_COLSET_END:
                case self::FF_TYPE_FORMCOL_END:
                    if ($stop !== null) {
                        throw new \DomainException('Multiple stop elements found for sub-column set.' . $errMsg);
                    }
                    $stop = $ce;
                    break;
                default:
                    throw new \DomainException('Invalid content element type found for sub-column set.' . $errMsg);
            }
        }

        if (!$start) throw new \DomainException('No start element found for subcolumn set.' . $errMsg);
        if (!$stop)  throw new \DomainException('No stop element found for subcolumn set.' . $errMsg);

        $part = $parts[0] ?? null;

        $table = $start->getTable();

        /* ============================================== *\
         * Transform the start element into a grid start.
        \* ============================================== */

        $stmt = $this->connection->prepare(<<<SQL
            UPDATE $table
               SET bs_grid_parent = :parentId,
                   bs_grid_name = :name,
                   bs_grid = :gridId,
                   type = :renameType,
                   customTpl = :customTpl
             WHERE id = :id
        SQL);

        $stmt->bindValue('parentId', 0);
        $stmt->bindValue('name', $start->getScName());
        $stmt->bindValue('gridId', $gridId);
        $stmt->bindValue('renameType', self::RENAME_TYPE[$start->getType()]);
        $stmt->bindValue('customTpl', $start->getCustomTpl() ?? '');
        $stmt->bindValue('id', $start->getId());

        $stmt->executeStatement();

        /* ======================================================= *\
         * Transform the child elements into grid columns and end. *
        \* ======================================================= */

        $childIds = \array_filter(\array_map(static function (ColsetElementDTO $row) use ($start) {
            $rowId = $row->getId();
            return $rowId !== $start->getId() ? $rowId : null;
        }, $ceDTOs));

        $placeholders = [];
        foreach ($childIds as $index => $childId)
            // necessary for parameter binding,
            // because DBAL does not allow mixing named and positional parameters
        {
            $placeholders[] = ':child_' . $index;
        }
        $placeholders = \implode(', ', $placeholders);

        $stmt = $this->connection->prepare(<<<SQL
            UPDATE $table
               SET bs_grid_parent = :parentId,
                   type = REPLACE(REPLACE(type, :oPart, :rPart), :oStop, :rStop),
                   customTpl = CASE 
                       WHEN customTpl != '' THEN customTpl
                       WHEN type = :oPart OR type = :rPart THEN :customTplPart
                       WHEN type = :oStop OR type = :rStop THEN :customTplStop
                       ELSE ''
                   END
             WHERE id IN ($placeholders)
               AND (type LIKE "colset%" OR type LIKE "formcol%")
        SQL);

        $stmt->bindValue('parentId', $start->getId());

        $oPart = $part ? ($part->getType() ?? self::CE_TYPE_COLSET_PART) : self::CE_TYPE_COLSET_PART;
        $stmt->bindValue('oPart', $oPart);
        $stmt->bindValue('rPart', self::RENAME_TYPE[$oPart]);
        $stmt->bindValue('customTplPart', $part ? ($part->getCustomTpl() ?? '') : '');

        $stmt->bindValue('oStop', $stop->getType());
        $stmt->bindValue('rStop', self::RENAME_TYPE[$stop->getType()]);
        $stmt->bindValue('customTplStop', $stop->getCustomTpl() ?? '');

        foreach ($childIds as $index => $childId) {
            $stmt->bindValue('child_' . $index, $childId, ParameterType::INTEGER);
        }

        $stmt->executeStatement();
    }

    /**
     * @throws DBALException
     */
    protected function migrateGlobalSubcolumnDefinitions(MigrationConfig $config): array
    {
        $migrated = $config->getMigratedIdentifiers();

        $newlyMigrated = [];

        $this->connection->beginTransaction();
        foreach ($config->getGlobalSubcolumnDefinitions() as $colset)
        {
            if (\in_array($colset->getIdentifier(), $migrated, true))
            {
                continue;
            }

            $id = $this->insertColSetDefinition($config, $colset);

            $colset->setMigratedId($id);
            $this->mapMigratedGlobalSubcolumnIdentifiersToBsGridId[$colset->getIdentifier()] = $id;

            $newlyMigrated[] = $colset->getIdentifier();
        }
        $this->connection->commit();

        return $newlyMigrated;
    }

    /**
     * @throws DBALException
     */
    protected function getMigratedIdentifiers(int $parentThemeId): array
    {
        $stmt = $this->connection
            ->prepare('SELECT id, description FROM tl_bs_grid WHERE pid = ? AND description LIKE "%[sub2col:%"');
        $stmt->bindValue(1, $parentThemeId);
        $migratedResult = $stmt->executeQuery();

        $migrated = [];

        while ($row = $migratedResult->fetchAssociative())
        {
            $identifier = \preg_match('/\[sub2col:([^]]+)]/i', $row['description'], $matches)
                ? $matches[1] ?? null
                : null;
            if (!$identifier) continue;
            $migrated[] = $identifier;
            $this->mapMigratedGlobalSubcolumnIdentifiersToBsGridId[$identifier] = (int) $row['id'];
        }

        return $migrated;
    }

    /**
     * @throws DBALException
     */
    protected function insertColSetDefinition(MigrationConfig $config, ColsetDefinition $colset): int
    {
        $breakpointOrder = \array_flip(self::BREAKPOINTS);

        $sizes = \array_filter($colset->getSizes(), static function ($size) {
            return \in_array($size, self::BREAKPOINTS);
        });

        \uasort($sizes, static function ($a, $b) use ($breakpointOrder) {
            return $breakpointOrder[$a] <=> $breakpointOrder[$b];
        });

        $arrColset = $colset->asArray((int)($config->getGridVersion() === 3));  // grid version 3 indices start at 1

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

        $stmt->bindValue('pid', $config->getParentThemeId());
        $stmt->bindValue('tstamp', \time());
        $stmt->bindValue('title', $colset->getTitle());
        $stmt->bindValue('description', \sprintf('[sub2col:%s]', $colset->getIdentifier()));
        $stmt->bindValue('sizes', \serialize($sizes));
        $stmt->bindValue('rowClass', $colset->getRowClasses() ?? '');
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
        return null;
    }

    /**
     * @return array<ColsetDefinition>
     */
    protected function fetchGlobalSetDefinitions(MigrationConfig $config): array
    {
        if (empty($GLOBALS['TL_SUBCL']) || !\is_array($GLOBALS['TL_SUBCL'])) {
            throw new \OutOfBoundsException('No subcolumns found in $GLOBALS["TL_SUBCL"].');
        }

        $colsetDefinitions = [];

        foreach ($GLOBALS['TL_SUBCL'] as $profileName => $profile)
        {
            if (!\is_array($profile)
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

                if (empty($sizes)) {
                    continue;
                }

                $idSource = $profileName === 'bootstrap' ? 'bootstrap3' : $profileName;
                $identifier = \sprintf('globals.%s.%s', $idSource, $setName);
                $maxColCount = \max(\array_map('count', $sizes) ?: [0]);
                $rowClasses = "colcount_$maxColCount $idSource col-$setName sc-type-$setName";

                $colset = ColsetDefinition::create()
                    ->setIdentifier($identifier)
                    ->setTitle("$label: $setName [global]")
                    ->setPublished(true)
                    ->setSizeDefinitions($sizes)
                    ->setRowClasses($rowClasses)
                    ->setInsideClass($inside ? 'inside' : null)
                    ->setUseInside((bool) $inside)
                ;

                if ($colset->getRowClasses() !== $rowClasses) {
                    $config->addNote(
                        "Row classes truncated for \"$identifier\" due to length limitations.\n"
                        . "Should be: \"$rowClasses\"\n"
                        . "   Is now: \"" . $colset->getRowClasses() . "\""
                    );
                }

                $colsetDefinitions[$identifier] = $colset;
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
            $colClasses = $this->createClassNames($classNames, $customClasses);

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

        // make sure that all breakpoints have the same amount of columns

        $colCount = \max(\array_map('count', $sizes) ?: [0]);

        foreach ($sizes as $breakpoint => $cols)
        {
            if (\count($cols) < $colCount)
            {
                for ($i = 0; $i < $colCount; $i++)
                {
                    $cols[$i] ??= ColumnDefinition::create();
                }
                $sizes[$breakpoint] = $cols;
            }
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

    protected function createClassNames(array $classNames, array &$customClasses = []): array
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

    //<editor-fold desc="Tamplate handling">

    /**
     * Copies the templates from this bundle's contao/templates to the project directory.
     *
     * @param array<ColsetDefinition> $colSets
     * @return array The prepared files.
     */
    protected function prepareTemplates(array $colSets): array
    {
        $source = $this->getBundlePath() . '/contao/templates';
        $target = $this->parameterBag->get('kernel.project_dir') . '/contao/templates/elements';

        if (!is_dir($target))
        {
            mkdir($target, 0777, true);
        }

        $insideClasses = [];

        foreach ($colSets as $colSet)
        {
            if ($colSet->getInsideClass())
            {
                $insideClasses[] = $colSet->getInsideClass();
            }
        }

        $copied = [];
        foreach (\array_unique($insideClasses) as $insideClass)
        {
            $copied = \array_merge($copied, $this->copyTemplates($source, $target, 'inner', ['{insideClass}' => $insideClass]));
        }

        return $copied;
    }

    /**
     * @param string $source The source directory.
     * @param string $target The target directory.
     * @param string $suffix The suffix included in the file name.
     * @param array $replace The keys to replace in the template content and source file name.
     * @return array The copied files.
     */
    protected function copyTemplates(string $source, string $target, string $suffix = '', array $replace = []): array
    {
        if (!\is_dir($source)) {
            throw new \RuntimeException('Template source directory not found. Please reinstall the bundle.');
        }

        if (!\is_dir($target)) {
            throw new \RuntimeException('Template target directory not found.');
        }

        if ($suffix && \substr($suffix, 0, 1) !== '_') {
            $suffix = '_' . $suffix;
        }

        $rx = "/ce_bs_gridS(tart|eparator|top)$suffix(_[a-zA-Z0-9{}_]+)/";

        $search = \array_keys($replace);
        $replace = \array_values($replace);

        $copied = [];

        foreach (\scandir($source) as $file)
        {
            if ($file === '.' || $file === '..') continue;
            if (!\preg_match($rx, $file)) continue;

            $destination = $target . \DIRECTORY_SEPARATOR . \str_replace($search, $replace, $file);
            if (\file_exists($destination)) continue;

            $sourceFile = $source . \DIRECTORY_SEPARATOR . $file;

            if ($this->copyTemplateFile($sourceFile, $destination, $file, $search, $replace) === false)
            {
                throw new \RuntimeException('Could not copy template file.');
            }

            $copied[] = $destination;
        }

        return $copied;
    }

    /**
     * @param string $source The source file path.
     * @param string $target The target file path.
     * @param string $cacheKey The cache key for the template content.
     * @param array $search The keys to replace in the template content.
     * @param array $replace The values to replace the keys with.
     * @return false|int The number of bytes written to the file, or false on failure.
     */
    protected function copyTemplateFile(
        string $source,
        string $target,
        string $cacheKey,
        array  $search,
        array  $replace
    ) {
        if (\in_array($cacheKey, \array_keys($this->templateCache), true))
        {
            $content = $this->templateCache[$cacheKey];
        }
        else
        {
            $content = \file_get_contents($source);
            $this->templateCache[$cacheKey] = $content;
        }

        return \file_put_contents($target, \str_replace($search, $replace, $content));
    }

    protected function getColumnTemplateFromIdentifier(
        MigrationConfig $config,
        string          $identifier,
        string          $ceType
    ): ?string {
        $def = $config->getSubcolumnDefinition($identifier);
        if (!$def) {
            throw new \DomainException("No sub-column definition found for identifier \"$identifier\".");
        }

        $insideClass = $def->getInsideClass();
        if (!$def->getUseInside() || !$insideClass) {
            return null;
        }

        $type = self::RENAME_TYPE[$ceType];

        return "ce_{$type}_inner_$insideClass";
    }

    //</editor-fold>

    /**
     * @throws DBALException
     */
    protected function autoConfig(InputInterface $input, MigrationConfig $config): void
    {
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
                //
                // break;
        }

        $profile = $this->smartGetSubcolumnProfile($input);
        $config->setProfile($profile);
    }

    //<editor-fold desc="Options">

    /**
     * @throws DBALException
     */
    protected function smartGetFrom(InputInterface $input): ?int
    {
        return $this->getOptionFrom($input) ?? $this->autoDetectFrom();
    }

    protected function smartGetSubcolumnProfile(InputInterface $input): ?string
    {
        return $this->getOptionProfile($input) ?? Config::get('subcolumns');
    }

    protected function getOptionFrom(InputInterface $input): ?int
    {
        $from = ($from = $input->getOption('from')) ? \ltrim($from, ' :=') : null;

        switch ($from) {
            case null: return null;
            case 'm': return MigrationConfig::FROM_SUBCOLUMNS_MODULE;
            case 'b': return MigrationConfig::FROM_SUBCOLUMNS_BOOTSTRAP_BUNDLE;
            default: throw new \InvalidArgumentException('Invalid source.');
        }
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

        return null;
    }

    //</editor-fold>

    //<editor-fold desc="Grid version configuration">

    /**
     * @throws \Exception
     */
    protected static function initGridVersion(InputInterface $input, SymfonyStyle $io): int
    {
        $gridVersion = $input->getOption('grid-version');
        if ($gridVersion !== null && \in_array($gridVersion = (int) \ltrim($gridVersion, ' :='), [2, 3])) {
            return $gridVersion;
        }

        if (!$gridVersion) {
            $version = ContaoCoreBundle::getVersion();
            $phpVers = \phpversion();
            $isContao5 = \version_compare($version, '5', '>=');
            if ($isContao5) {
                $io->block([
                    "Detected Contao version $version on PHP $phpVers.",
                    "Necessarily migrating to contao-bootstrap/grid version 3."
                ]);
                $gridVersion = 3;
            } else {
                $io->text("Detected Contao version $version on PHP $phpVers.");
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
        return (int) $id;  // (int) "new" === 0
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

    protected function getBundlePath(): string
    {
        return $this->kernel->getBundle('HeimrichHannotSubcolumns2GridMigrationBundle')->getPath();
    }
}