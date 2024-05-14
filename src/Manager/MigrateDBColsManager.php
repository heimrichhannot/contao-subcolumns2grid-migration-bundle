<?php

namespace HeimrichHannot\Subcolumns2Grid\Manager;

use Contao\StringUtil;
use Doctrine\DBAL\Exception as DBALException;
use HeimrichHannot\Subcolumns2Grid\Config\BreakpointDTO;
use HeimrichHannot\Subcolumns2Grid\Config\ColsetDefinition;
use HeimrichHannot\Subcolumns2Grid\Config\ColumnDefinition;
use HeimrichHannot\Subcolumns2Grid\Config\MigrationConfig;
use HeimrichHannot\Subcolumns2Grid\Util\Constants;
use HeimrichHannot\Subcolumns2Grid\Util\Helper;
use Symfony\Component\Console\Style\SymfonyStyle;

class MigrateDBColsManager extends AbstractManager
{
    public function migrate(SymfonyStyle $io, MigrationConfig $config): void
    {
        $io->text('Fetching columnset definitions from database...');
        $columnSets = $this->fetchSetDefinitions($config);

        if (empty($columnSets)) {
            $io->caution('Skipping migration of database column-set definitions, as none were found.');
            return;
        }

        $config->setDBSubcolumnDefinitions($columnSets);
        $io->listing(\array_map(static function (ColsetDefinition $colset) {
            return $colset->getIdentifier();
        }, $columnSets));
        $io->info(\sprintf('Evaluated %s database defined column-sets.', \count($columnSets)));

        $io->text('Preparing templates for missing outer containers and inner wrappers...');
        $copiedTemplates = $this->templateManager()->prepareTemplates($columnSets);

        if (empty($copiedTemplates)) {
            $io->info('No templates had to be copied.');
        } else {
            $io->listing($copiedTemplates);
            $io->success('Copied templates successfully.');
        }

        $io->text('Migrating database column-set definitions...');
        $newlyMigratedIdentifiers = $this->migrateSubcolumnDefinitions($config);

        if (empty($newlyMigratedIdentifiers))
        {
            $io->info('No database defined column-sets had to be migrated anew.');
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
    }

    protected function fetchSetDefinitions(MigrationConfig $config): array
    {
        $stmt = $this->connection->prepare('SELECT * FROM tl_columnset');
        $result = $stmt->executeQuery();

        $profile = $config->getProfile();

        $columnSets = [];
        foreach ($result->iterateAssociative() as $row)
        {
            $identifier = 'db.tl_columnset.' . $row['id'];

            $breakpoints = $this->createBreakpointsFromRow($row);
            // note: having an empty set of breakpoints is valid for database column-sets

            $idSource = $profile === 'bootstrap' ? 'bootstrap3' : $profile;
            $maxColCount = $row['columns'];
            $rowClasses = "colcount_$maxColCount $idSource"; // col-$maxColCount? sc-type-$setName?

            $colset = ColsetDefinition::create()
                ->setIdentifier($identifier)
                ->setTitle($row['title'] . ' [db]')
                ->setPublished((bool) $row['published'])
                ->setBreakpoints($breakpoints)
                ->setRowClasses($rowClasses)
                ->setUseInside((bool) $row['useInside'])
                ->setInsideClass($row['insideClass'] ?? '')
                ->setUseOutside((bool) $row['useOutside'])
                ->setOutsideClass($row['outsideClass'] ?? '')
                ->setColumnsetRow($row)
            ;
            $columnSets[$identifier] = $colset;

            if ($colset->getRowClasses() !== $rowClasses) {
                $config->addNote(
                    "Row classes truncated for \"$identifier\" due to length limitations.\n"
                    . "Should be: \"$rowClasses\"\n"
                    . "   Is now: \"" . $colset->getRowClasses() . "\""
                );
            }
        }

        return $columnSets;
    }

    /**
     * @param array{
     *     id: int,
     *     pid: int,
     *     tstamp: int,
     *     title: string,
     *     description: string,
     *     columns: int,
     *     useOutside: bool,
     *     outsideClass: string,
     *     useInside: bool,
     *     insideClass: string,
     *     sizes: array,
     *     published: bool,
     *     cssID: string,
     *     columnset_xs: string,
     *     columnset_sm: string,
     *     columnset_md: string,
     *     columnset_lg: string,
     *     columnset_xl: string,
     *     columnset_xxl: string
     * } $row
     * @return array
     */
    protected function createBreakpointsFromRow(array $row): array
    {
        /** @var array<string, BreakpointDTO> $breakpoints */
        $breakpoints = [];

        $sizesColumns = \array_flip(Constants::BREAKPOINTS);

        \array_walk($sizesColumns, static function (&$v, $k) use ($row) {
            $v = StringUtil::deserialize($row['columnset_' . $k] ?? null) ?: null;
        }); // => $sizesColumns = ['xs' => $item['columnset_xs'], 'sm' => $item['columnset_sm'], ...]

        foreach ($sizesColumns as $strBreakpoint => $columns)
        {
            $dto = ($breakpoints[$strBreakpoint] ??= new BreakpointDTO($strBreakpoint));

            if (!\is_array($columns)) continue;

            foreach ($columns as $colIndex => $column)
            {
                $dto->set(
                    $colIndex,
                    ColumnDefinition::create()
                        ->setSpan($column['width'] ?? "")
                        ->setOffset($column['offset'] ?? "")
                        ->setOrder($column['order'] ?? "")
                );
            }
        }

        foreach ($breakpoints as $strBreakpoint => $dto)
        {
            if (!$dto->count())
            {
                unset($breakpoints[$strBreakpoint]);
            }
        }

        // make sure that all breakpoints have the same amount of columns

        // $colCount = (int) $row['columns'];
        //
        // foreach ($breakpoints as $strBreakpoint => $dto)
        // {
        //     if (\count($dto) >= $colCount) continue;
        //
        //     for ($i = 0; $i < $colCount; $i++)
        //     {
        //         if (!$dto->has($i))
        //         {
        //             $dto->set($i, ColumnDefinition::create());
        //         }
        //     }
        // }

        return $breakpoints;
    }

    /**
     * @throws DBALException
     * @throws \Random\RandomException
     */
    protected function migrateSubcolumnDefinitions(MigrationConfig $config): array
    {
        $migrated = $config->getMigratedIdentifiers();

        $newlyMigrated = [];

        $this->connection->createSavepoint($uid = Helper::savepointId());
        foreach ($config->getDBSubcolumnDefinitions() as $colset)
        {
            if (\in_array($colset->getIdentifier(), $migrated, true))
            {
                continue;
            }

            $id = $this->migrationManager()->insertColSetDefinition($config, $colset);

            $colset->setMigratedId($id);
            $this->migrationManager()->mapIdentifierToGridId($colset->getIdentifier(), $id);

            $newlyMigrated[] = $colset->getIdentifier();
        }
        $this->connection->releaseSavepoint($uid);

        return $newlyMigrated;
    }
}