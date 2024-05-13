<?php

namespace HeimrichHannot\Subcolumns2Grid\Manager;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DBALDriverException;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Result;
use HeimrichHannot\Subcolumns2Grid\Config\BreakpointDTO;
use HeimrichHannot\Subcolumns2Grid\Config\ClassName;
use HeimrichHannot\Subcolumns2Grid\Config\ColsetDefinition;
use HeimrichHannot\Subcolumns2Grid\Config\ColsetElementDTO;
use HeimrichHannot\Subcolumns2Grid\Config\ColumnDefinition;
use HeimrichHannot\Subcolumns2Grid\Config\CommandConfig;
use HeimrichHannot\Subcolumns2Grid\Config\MigrationConfig;
use HeimrichHannot\Subcolumns2Grid\Util\Constants;
use HeimrichHannot\Subcolumns2Grid\Util\Helper;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpKernel\KernelInterface;

class MigrationManager
{
    protected Connection $connection;
    protected KernelInterface $kernel;
    protected ParameterBagInterface $parameterBag;
    protected TemplateManager $templateManager;

    protected array $mapMigratedGlobalSubcolumnIdentifiersToBsGridId = [];

    public function __construct(
        Connection            $connection,
        KernelInterface       $kernel,
        ParameterBagInterface $parameterBag,
        TemplateManager       $templateManager
    ) {
        $this->connection = $connection;
        $this->kernel = $kernel;
        $this->parameterBag = $parameterBag;
        $this->templateManager = $templateManager;
    }

    protected function dbColumnExists(string $table, string $column): bool
    {
        return Helper::dbColumnExists($this->connection, $table, $column);
    }

