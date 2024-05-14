<?php

namespace HeimrichHannot\Subcolumns2Grid\Manager;

use Doctrine\DBAL\Driver\Exception as DBALDriverException;
use Doctrine\DBAL\Exception as DBALException;
use HeimrichHannot\Subcolumns2Grid\Config\ColsetDefinition;
use HeimrichHannot\Subcolumns2Grid\Config\CommandConfig;
use HeimrichHannot\Subcolumns2Grid\Config\MigrationConfig;
use HeimrichHannot\Subcolumns2Grid\Util\Constants;
use Symfony\Component\Console\Style\SymfonyStyle;

class MigrationManager extends AbstractManager
{
    protected array $mapMigratedGlobalSubcolumnIdentifiersToBsGridId = [];
    protected array $mapMigratedDbSubcolumnIdentifiersToBsGridId = [];

    public function getGridIdFromMigratedIdentifier(string $identifier): ?int
    {
        if (\substr($identifier, 0, 3) === 'db.') {
            return $this->mapMigratedDbSubcolumnIdentifiersToBsGridId[$identifier] ?? null;
        }
        return $this->mapMigratedGlobalSubcolumnIdentifiersToBsGridId[$identifier] ?? null;
    }

    public function mapIdentifierToGridId($identifier, $gid): void
    {
        if (\substr($identifier, 0, 3) === 'db.') {
            $this->mapMigratedDbSubcolumnIdentifiersToBsGridId[$identifier] = $gid;
        } else {
            $this->mapMigratedGlobalSubcolumnIdentifiersToBsGridId[$identifier] = $gid;
        }
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

                $this->globalsManager()->migrate($io, $config);

                $this->alchemist()->transformModule($io, $config);
            }
        }

        if ($config->hasSource(MigrationConfig::SOURCE_DB))
        {
            if (!$cmd->skipConfirmations() &&
                !$io->confirm("Migrate database defined column-sets now?"))
            {
                $io->info("Skipping migration of database defined column-sets.");
            }
            else
            {
                $io->info('Migrating database defined column-sets.');

                $this->dbManager()->migrate($io, $config);

                // todo: alchemist!
            }
        }

        return true;
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
    public function insertColSetDefinition(MigrationConfig $config, ColsetDefinition $colset): int
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
}