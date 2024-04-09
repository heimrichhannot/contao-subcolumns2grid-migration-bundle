<?php /** @noinspection PhpUnusedAliasInspection */

namespace HeimrichHannot\Subcolumns2Grid\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use FelixPfeiffer\Subcolumns\ModuleSubcolumns;
use HeimrichHannot\Subcolumns2Grid\Config\ColSetDefinition;
use HeimrichHannot\Subcolumns2Grid\Config\ColSizeDefinition;
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
    protected const CE_TYPES = [
        'colsetStart',
        'colsetPart',
        'colsetEnd',
    ];
    protected const CLASS_TYPE_COL = 'col';
    protected const CLASS_TYPE_OFFSET = 'offset';
    protected const CLASS_TYPE_ORDER = 'order';
    protected const BREAKPOINTS = ['xs', 'sm', 'md', 'lg', 'xl', 'xxl'];
    protected const SMALLEST_PLACEHOLDER = '{smallest}';

    protected Connection $connection;
    protected ContaoFramework $framework;

    public function __construct(Connection $connection, ContaoFramework $framework, ?string $name = null)
    {
        $this->connection = $connection;
        $this->framework = $framework;
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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->framework->initialize();

        $io = new SymfonyStyle($input, $output);

        $io->title('Migrating subcolumns to grid columns');

        try {
            $config = $this->createConfig($input);
        } catch (InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        if ($from = $config->getFrom()) {
            $io->info(
                sprintf('Migrating from %s',
                $from === MigrationConfig::FROM_SUBCOLUMNS_MODULE
                    ? 'SubColumns module'
                    : 'SubColumnsBootstrapBundle')
            );
        }

        if (!$config->hasAnyFetch()) {
            $io->error('No migration source found.');
            return Command::FAILURE;
        }

        if ($config->hasFetch(MigrationConfig::FETCH_GLOBALS))
        {
            $io->comment('Fetching definitions from globals.');
            try {
                $configFetch = $this->fetchConfigSubcolumns();
            } catch (\OutOfBoundsException $e) {
                $io->error($e->getMessage());
                return Command::FAILURE;
            }
        }

        if ($config->hasFetch(MigrationConfig::FETCH_DB))
        {
            $io->success('Fetching definitions from database.');
            try {

            } catch (\Throwable $e) {
                $io->comment('Please make sure that the SubColumnsBootstrapBundle is installed and migrated to the latest version.');
            }
        }

        return Command::SUCCESS;
    }

    protected function fetchConfigSubcolumns(): ?array
    {
        if (empty($GLOBALS['TL_SUBCL']) || !is_array($GLOBALS['TL_SUBCL'])) {
            throw new \OutOfBoundsException('No subcolumns found in $GLOBALS.');
        }

        $colsetDefinitions = [];

        foreach ($GLOBALS['TL_SUBCL'] as $profileName => $profile)
        {
            if (!is_array($profile) || \strpos($profileName, 'yaml') !== false) {
                continue;
            }

            $label = $profile['label'] ?? null;
            $scClass = $profile['scclass'] ?? null;
            $equalize = $profile['equalize'] ?? null;
            $inside = $profile['inside'] ?? null;
            $gap = $profile['gap'] ?? null;
            $files = $profile['files'] ?? null;


            /**
             * @var array<array{0: string, 1: string}> $colsConfig
             */
            foreach ($profile['sets'] as $setName => $globalColsConfig)
            {
                /** @var array<string, array<ColSizeDefinition>> $sizes */
                $sizes = $this->parseGlobalSets($globalColsConfig);

                $colset = ColSetDefinition::create()
                    ->setPublished(true)
                    ->setColumnSizes($sizes)
                    ->setName($setName); ## todo: give improved name

                $colsetDefinitions[] = $colset;
            }
        }

        return $colsetDefinitions;
    }

    protected function parseGlobalSets(array $colsConfig): array
    {
        /** @var array<string, array<ColSizeDefinition>> $sizes */
        $sizes = [];

        $colIndex = 0;
        foreach ($colsConfig as $singleCol)
        {
            if (\count($singleCol) < 1) {
                continue;
            }

            $customClasses = [];
            $classNames = \explode(' ', \preg_replace('/\s+/i', ' ', $singleCol[0]));
            $colClasses = $this->filterClassNames($classNames, $customClasses);

            foreach ($colClasses as $objColClass)
            {
                $breakpoint = $objColClass->breakpoint ?: self::SMALLEST_PLACEHOLDER;

                if (!isset($sizes[$breakpoint]))
                {
                    $sizes[$breakpoint] = [];
                }

                $sizes[$breakpoint][$colIndex] ??= ColSizeDefinition::create()
                    ->setCustomClasses(\implode(' ', $customClasses));

                $col = $sizes[$breakpoint][$colIndex];

                switch ($objColClass->type)
                {
                    case self::CLASS_TYPE_COL:
                        $col->setSpan($objColClass->width);
                        break;
                    case self::CLASS_TYPE_OFFSET:
                        $col->setOffset($objColClass->width);
                        break;
                    case self::CLASS_TYPE_ORDER:
                        $col->setOrder($objColClass->width);
                        break;
                }
            }

            $colIndex++;
        }

        $this->applyFallbackSizes($sizes);

        return $sizes;
    }

    protected function applyFallbackSizes(array &$sizes)
    {
        $smallestSizeCols = $sizes[self::SMALLEST_PLACEHOLDER] ?? [];
        unset($sizes[self::SMALLEST_PLACEHOLDER]);

        if (empty($smallestSizeCols)) {
            return;
        }

        $breakpointValues = \array_flip(self::BREAKPOINTS);

        $smallestKnownBreakpoint = 'xxl';
        foreach (\array_keys($sizes) as $breakpoint)
        {
            if ($breakpointValues[$breakpoint] < $breakpointValues[$smallestKnownBreakpoint])
            {
                $smallestKnownBreakpoint = $breakpoint;
            }
        }

        if ($breakpointValues[$smallestKnownBreakpoint] - 1 >= 0)
            // the smallest available breakpoint has not yet been set,
            // therefore we can just set the smallest undefined size to the smallest breakpoint
        {
            foreach ($smallestSizeCols as $col) {
                $col->setBreakpoint(self::BREAKPOINTS[0]);
            }
            $sizes[self::BREAKPOINTS[0]] = $smallestSizeCols;
            return;
        }

        // otherwise, the smallest breakpoint is already defined,
        // therefore we need to apply the smallest undefined size as fallback to the smallest breakpoint

        $definedSizeCols = $sizes[self::BREAKPOINTS[0]] ?? [];

        $minSize = \min(\count($definedSizeCols), \count($smallestSizeCols));
        $fallback = \array_values(\array_slice($smallestSizeCols, 0, $minSize));
        $values = \array_values(\array_slice($definedSizeCols, 0, $minSize));

        for ($i = 0; $i < $minSize; $i++)
        {
            $f = $fallback[$i];
            $v = $values[$i];

            if (null === $v->getSpan())   $v->setSpan($f->getSpan());
            if (null === $v->getOffset()) $v->setOffset($f->getOffset());
            if (null === $v->getOrder())  $v->setOrder($f->getOrder());
        }

        if (\count($smallestSizeCols) > $minSize)
        {
            $sizes[self::BREAKPOINTS[0]] = \array_merge($values, \array_slice($smallestSizeCols, $minSize));
        }
    }

    protected function filterClassNames(array $classNames, array &$customClasses = []): array
    {
        $callback = static function ($strClass) use (&$customClasses) {
            if (empty($strClass)) {
                return null;
            }

            $matches = [];

            if (!\preg_match_all("/(?P<type>col|col-offset|offset|order)(?:-(?P<breakpoint>xxs|xs|sm|md|lg|xl|xxl))?(?:-(?P<width>\d+))?/i", $strClass, $matches))
            {
                $customClasses[] = $strClass;
                return null;
            }

            $class = new class() {
                public string $class;
                public string $type;
                public string $breakpoint;
                public string $width;
            };

            $class->class = $strClass;
            $class->breakpoint = $matches['breakpoint'][0] ?? '';
            $type = $matches['type'][0] ?? self::CLASS_TYPE_COL;
            $class->type = \strpos($type, 'offset') !== false
                ? self::CLASS_TYPE_OFFSET : (
                \strpos($type, 'order') !== false
                    ? self::CLASS_TYPE_ORDER
                    : self::CLASS_TYPE_COL
                );
            $class->width = $matches['width'][0] ?? '';

            return $class;
        };

        return \array_filter(\array_map($callback, $classNames));
    }

    protected function fetchDatabaseSubcolumns(): void
    {
        if (empty($GLOBALS['TL_DCA']['tl_content']['fields']['sc_columnset'])) {
            throw new \DomainException('No sc_columnset field found in tl_content.');
        }
    }

    /**
     * @throws Exception
     */
    protected function createConfig(InputInterface $input): MigrationConfig
    {
        $config = new MigrationConfig();

        $from = $this->createConfigGetFrom($input);
        $config->setFrom($from);

        switch ($from)
        {
            case MigrationConfig::FROM_SUBCOLUMNS_MODULE:
                $config->addFetch(MigrationConfig::FETCH_GLOBALS);
                break;

            case MigrationConfig::FROM_SUBCOLUMNS_BOOTSTRAP_BUNDLE:

                throw new InvalidArgumentException('Migrating from the SubColumnsBootstrapBundle is not supported yet.');

                $config->addFetch(MigrationConfig::FETCH_DB);

                if (\class_exists(ModuleSubcolumns::class)) {
                    $config->addFetch(MigrationConfig::FETCH_GLOBALS);
                    break;
                }

                $stmt = $this->connection->prepare(<<<'SQL'
                    SELECT id FROM tl_content WHERE type IN :types AND sc_columnset LIKE "globals.%" LIMIT 1
                SQL);
                $stmt->bindValue('types', static::CE_TYPES);

                $res = $stmt->executeQuery();
                if ($res->rowCount() > 0) {
                    $config->addFetch(MigrationConfig::FETCH_GLOBALS);
                }

                break;
        }

        return $config;
    }

    /**
     * @throws Exception
     */
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
                2 => MigrationConfig::FROM_SUBCOLUMNS_BOOTSTRAP_BUNDLE,
            ][$from];
        })();

        if ($fromOption !== null) {
            return $fromOption;
        }

        if (class_exists( SubColumnsBootstrapBundle::class)) {
            return MigrationConfig::FROM_SUBCOLUMNS_BOOTSTRAP_BUNDLE;
        }

        if (class_exists(ModuleSubcolumns::class)) {
            return MigrationConfig::FROM_SUBCOLUMNS_MODULE;
        }

        $table = $this->connection
            ->prepare('SHOW TABLES LIKE "tl_columnset"')
            ->executeQuery()
            ->fetchOne();

        if ($table === 'tl_columnset')
        {
            return MigrationConfig::FROM_SUBCOLUMNS_BOOTSTRAP_BUNDLE;
        }

        return 0;
    }
}