    /**
     * @throws \Throwable
     * @throws DBALException
     */
    public function migrate(CommandConfig $cmd, MigrationConfig $config, SymfonyStyle $io): bool
    {
        $io->title('Migrating sub-columns to grid columns');

        $io->note('This will migrate existing sub-columns to grid columns. Please make sure to backup your database before running this command.');
        if (!$cmd->skipConfirmations() && !$io->confirm('Proceed with the migration?')) {
            return true;
        }

        if ($config->hasSource(MigrationConfig::SOURCE_GLOBALS))
        {
            if (!$cmd->skipConfirmations() &&
                !$io->confirm("Migrate globally defined sub-column profiles now?"))
            {
                $io->info("Skipping migration of globally defined sub-column sets.");
            }
            else
            {
                $io->section('Migrating globally defined sub-column sets.');

                try
                {
                    $this->connection->beginTransaction();

                    $this->migrateGlobal($io, $config);

                    $cmd->isDryRun()
                        ? $this->connection->rollBack()
                        : $this->connection->commit();
                }
                catch (\Throwable $e)
                {
                    $this->connection->rollBack();
                    throw $e;
                }
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

        return true;
    }

    /**
     * @throws \Throwable
     * @throws DBALException
     */
    protected function migrateGlobal(SymfonyStyle $io, MigrationConfig $config): void
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

        $io->text('Preparing templates for missing inner wrappers.');
        $copiedTemplates = $this->templateManager->prepareTemplates($globalSubcolumns);

        if (empty($copiedTemplates)) {
            $io->info('No templates had to be copied.');
        } else {
            $io->listing($copiedTemplates);
            $io->success('Copied templates successfully.');
        }

        $io->text('Migrating global sub-column definitions.');
        $newlyMigratedIdentifiers = $this->migrateGlobalSubcolumnDefinitions($config);

        if (empty($newlyMigratedIdentifiers))
        {
            $io->info('No globally defined sub-column sets had to be migrated anew.');
        }
        else
        {
            $io->createTable()
                ->setHeaders(['Identifier', 'Title', 'Breakpoints', 'Row classes'])
                ->setRows(\array_map(static function ($identifier) use ($config) {
                    $def = $config->getSubcolumnDefinition($identifier);
                    return [
                        $identifier,
                        $def->getTitle() ?? '',
                        \implode(', ', \array_keys($def->getBreakpoints())),
                        $def->getRowClasses() ?? '',
                    ];
                }, $newlyMigratedIdentifiers))
                ->render();

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
     * Check if there are module content elements in the database.
     *
     * Module content elements can be differentiated from subcolumn-bootstrap-bundle content elements,
     *   because the latter have a sc_columnset field that is not empty.
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
     * @throws \Throwable
     */
    protected function transformModuleContentElements(MigrationConfig $config): void
    {
        $sqlScColumnsetEmpty = $this->dbColumnExists('tl_content', 'sc_columnset')
            ? 'AND sc_columnset = ""'
            : '';

        $stmt = $this->connection->prepare(<<<SQL
            SELECT id, type, customTpl, sc_childs, sc_parent, sc_type, sc_name, sc_sortid
              FROM tl_content
             WHERE type LIKE "colset%"
             ORDER BY sc_parent, sc_sortid
               $sqlScColumnsetEmpty
        SQL);
        $result = $stmt->executeQuery();

        $contentElements = $this->dbResult2colsetElementDTOs($config, $result);

        $this->transformColsetElements($contentElements);
    }

    /**
     * @return array<int, ColsetElementDTO[]> A map of parent IDs to their respective colset element data transfer
     *   objects, that may either represent content elements or form fields.
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

            $contentElements[$ce->getScParent()][] = $ce;
        }

        foreach ($contentElements as $scParentId => $ces)
        {
            foreach ($ces as $ce)
            {
                if (!$ce->getCustomTpl())
                {
                    $customTpl = $this->findColumnTemplate($config, $ce);
                    $ce->setCustomTpl($customTpl ?? '');
                }
            }
        }

        return $contentElements;
    }

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
            'scOrder'    => 'fsc_sortid',
        ], 'tl_form_field');

        $this->transformColsetElements($formFields);
    }

    /**
     * @param array<int, ColsetElementDTO[]> $colsetElements
     * @throws DBALException|\Throwable
     */
    protected function transformColsetElements(array $colsetElements): void
    {
        $this->connection->createSavepoint($uid = Helper::savepointId());

        foreach ($colsetElements as $parentId => $ceDTOs)
        {
            $this->transformColsetIntoGrid($parentId, $ceDTOs);
        }

        $this->connection->releaseSavepoint($uid);
    }

    /**
     * @param int $parentId
     * @param ColsetElementDTO[] $ceDTOs
     * @throws \DomainException
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
                case Constants::CE_TYPE_COLSET_START:
                case Constants::FF_TYPE_FORMCOL_START:
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
                case Constants::CE_TYPE_COLSET_PART:
                case Constants::FF_TYPE_FORMCOL_PART:
                    $parts[] = $ce;
                    break;
                case Constants::CE_TYPE_COLSET_END:
                case Constants::FF_TYPE_FORMCOL_END:
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
         * Transform the start element into a grid start. *
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
        $stmt->bindValue('renameType', Constants::RENAME_TYPE[$start->getType()]);
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

        $oPart = $part ? ($part->getType() ?? Constants::CE_TYPE_COLSET_PART) : Constants::CE_TYPE_COLSET_PART;
        $stmt->bindValue('oPart', $oPart);
        $stmt->bindValue('rPart', Constants::RENAME_TYPE[$oPart]);
        $stmt->bindValue('customTplPart', $part ? ($part->getCustomTpl() ?? '') : '');

        $stmt->bindValue('oStop', $stop->getType());
        $stmt->bindValue('rStop', Constants::RENAME_TYPE[$stop->getType()]);
        $stmt->bindValue('customTplStop', $stop->getCustomTpl() ?? '');

        foreach ($childIds as $index => $childId) {
            $stmt->bindValue('child_' . $index, $childId, ParameterType::INTEGER);
        }

        $stmt->executeStatement();
    }

    /**
     * @throws DBALException
     * @throws \Random\RandomException
     */
    protected function migrateGlobalSubcolumnDefinitions(MigrationConfig $config): array
    {
        $migrated = $config->getMigratedIdentifiers();

        $newlyMigrated = [];

        $this->connection->createSavepoint($uid = Helper::savepointId());
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
        $this->connection->releaseSavepoint($uid);

        return $newlyMigrated;
    }

    /**
     * @throws DBALDriverException|DBALException
     */
    public function getMigratedIdentifiers(int $parentThemeId): array
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
        $breakpointOrder = \array_flip(Constants::BREAKPOINTS);

        $sizes = \array_filter($colset->getSizes(), static function ($size) {
            return \in_array($size, Constants::BREAKPOINTS);
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
                $breakpoints = $this->createBreakpointsFromArray($globalColsConfig);

                if (empty($breakpoints)) {
                    continue;
                }

                $idSource = $profileName === 'bootstrap' ? 'bootstrap3' : $profileName;
                $identifier = \sprintf('globals.%s.%s', $idSource, $setName);
                $maxColCount = \max(\array_map('count', $breakpoints) ?: [0]);
                $rowClasses = "colcount_$maxColCount $idSource col-$setName sc-type-$setName";

                $colset = ColsetDefinition::create()
                    ->setIdentifier($identifier)
                    ->setTitle("$label: $setName [global]")
                    ->setPublished(true)
                    ->setBreakpoints($breakpoints)
                    ->setRowClasses($rowClasses)
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
     * @return array<string, BreakpointDTO>
     */
    protected function createBreakpointsFromArray(array $colsConfig): array
    {
        /** @var array<string, BreakpointDTO> $breakpoints */
        $breakpoints = [];

        $colIndex = 0;
        foreach ($colsConfig as $singleCol)
        {
            if (\count($singleCol) < 1) {
                continue;
            }

            $classNames = \explode(' ', \preg_replace('/\s+/i', ' ', $singleCol[0]));
            $colClasses = ClassName::list($classNames, $customClasses);
            $insideClass = $singleCol[1] ?? 'inside';

            foreach ($colClasses as $objColClass)
            {
                $strBreakpoint = $objColClass->breakpoint ?: Constants::UNSPECIFIC_PLACEHOLDER;

                $dto = ($breakpoints[$strBreakpoint] ??= new BreakpointDTO($strBreakpoint));

                if (!$dto->has($colIndex))
                {
                    $dto->set(
                        $colIndex,
                        ColumnDefinition::create()
                            ->setCustomClasses(\implode(' ', $customClasses))
                            ->setInsideClass($insideClass)
                    );
                }

                $col = $dto->get($colIndex);

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

        $colCount = \max(\array_map('count', $breakpoints) ?: [0]);

        foreach ($breakpoints as $strBreakpoint => $dto)
        {
            if (\count($dto) >= $colCount)
            {
                continue;
            }

            for ($i = 0; $i < $colCount; $i++)
            {
                if (!$dto->has($i))
                {
                    $dto->set($i, ColumnDefinition::create());
                }
            }
        }

        // replace unspecific placeholders with the smallest breakpoint available or update missing data

        $this->applyUnspecificSizes($breakpoints);

        return $breakpoints;
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
     * @param array<string, BreakpointDTO> $breakpointDTOs
     */
    protected function applyUnspecificSizes(array &$breakpointDTOs): void
    {
        $unspecificBreakpointDTO = $breakpointDTOs[Constants::UNSPECIFIC_PLACEHOLDER] ?? null;
        unset($breakpointDTOs[Constants::UNSPECIFIC_PLACEHOLDER]);

        if (empty($unspecificBreakpointDTO)) {
            return;
        }

        $strSmallestBreakpoint = Constants::BREAKPOINTS[0];

        $specificBreakpointDTO = $breakpointDTOs[$strSmallestBreakpoint] ?? null;

        $breakpointDTOs[$strSmallestBreakpoint] = $unspecificBreakpointDTO;
        $unspecificBreakpointDTO->setBreakpoint($strSmallestBreakpoint);

        if ($specificBreakpointDTO === null)
            // the smallest available breakpoint has not yet been set,
            // therefore we just keep the unspecific classes assigned to the smallest breakpoint (xs)
        {
            return;
        }

        // otherwise, the smallest breakpoint is already defined with css classes incorporating specific sizes,
        // therefore we need to apply the unspecific size as a fallback to the smallest breakpoint


        foreach ($specificBreakpointDTO->getColumns() as $colIndex => $specificColDef)
        {
            $unspecificColDef = $breakpointDTOs[$strSmallestBreakpoint]->get($colIndex);

            if (null === $unspecificColDef) {
                $breakpointDTOs[$strSmallestBreakpoint]->set($colIndex, $specificColDef);
                continue;
            }

            if ($span = $specificColDef->getSpan())     $unspecificColDef->setSpan($span);
            if ($offset = $specificColDef->getOffset()) $unspecificColDef->setOffset($offset);
            if ($order = $specificColDef->getOrder())   $unspecificColDef->setOrder($order);
        }
    }

    protected function fetchDatabaseSubcolumns(): void
    {
        if (empty($GLOBALS['TL_DCA']['tl_content']['fields']['sc_columnset'])) {
            throw new \DomainException('No sc_columnset field found in tl_content.');
        }
    }
}