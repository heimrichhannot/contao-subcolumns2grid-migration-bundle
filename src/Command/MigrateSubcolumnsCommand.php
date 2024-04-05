<?php

namespace HeimrichHannot\Subcolumns2Grid\Command;

use Doctrine\DBAL\Connection;
use FelixPfeiffer\Subcolumns\ModuleSubcolumns;
use HeimrichHannot\Subcolumns2Grid\Config\MigrationConfig;
use HeimrichHannot\SubColumnsBootstrapBundle\SubColumnsBootstrapBundle;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class MigrateSubcolumnsCommand extends Command
{
    protected Connection $con;

    public function __construct(Connection $connection, ?string $name = null)
    {
        $this->con = $connection;
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('sub2grid:migrate')->setDescription('Migrates existing subcolumns to grid columns.');
        $this->addOption(
            'from-subcolumns-bootstrap-bundle',
            'b',
            InputOption::VALUE_OPTIONAL,
            'Attempt to migrate from the SubColumnsBootstrapBundle, no matter if it\'s installed.',
            false
        );
        $this->addOption(
            'from-subcolumns-module',
            'm',
            InputOption::VALUE_OPTIONAL,
            'Attempt to migrate from the SubColumns module, no matter if it\'s installed.',
            false
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Migrating subcolumns to grid columns');

        try {
            $config = $this->createConfig($input);
        } catch (InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }


        if ($isSCBB) {
            $io->info('SubColumnsBootstrapBundle detected.');
        } else {
            $io->info('SubColumnsBootstrapBundle not detected.');
        }

        if ($isSCBB && empty($GLOBALS['TL_DCA']['tl_content']['fields']['sc_columnset']))
        {
            $io->error('SubColumnsBootstrapBundle detected, but no sc_columnset field found in tl_content.');
            $io->comment('Please make sure that the SubColumnsBootstrapBundle is installed and migrated to the latest version.');
            return Command::FAILURE;
        }

        if (!$isSCBB) {

        }

    }

    protected function createConfig(InputInterface $input): MigrationConfig
    {
        $config = new MigrationConfig();

        $config->setFrom($this->createConfigGetFrom($input));

        return $config;
    }

    protected function createConfigGetFrom(InputInterface $input): int
    {
        $fromOption = (function () use ($input) {
            $from =
                (int)$input->getOption('from-subcolumns-module') |
                (int)$input->getOption('from-subcolumns-bootstrap-bundle') * 2;

            if ($from === 3) {
                throw new InvalidArgumentException('You can only specify one migration source parameter "from-*".');
            }

            return [
                0 => null,
                1 => MigrationConfig::FROM_SUBCOLUMNS_MODULE,
                2 => MigrationConfig::FROM_SUBCOLUMNS_BOOTSTRAP_BUNDLE
            ][$from];
        })();

        if ($fromOption !== null) {
            return $fromOption;
        }

        if (class_exists(SubColumnsBootstrapBundle::class)) {
            return MigrationConfig::FROM_SUBCOLUMNS_BOOTSTRAP_BUNDLE;
        }

        if (class_exists(ModuleSubcolumns::class)) {
            return MigrationConfig::FROM_SUBCOLUMNS_MODULE;
        }

        $found = $this->con->prepare('SHOW TABLES LIKE "tl_columnset"')->executeQuery();

        return 0;
    }
}