<?php

namespace HeimrichHannot\Subcolumns2Grid\Command;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Result;
use HeimrichHannot\Subcolumns2Grid\Exception\FixException;
use HeimrichHannot\Subcolumns2Grid\Util\Constants;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class FixSubcolumnsCommand extends Command
{
    protected Connection $connection;
    protected ProgressBar $progress;
    protected bool $cleanse;
    protected bool $dryRun;
    protected array $notes = [];
    protected array $errors = [];

    public function __construct(
        Connection $connection,
        ?string $name = null
    ) {
        $this->connection = $connection;
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('sub2grid:fix')
            ->setDescription('Fixes corrupted subcolumns in the database.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not write changes to the database.')
            ->addOption('cleanse', 'c', InputOption::VALUE_NONE, 'Allow the deletion of corrupt entities that are not published.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->cleanse = (bool) $input->getOption('cleanse');
        $this->dryRun = (bool) $input->getOption('dry-run');

        try
        {
            $this->initProgressBar($output);

            $this->connection->beginTransaction();

            try
            {
                $this->fixTlContent();
                $this->fixTlFormField();
            }
            catch (\Throwable $e)
            {
                $this->connection->rollBack();
                throw $e;
            }

            $this->dryRun
                ? $this->connection->rollBack()
                : $this->connection->commit();

            $io->newLine(2);
            $io->info('Finished processing all records.');

            foreach ($this->notes as $note)
            {
                $io->note($note);
            }

            foreach ($this->errors as $error)
            {
                $io->error($error);
            }

            return Command::SUCCESS;
        }
        catch (FixException $e)
        {
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

    protected function initProgressBar(OutputInterface $output): void
    {
        ProgressBar::setFormatDefinition('custom', ' %current%/%max% [%bar%] %percent:3s%% %elapsed:16s%/%estimated:-16s% %memory:6s% %message%');

        $progress = new ProgressBar($output, 100);
        $progress->setFormat('custom');
        $progress->setRedrawFrequency(1);
        $progress->maxSecondsBetweenRedraws(0.2);
        $progress->minSecondsBetweenRedraws(0.1);

        $this->progress = $progress;
    }

    /**
     * @throws DBALException
     * @throws FixException
     */
    protected function fixTlContent(): void
    {
        $result = $this->fetchTlContent();
        $this->fixResults('tl_content', $result);
    }

    /**
     * @throws DBALException
     * @throws FixException
     */
    protected function fixTlFormField(): void
    {
        $result = $this->fetchTlFormField();
        $this->fixResults('tl_form_field', $result, 'tl_form');
    }

    /**
     * @throws DBALException
     */
    protected function fetchTlContent(): Result
    {
        $typeIn = \implode(', ', \array_map(static function (string $type) {
            return "'$type'";
        }, Constants::CE_TYPES));

        $stmt = $this->connection->prepare(<<<SQL
            SELECT id, type, pid, ptable, sorting, invisible, sc_parent, sc_type, sc_name
              FROM tl_content
             WHERE type IN ($typeIn)
             ORDER BY ptable, pid, sorting
        SQL);

        return $stmt->executeQuery();
    }

    /**
     * @throws DBALException
     */
    protected function fetchTlFormField(): Result
    {
        $typeIn = \implode(', ', \array_map(static function (string $type) {
            return "'$type'";
        }, Constants::FF_TYPES));

        $stmt = $this->connection->prepare(<<<SQL
            SELECT id, type, pid, sorting, invisible, fsc_parent, fsc_type, fsc_name
              FROM tl_form_field
             WHERE type IN ($typeIn)
             ORDER BY pid, sorting
        SQL);

        return $stmt->executeQuery();
    }

    /**
     * @throws FixException
     * @throws DBALException
     */
    protected function fixResults(string $table, Result $result, ?string $overrideParentTable = null): void
    {
        $rowCount = $result->rowCount();

        if ($rowCount < 1) {
            return;
        }

        $this->progress->start($rowCount);

        $collector = [];

        $currentParentTable = $overrideParentTable;
        $currentParentId = null;

        $currentSetIndex = -1;

        while ($row = $result->fetchAssociative())
        {
            if (!$overrideParentTable && $currentParentTable !== $row['ptable'])
            {
                if ($currentParentId !== null)
                {
                    unset($collector[$currentParentTable]);
                }

                $currentParentTable = $row['ptable'];
                $collector[$currentParentTable] = [];
            }

            if ($currentParentId !== $row['pid'])
            {
                // finish last parent
                if ($currentParentId !== null)
                {
                    if (!empty($collector[$currentParentTable][$currentParentId])) {
                        $this->fixParent(
                            $table,
                            $currentParentId,
                            $currentParentTable,
                            $collector[$currentParentTable][$currentParentId]
                        );
                    }

                    unset($collector[$currentParentTable][$currentParentId]);

                    $currentSetIndex = -1;
                }

                // start a new parent
                $currentParentId = $row['pid'];
                $collector[$currentParentTable][$currentParentId] = [];
            }

            $this->progress->setMessage(\sprintf(
                "       $table.id=%s  %s   on  %s.id=%s",
                \str_pad($row['id'], 8),
                \str_pad($row['type'], 14),
                $currentParentTable,
                $currentParentId
            ));

            if ($row['type'] === Constants::CE_TYPE_COLSET_START)
            {
                $currentSetIndex++;
            }

            $collector[$currentParentTable][$currentParentId][$currentSetIndex] ??= [];
            $collector[$currentParentTable][$currentParentId][$currentSetIndex][] = $row;

            if ($row['type'] === Constants::CE_TYPE_COLSET_END)
            {
                $currentSetIndex--;
            }

            \usleep(500);

            $this->progress->advance();
        }

        $this->progress->finish();
    }

    /**
     * @throws FixException
     * @throws DBALException
     */
    protected function fixParent(string $table, int $parentId, string $parentTable, array $sets): void
    {
        foreach ($sets as $set)
        {
            if (!$this->prepareSet($table, $set, $parentId, $parentTable)) {
                continue;
            }

            switch ($table) {
                case 'tl_content':
                    $this->updateTlContentSets($set, $parentId, $parentTable);
                    break;
                case 'tl_form_field':
                    $this->updateTlFormFieldSets($set, $parentId);
                    break;
            }
        }
    }

    /**
     * @throws FixException
     * @throws DBALException
     */
    protected function prepareSet(string $table, array $set, int $parentId, ?string $parentTable = null): bool
    {
        if (\count($set) < 2
            || $set[0]['type'] !== Constants::CE_TYPE_COLSET_START
            || $set[\count($set) - 1]['type'] !== Constants::CE_TYPE_COLSET_END)
        {
            [$ids, $strIds] = $this->mapIds($set);

            $sqlParentTable = $parentTable ? " AND ptable=\"$parentTable\"" : '';
            $sqlSelect = "SELECT * FROM $table WHERE id IN ($strIds) AND pid=$parentId$sqlParentTable;";

            $allInvisible = \array_reduce($set, static function (bool $carry, array $row) {
                return $carry && $row['invisible'];
            }, true);

            $errMsg = "corrupt set -- incomplete series\n$sqlSelect";

            if ($this->cleanse && $allInvisible)
            {
                $this->notes[] = "deleted $errMsg";
                $this->connection->executeQuery(
                    'DELETE FROM ? WHERE id IN (?)',
                    [$table, $ids], [ParameterType::STRING, ArrayParameterType::INTEGER]
                );
                return false;
            }

            throw new FixException($errMsg);
        }

        return true;
    }

    /**
     * @throws DBALException
     */
    protected function updateTlContentSets(array $set, int $parentId, string $parentTable): void
    {
        [$ids, $strIds] = $this->mapIds($set);

        $startId = $set[0]['id'];
        $format = static function ($suffix = '') use ($startId) {
            return "colset.$startId" . $suffix;
        };

        $stmt = $this->connection->prepare(<<<SQL
            UPDATE tl_content
               SET sc_parent = :startId,
                   sc_name = CASE type
                       WHEN :startType THEN :startName
                       WHEN :partType THEN :partName
                       WHEN :endType THEN :endName
                       ELSE sc_name
                   END
             WHERE id IN ($strIds)
               AND pid = :parentId
               AND ptable = :parentTable
        SQL);

        $stmt->bindValue('startId', $startId, ParameterType::INTEGER);

        $stmt->bindValue('startType', Constants::CE_TYPE_COLSET_START);
        $stmt->bindValue('partType', Constants::CE_TYPE_COLSET_PART);
        $stmt->bindValue('endType', Constants::CE_TYPE_COLSET_END);
        $stmt->bindValue('startName', $format());
        $stmt->bindValue('partName', $format('-Part'));
        $stmt->bindValue('endName', $format('-End'));

        $stmt->bindValue('parentId', $parentId, ParameterType::INTEGER);
        $stmt->bindValue('parentTable', $parentTable);

        $stmt->executeStatement();
    }

    /**
     * @throws DBALException
     */
    protected function updateTlFormFieldSets(array $set, int $parentId): void
    {
        [$ids, $strIds] = $this->mapIds($set);

        $startId = $set[0]['id'];
        $format = static function ($suffix = '') use ($startId) {
            return "formcol.$startId" . $suffix;
        };

        $stmt = $this->connection->prepare(<<<SQL
            UPDATE tl_form_field
               SET fsc_parent = :startId,
                   fsc_name = CASE type
                       WHEN :startType THEN :startName
                       WHEN :partType THEN :partName
                       WHEN :endType THEN :endName
                       ELSE sc_name
                   END
             WHERE id IN ($strIds)
               AND pid = :parentId
        SQL);

        $stmt->bindValue('startId', $startId, ParameterType::INTEGER);

        $stmt->bindValue('startType', Constants::CE_TYPE_COLSET_START);
        $stmt->bindValue('partType', Constants::CE_TYPE_COLSET_PART);
        $stmt->bindValue('endType', Constants::CE_TYPE_COLSET_END);
        $stmt->bindValue('startName', $format());
        $stmt->bindValue('partName', $format('-Part'));
        $stmt->bindValue('endName', $format('-End'));

        $stmt->bindValue('parentId', $parentId, ParameterType::INTEGER);

        $stmt->executeStatement();
    }

    protected function mapIds(array $set): array
    {
        $ids = \array_map(static function (array $row) {
            return $row['id'];
        }, $set);

        $strIds = \implode(', ', $ids);

        return [$ids, $strIds];
    }
}