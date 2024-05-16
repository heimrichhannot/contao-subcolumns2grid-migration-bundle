<?php

namespace HeimrichHannot\Subcolumns2Grid\Manager;

use Doctrine\DBAL\Driver\Exception as DBALDriverException;
use Doctrine\DBAL\Exception as DBALException;
use HeimrichHannot\Subcolumns2Grid\Config\ColsetDefinition;
use HeimrichHannot\Subcolumns2Grid\Config\MigrationConfig;
use Symfony\Component\Console\Style\SymfonyStyle;

abstract class AbstractMigrationManager extends AbstractManager
{
    public const LANG_SUBJECT = 'abstract definitions';
    public const LANG_FETCHING_DEFINITIONS = 'Fetching definitions...';

    public function getSubject(): string
    {
        return $this::LANG_SUBJECT;
    }

    /**
     * @param MigrationConfig $config
     * @return ColsetDefinition[]
     */
    protected abstract function getMigrationColumnSets(MigrationConfig $config): array;

    protected abstract function setMigrationColumnSets(MigrationConfig $config, array $columnSets): void;

    protected abstract function fetchSetDefinitions(MigrationConfig $config): array;

    public final function migrate(SymfonyStyle $io, MigrationConfig $config): void
    {
        $io->section("Migrating {$this->getSubject()}");

        if (!$io->confirm("Migrate {$this->getSubject()} now?"))
        {
            $io->info("Skipping migration of {$this->getSubject()}.");
            return;
        }

        $io->text($this::LANG_FETCHING_DEFINITIONS);
        $columnSets = $this->fetchSetDefinitions($config);

        if (empty($columnSets)) {
            $io->caution("Skipping migration of {$this->getSubject()}, as none were found.");
            return;
        }

        $this->setMigrationColumnSets($config, $columnSets);

        $io->listing(\array_map(static function (ColsetDefinition $colset) {
            return $colset->getIdentifier();
        }, $columnSets));
        $io->info(\sprintf('Evaluated %s %s.', \count($columnSets), $this::LANG_SUBJECT));

        $io->text('Preparing templates...');
        $copiedTemplates = $this->templateManager()->prepareTemplates($columnSets);

        if (empty($copiedTemplates)) {
            $io->info('No templates had to be copied.');
        } else {
            $io->listing($copiedTemplates);
            $io->success('Copied templates successfully.');
        }

        $io->text("Migrating {$this->getSubject()}...");

        $newlyMigratedIdentifiers = $this->migrateSubcolumnDefinitions($config);

        if (empty($newlyMigratedIdentifiers))
        {
            $io->info("No {$this->getSubject()} had to be migrated anew.");
        }
        else
        {
            $config->addMigratedIdentifiers(...$newlyMigratedIdentifiers);

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
            $io->success("Migrated {$this->getSubject()} successfully.");
        }
    }

    /**
     * @throws DBALException|DBALDriverException
     * @throws \Exception|\Random\RandomException
     */
    protected final function migrateSubcolumnDefinitions(MigrationConfig $config): array
    {
        $migrated = $config->getMigratedIdentifiers();

        $newlyMigrated = [];

        // $this->connection->createSavepoint($uid = Helper::savepointId());
        foreach ($this->getMigrationColumnSets($config) as $colset)
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
        // $this->connection->releaseSavepoint($uid);

        return $newlyMigrated;
    }
}