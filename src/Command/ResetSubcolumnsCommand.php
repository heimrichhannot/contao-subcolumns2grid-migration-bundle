<?php

namespace HeimrichHannot\Subcolumns2Grid\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DBALDriverException;
use Doctrine\DBAL\Exception as DBALException;
use HeimrichHannot\Subcolumns2Grid\Util\Helper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

class ResetSubcolumnsCommand extends Command
{
    protected Connection $connection;
    protected Helper $helper;

    public function __construct(
        Connection $connection,
        Helper     $helper,
        ?string    $name = null
    ) {
        $this->connection = $connection;
        $this->helper = $helper;
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('sub2grid:rollback')
            ->setDescription('Reset grid columns to their original subcolumns');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Resetting sub-columns from migrated grid columns');

        $io->note('This will reset all grid columns to their original subcolumns. '
            . 'This will retain content elements and form fields and their contents but change their types back to '
            . 'what they were before the migration.');
        if (!$io->confirm('Proceed with the rollback?'))
        {
            return Command::SUCCESS;
        }

        try
        {
            $this->connection->beginTransaction();

            $this->reset($io);

            $this->connection->commit();
        }
        catch (Throwable $e)
        {
            $io->error($e->getMessage());
            $io->getErrorStyle()->block($e->getTraceAsString());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @throws DBALException|DBALDriverException
     */
    protected function reset(SymfonyStyle $io): void
    {
        if ($io->confirm('Rollback tl_content?'))
        {
            if ($this->rollbackTlContent()) {
                $io->success('tl_content rolled back successfully.');
            } else {
                $io->warning('tl_content was not rolled back.');
            }
        }

        if ($io->confirm('Reset customTpl of colsets in in tl_content?'))
        {
            if ($this->resetCustomTplTlContent()) {
                $io->success('customTpl in tl_content reset successfully.');
            } else {
                $io->warning('customTpl in tl_content was not reset.');
            }
        }

        if ($io->confirm('Rollback tl_form_field?'))
        {
            if ($this->rollbackTlFormField()) {
                $io->success('tl_form_field rolled back successfully.');
            } else {
                $io->warning('tl_form_field was not rolled back.');
            }
        }

        if ($io->confirm('Reset customTpl of formcols in tl_form_field?'))
        {
            if ($this->resetCustomTplTlFormField()) {
                $io->success('customTpl in tl_form_field reset successfully.');
            } else {
                $io->warning('customTpl in tl_form_field was not reset.');
            }
        }

        if ($io->confirm('Delete all migrated grid definitions?'))
        {
            if ($this->rollbackTlBsGrid()) {
                $io->success('Migrated grid definitions deleted successfully.');
            } else {
                $io->warning('Migrated grid definitions were not deleted.');
            }
        }
    }

    /**
     * @throws DBALException
     */
    protected function resetCustomTplTlContent(): bool
    {
        $sql = <<<SQL
            UPDATE `tl_content`
               SET customTpl = ''
             WHERE type LIKE "colset%"
               AND customTpl LIKE "ce_bs_grid%"
            ;
        SQL;

        return (bool) $this->connection->executeStatement($sql);
    }

    /**
     * @throws DBALException
     */
    protected function resetCustomTplTlFormField(): bool
    {
        $sql = <<<SQL
            UPDATE `tl_form_field`
               SET customTpl = ''
             WHERE type LIKE "formcol%"
               AND customTpl LIKE "ce_bs_grid%"
            ;
        SQL;

        return (bool) $this->connection->executeStatement($sql);
    }

    /**
     * @throws DBALException
     */
    protected function rollbackTlBsGrid(): bool
    {
        $sql = <<<SQL
            DELETE FROM `tl_bs_grid`
             WHERE description LIKE "[sub2col:%"
            ;
        SQL;

        return (bool) $this->connection->executeStatement($sql);
    }

    /**
     * @throws DBALException|DBALDriverException
     */
    protected function rollbackTlContent(): bool
    {
        $scTypeExists = $this->helper->dbColumnExists('tl_content', 'sc_type');
        $scColumnsetExists = $this->helper->dbColumnExists('tl_content', 'sc_columnset');

        if (!$scTypeExists && !$scColumnsetExists) {
            return false;
        }

        $sqlSubColTypeNotEmpty = $scTypeExists ? '(sc_type IS NOT NULL AND sc_type != "")' : '0';
        $sqlSubColNotEmpty = $scColumnsetExists ? '(sc_columnset IS NOT NULL AND sc_columnset != "")' : '0';

        $sql = <<<SQL
            UPDATE `tl_content`
               SET type = CASE
                       WHEN type = "bs_gridStart" THEN "colsetStart"
                       WHEN type = "bs_gridSeparator" THEN "colsetPart"
                       WHEN type = "bs_gridStop" THEN "colsetEnd"
                       ELSE type
                   END
             WHERE type IN ("bs_gridStart", "bs_gridSeparator", "bs_gridStop")
               AND ($sqlSubColTypeNotEmpty OR $sqlSubColNotEmpty)
            ;
        SQL;

        return $this->connection->executeStatement($sql);
    }

    /**
     * @throws DBALException|DBALDriverException
     */
    protected function rollbackTlFormField(): bool
    {
        $scTypeExists = $this->helper->dbColumnExists('tl_form_field', 'fsc_type');
        $scColumnsetExists = $this->helper->dbColumnExists('tl_form_field', 'sc_columnset');

        if (!$scTypeExists && !$scColumnsetExists) {
            return false;
        }

        $sqlSubColTypeNotEmpty = $scTypeExists ? 'fsc_type IS NOT NULL AND fsc_type != ""' : '0';
        $sqlSubColNotEmpty = $scColumnsetExists ? 'sc_columnset IS NOT NULL AND sc_columnset != ""' : '0';

        $sql = <<<SQL
            UPDATE `tl_form_field`
               SET type = CASE
                       WHEN type = "bs_gridStart" THEN "formcolstart"
                       WHEN type = "bs_gridSeparator" THEN "formcolpart"
                       WHEN type = "bs_gridStop" THEN "formcolend"
                       ELSE type
                   END
             WHERE type IN ("bs_gridStart", "bs_gridSeparator", "bs_gridStop")
               AND $sqlSubColTypeNotEmpty OR $sqlSubColNotEmpty
            ;
        SQL;

        return $this->connection->executeStatement($sql);
    }
}