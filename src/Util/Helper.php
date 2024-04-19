<?php

namespace HeimrichHannot\Subcolumns2Grid\Util;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Random\RandomException;

class Helper
{
    static protected array $dbColumnsCache = [];

    /**
     * @throws RandomException
     */
    public static function GUIDv4($data = null): string
    {
        // Generate 16 bytes (128 bits) of random data or use the data passed into the function.
        $data = $data ?? \random_bytes(16);
        \assert(\strlen($data) == 16);

        // Set version to 0100
        $data[6] = \chr(\ord($data[6]) & 0x0f | 0x40);
        // Set bits 6-7 to 10
        $data[8] = \chr(\ord($data[8]) & 0x3f | 0x80);

        // Output the 36 character UUID.
        return \vsprintf('%s%s-%s-%s-%s-%s%s%s', \str_split(\bin2hex($data), 4));
    }

    /**
     * @throws RandomException
     */
    public static function savepointId(): string
    {
        return 'savepoint_' . \str_replace('-', '', self::GUIDv4());
    }

    /**
     * @throws DBALException
     */
    public static function dbColumnExists(Connection $connection, string $table, string $column): bool
    {
        $cacheKey = $connection->getDatabase() . "." . $table . "." . $column;

        if (isset(self::$dbColumnsCache[$cacheKey])) {
            return self::$dbColumnsCache[$cacheKey];
        }

        $stmt = $connection->prepare(<<<SQL
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
}