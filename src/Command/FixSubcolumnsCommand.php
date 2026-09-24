<?php

namespace HeimrichHannot\Subcolumns2Grid\Command;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Result;
use HeimrichHannot\Subcolumns2Grid\Exception\FixException;
use HeimrichHannot\Subcolumns2Grid\Exception\Sub2ColException;
use HeimrichHannot\Subcolumns2Grid\Util\Constants;
use HeimrichHannot\Subcolumns2Grid\Util\Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command to fix corrupted subcolumns in the database.
 *
 * This command provides functionality to identify and fix corrupted subcolumn entries within the database.
 * It supports a dry-run mode for safe testing and an option to cleanse by deleting corrupt entities that are not published.
 */
class FixSubcolumnsCommand extends Command
{
    protected Connection $connection;
    protected Helper $helper;
    protected ProgressBar $progress;
    protected bool $cleanse;
    protected bool $force;
    protected bool $dryRun;
    protected array $notes = [];
    protected array $errors = [];

    public function __construct(
        Connection $connection,
        Helper $helper,
        ?string $name = null
    ) {
        $this->connection = $connection;
        $this->helper = $helper;
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('sub2grid:fix')
            ->setDescription('Fixes corrupted subcolumns in the database.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not write changes to the database.')
            ->addOption('cleanse', 'c', InputOption::VALUE_NONE, 'Allow the deletion of corrupt entities that are not published.')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force the execution of the command even on entities that are published.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->cleanse = (bool) $input->getOption('cleanse');
        $this->force = (bool) $input->getOption('force');

        try
        {
            $this->dryRun = $this->helper->initDryRun(
                (bool) ($input->getOption('dry-run') ?? false),
                Helper::TEST_TL_CONTENT + Helper::TEST_TL_FORM_FIELD
            );

            if ($this->dryRun) {
                $io->warning('Running in dry-run mode. No changes will be made to the database.');
            }

            $this->initProgressBar($output);

            $this->connection->beginTransaction();

            $noneFoundIn = [];

            try
            {
                if (!$this->fixTlContent())
                {
                    $noneFoundIn[] = 'tl_content';
                }

                if (!$this->fixTlFormField())
                {
                    $noneFoundIn[] = 'tl_form_field';
                }
            }
            catch (\Throwable $e)
            {
                $this->connection->rollBack();
                throw $e;
            }

            $this->dryRun
                ? $this->connection->rollBack()
                : $this->connection->commit();

            if (empty($noneFoundIn)) $io->newLine(2);
            $io->success('Finished processing all records.');

            if (!empty($noneFoundIn))
            {
                $io->info(\sprintf('No relevant records found in: %s.', \implode(', ', $noneFoundIn)));
            }

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
        catch (Sub2ColException $e)
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
    protected function fixTlContent(): bool
    {
        $result = $this->fetchTlContent();
        return $this->fixResults('tl_content', $result);
    }

    /**
     * @throws DBALException
     * @throws FixException
     */
    protected function fixTlFormField(): bool
    {
        $result = $this->fetchTlFormField();
        return $this->fixResults('tl_form_field', $result, 'tl_form');
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
    protected function fixResults(string $table, Result $result, ?string $overrideParentTable = null): bool
    {
        $rowCount = $result->rowCount();

        if ($rowCount < 1) {
            return false;
        }

        $this->progress->start($rowCount);

        $collector = [];

        $currentParentTable = $overrideParentTable;
        $currentParentId = null;

        $processCurrentParent = function () use (
            $table,
            &$collector,
            &$currentParentTable,
            &$currentParentId
        ) {
            if ($currentParentTable === null || $currentParentId === null) {
                return;
            }

            if (!empty($collector[$currentParentTable][$currentParentId]))
            {
                $this->fixParent(
                    $table,
                    $currentParentId,
                    $currentParentTable,
                    $this->groupIntoSets($collector[$currentParentTable][$currentParentId])
                );
            }

            unset($collector[$currentParentTable][$currentParentId]);

            $currentParentId = null;
        };

        while ($row = $result->fetchAssociative())
        {
            if (!$overrideParentTable && $currentParentTable !== $row['ptable'])
            // new parent table
            {
                $processCurrentParent();

                unset($collector[$currentParentTable]);

                // start a new parent table entry
                $currentParentTable = $row['ptable'];
                $collector[$currentParentTable] = [];
            }

            if ($currentParentId !== $row['pid'])
            // new parent
            {
                $processCurrentParent();

                // start a new parent entity entry
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

            $collector[$currentParentTable][$currentParentId][] = $row;

            $this->progress->advance();
        }

        if (!empty($collector[$currentParentTable][$currentParentId]))
        // finish last parent of last parent table
        {
            $this->fixParent(
                $table,
                $currentParentId,
                $currentParentTable,
                $this->groupIntoSets($collector[$currentParentTable][$currentParentId])
            );
        }

        $this->progress->finish();

        return true;
    }

    /**
     * Groups the sub-column elements of one parent into sets, the way the front end rendered them.
     *
     * SubColumns never looked at sc_parent when rendering: a visible start opened a row, a visible part
     * opened the next column and a visible end closed the innermost open row -- invisible elements were
     * simply not rendered. Pairing all elements in one pass, visible and invisible alike, can therefore
     * disagree with what visitors saw: an invisible end closes a visible start, and the visible end that
     * actually closed it on the page is left over as an "orphan" -- and deleted by --cleanse --force. The
     * migrated grid then opens and never closes, and every following element ends up inside it.
     *
     * So:
     *  1. visible elements are paired among themselves, in order;
     *  2. invisible elements are paired among themselves, in order;
     *  3. an invisible part left over from 2. joins the innermost visible set around it -- it never
     *     rendered, so it changes nothing, and it keeps the column separator an editor hid;
     *  4. everything else is left over as its own fragment, which prepareSet() treats as before.
     *
     * Where visibility is consistent within every set, this yields the same sets as a single pass.
     * Only the invisible flag counts; start/stop publication windows are not taken into account.
     *
     * @param array<int, array> $rows The parent's sub-column elements, ordered by sorting.
     * @return array<int, array> Sets and fragments, each ordered by sorting.
     */
    protected function groupIntoSets(array $rows): array
    {
        $isStart = static function (array $row): bool { return \in_array($row['type'], Constants::TYPES_START, true); };
        $isEnd = static function (array $row): bool { return \in_array($row['type'], Constants::TYPES_END, true); };

        /**
         * Pairs the rows at the given positions in order.
         * @return array{0: array<int, int[]>, 1: int[]} [set start position => member positions, unpaired positions]
         */
        $pair = static function (array $positions) use ($rows, $isStart, $isEnd): array {
            $sets = [];
            $open = [];
            $unpaired = [];

            foreach ($positions as $pos)
            {
                $row = $rows[$pos];

                if ($isStart($row)) {
                    $open[] = $pos;
                    $sets[$pos] = [$pos];
                    continue;
                }

                if (empty($open)) {
                    $unpaired[] = $pos;
                    continue;
                }

                $sets[\end($open)][] = $pos;

                if ($isEnd($row)) {
                    \array_pop($open);
                }
            }

            // starts that were never closed are fragments, not sets
            foreach ($open as $pos) {
                \array_push($unpaired, ...$sets[$pos]);
                unset($sets[$pos]);
            }

            return [$sets, $unpaired];
        };

        $visible = $invisible = [];
        foreach ($rows as $pos => $row) {
            if ($row['invisible']) $invisible[] = $pos; else $visible[] = $pos;
        }

        [$visibleSets, $leftovers] = $pair($visible);
        [$invisibleSets, $invisibleLeftovers] = $pair($invisible);

        foreach ($invisibleLeftovers as $pos)
        {
            $row = $rows[$pos];

            if (!$isStart($row) && !$isEnd($row) && ($setPos = $this->enclosingSet($visibleSets, $pos)) !== null) {
                $visibleSets[$setPos][] = $pos;
                continue;
            }

            $leftovers[] = $pos;
        }

        $sets = [];
        foreach ($visibleSets + $invisibleSets as $members) {
            \sort($members);
            $sets[] = \array_map(static function (int $pos) use ($rows) { return $rows[$pos]; }, $members);
        }

        foreach ($leftovers as $pos) {
            $sets[] = [$rows[$pos]];
        }

        return $sets;
    }

    /**
     * @param array<int, int[]> $sets set start position => member positions, the last one being the end
     * @return int|null The start position of the innermost set whose start and end enclose the position.
     */
    protected function enclosingSet(array $sets, int $pos): ?int
    {
        $innermost = null;

        foreach ($sets as $start => $members)
        {
            if ($start < $pos && \max($members) > $pos && ($innermost === null || $start > $innermost)) {
                $innermost = $start;
            }
        }

        return $innermost;
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
            || !\in_array($set[0]['type'], Constants::TYPES_START, true)
            || !\in_array($set[\count($set) - 1]['type'], Constants::TYPES_END, true))
        {
            [$ids, $strIds] = $this->mapIds($set);

            $sqlParentTable = $parentTable ? " AND ptable=\"$parentTable\"" : '';
            $sqlSelect = "SELECT * FROM $table WHERE id IN ($strIds) AND pid=$parentId$sqlParentTable;";

            $allInvisible = \array_reduce($set, static function (bool $carry, array $row) {
                return $carry && $row['invisible'];
            }, true);

            $errMsg = "corrupt set -- incomplete series\n$sqlSelect";

            if ($this->cleanse && ($this->force || $allInvisible))
            {
                $this->notes[] = "deleted $errMsg";
                $qTable = $this->connection->quoteIdentifier($table);
                $this->connection->executeQuery(
                    "DELETE FROM $qTable WHERE id IN (?)",
                    [$ids], [ArrayParameterType::INTEGER]
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
                       ELSE fsc_name
                   END
             WHERE id IN ($strIds)
               AND pid = :parentId
        SQL);

        $stmt->bindValue('startId', $startId, ParameterType::INTEGER);

        $stmt->bindValue('startType', Constants::FF_TYPE_FORMCOL_START);
        $stmt->bindValue('partType', Constants::FF_TYPE_FORMCOL_PART);
        $stmt->bindValue('endType', Constants::FF_TYPE_FORMCOL_END);
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