<?php

namespace HeimrichHannot\Subcolumns2Grid\Manager;

use Doctrine\DBAL\DBALException as DBALDBALException;
use Doctrine\DBAL\Driver\Exception as DBALDriverException;
use Doctrine\DBAL\Exception as DBALException;
use HeimrichHannot\Subcolumns2Grid\Config\ColsetDefinition;
use HeimrichHannot\Subcolumns2Grid\Config\MigrationConfig;
use HeimrichHannot\Subcolumns2Grid\Exception\MigrationException;
use HeimrichHannot\Subcolumns2Grid\Util\Constants;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

class MigrationManager extends AbstractManager
{
    protected array $mapMigratedIdentifiersToBsGridId = [];

    public function getGridIdFromMigratedIdentifier(string $identifier): ?int
    {
        return $this->mapMigratedIdentifiersToBsGridId[$identifier] ?? null;
    }

    public function mapIdentifierToGridId(string $identifier, int $gid): void
    {
        $this->mapMigratedIdentifiersToBsGridId[$identifier] = $gid;
    }

    /**
     * @throws Throwable
     * @throws DBALException
     */
    public function migrate(SymfonyStyle $io, MigrationConfig $config): bool
    {
        $io->title('Migrating sub-columns to grid columns');

        $io->note(
            'This will migrate existing sub-columns to grid columns. '
            . 'Please make sure to backup your database before running this command.'
        );
        if (!$io->confirm('Proceed with the migration?')) {
            return true;
        }

        if ($config->hasSource(MigrationConfig::SOURCE_GLOBALS))
        {
            $this->globalsManager()->migrate($io, $config);
            $this->moduleAlchemist()->transform($io, $config);
        }

        if ($config->hasSource(MigrationConfig::SOURCE_DB))
        {
            $this->dbManager()->migrate($io, $config);
            $this->bundleAlchemist()->transform($io, $config);
        }

        return true;
    }

    /**
     * @throws DBALDriverException|DBALDBALException|DBALException
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
            $this->mapIdentifierToGridId($identifier, (int) $row['id']);
        }

        return $migrated;
    }

    /**
     * @throws DBALDriverException|DBALDBALException|DBALException
     * @throws MigrationException
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

        $lastId = $this->connection->lastInsertId();

        if (!$lastId) {
            throw new MigrationException('Could not insert colset definition.');
        }

        return (int) $lastId;
    }
}