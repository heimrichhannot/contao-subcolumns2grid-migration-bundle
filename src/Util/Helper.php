<?php

namespace HeimrichHannot\Subcolumns2Grid\Util;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DBALException as DBALDBALException;
use Doctrine\DBAL\Driver\Exception as DBALDriverException;
use Doctrine\DBAL\Exception as DBALException;
use Exception;
use HeimrichHannot\Subcolumns2Grid\Exception\ConfigException;
use Throwable;

class Helper
{
    const TEST_TL_CONTENT = ['tl_content' => 'type'];
    const TEST_TL_FORM_FIELD = ['tl_form_field' => 'type'];
    const TEST_TL_BS_GRID = ['tl_bs_grid' => 'title'];

    static protected array $dbColumnsCache = [];

    protected Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @throws Exception
     */
    public static function GUIDv4($data = null): string
    {
        // Generate 16 bytes (128 bits) of random data or use the data passed into the function.
        try {
            $data = $data ?? \random_bytes(16);
            \assert(\strlen($data) == 16);
        } catch (Exception $e) {
            throw new Exception('Unable to generate a GUIDv4 string.');
        }

        // Set version to 0100
        $data[6] = \chr(\ord($data[6]) & 0x0f | 0x40);
        // Set bits 6-7 to 10
        $data[8] = \chr(\ord($data[8]) & 0x3f | 0x80);

        // Output the 36 character UUID.
        return \vsprintf('%s%s-%s-%s-%s-%s%s%s', \str_split(\bin2hex($data), 4));
    }

    /**
     * @throws Exception
     */
    public static function savepointId(): string
    {
        return 'savepoint_' . \str_replace('-', '', self::GUIDv4());
    }

    /**
     * @throws DBALDriverException|DBALDBALException|DBALException
     */
    public function dbColumnExists(string $table, string $column): bool
    {
        $cacheKey = $this->connection->getDatabase() . "." . $table . "." . $column;

        if (isset(self::$dbColumnsCache[$cacheKey])) {
            return self::$dbColumnsCache[$cacheKey];
        }

        $stmt = $this->connection->prepare(<<<SQL
            SELECT COUNT(*)
              FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table
               AND COLUMN_NAME = :column
             LIMIT 1
        SQL);

        $stmt->bindValue('table', $table);
        $stmt->bindValue('column', $column);

        $result = $stmt->executeQuery();

        return self::$dbColumnsCache[$cacheKey] = (int)$result->fetchOne() > 0;
    }

    /**
     * @param mixed $payload
     * @throws Throwable
     */
    public function testTransactionAbility(string $table, string $column, $payload = 's2g_transaction_test'): bool
    {
        $dbPlattform = @$this->connection->getDatabasePlatform() ?? null;

        if ($dbPlattform
            && \method_exists($dbPlattform, 'supportsTransactions')
            && !$dbPlattform->supportsTransactions())
        {
            return false;
        }

        try
        {
            $this->connection->beginTransaction();
            $this->connection->executeStatement("INSERT INTO `$table` (`$column`) VALUES (\"$payload\")");
            $this->connection->rollBack();

            return 0 === $this->connection
                    ->executeQuery("SELECT COUNT(*) FROM `$table` WHERE `$column`=\"$payload\"")
                    ->fetchOne();
        }
        catch (DBALException $e)
        {
            return false;
        }
        finally
        {
            $this->connection->executeStatement("DELETE FROM `$table` WHERE `$column`=\"$payload\" LIMIT 1");
        }
    }

    /**
     * @throws ConfigException|Throwable
     * @internal
     */
    public function initDryRun(bool $option, array $tables): bool
    {
        if (!$option)
        {
            return false;
        }

        $supportsTransactions = \array_reduce(\array_keys($tables), function ($carry, $table) use ($tables) {
            return $carry && $this->testTransactionAbility($table, $tables[$table]);
        }, true);

        if (!$supportsTransactions) {
            throw new ConfigException('The database does not support transactions. Cannot run in dry-run mode.');
        }

        return true;
    }
}