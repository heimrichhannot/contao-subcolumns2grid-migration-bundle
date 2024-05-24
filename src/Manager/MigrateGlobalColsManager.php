<?php

namespace HeimrichHannot\Subcolumns2Grid\Manager;

use Doctrine\DBAL\Exception as DBALException;
use HeimrichHannot\Subcolumns2Grid\Config\BreakpointDTO;
use HeimrichHannot\Subcolumns2Grid\Config\ClassName;
use HeimrichHannot\Subcolumns2Grid\Config\ColsetDefinition;
use HeimrichHannot\Subcolumns2Grid\Config\ColumnDefinition;
use HeimrichHannot\Subcolumns2Grid\Config\MigrationConfig;
use HeimrichHannot\Subcolumns2Grid\Util\Constants;
use HeimrichHannot\Subcolumns2Grid\Util\Helper;

class MigrateGlobalColsManager extends AbstractMigrationManager
{
    public const LANG_SUBJECT = 'global sub-column sets';
    public const LANG_FETCHING_DEFINITIONS = 'Fetching definitions from $GLOBALS["TL_SUBCL"]...';

    protected function getMigrationColumnSets(MigrationConfig $config): array
    {
        return $config->getGlobalSubcolumnDefinitions();
    }

    protected function setMigrationColumnSets(MigrationConfig $config, array $columnSets): void
    {
        $config->setGlobalSubcolumnDefinitions($columnSets);
    }

    /**
     * @return array<ColsetDefinition>
     */
    protected function fetchSetDefinitions(MigrationConfig $config): array
    {
        if (empty($GLOBALS['TL_SUBCL']) || !\is_array($GLOBALS['TL_SUBCL'])) {
            throw new \OutOfBoundsException('No subcolumns found in $GLOBALS["TL_SUBCL"].');
        }

        $colsetDefinitions = [];

        foreach ($GLOBALS['TL_SUBCL'] as $profileName => $profile)
        {
            if (!\is_array($profile)
                || \strpos($profileName, 'yaml') !== false
                || empty($profile['sets']))
            {
                continue;
            }

            $label = $profile['label'] ?? null;
            $scClass = $profile['scclass'] ?? null;
            $equalize = $profile['equalize'] ?? null;
            $inside = $profile['inside'] ?? null;
            $gap = $profile['gap'] ?? null;
            $files = $profile['files'] ?? null;

            foreach ($profile['sets'] as $setName => $globalColsConfig)
            {
                $breakpoints = $this->createBreakpointsFromArray($globalColsConfig);

                if (empty($breakpoints)) {
                    continue;
                }

                $idSource = $profileName === 'bootstrap' ? 'bootstrap3' : $profileName;
                $identifier = \sprintf('globals.%s.%s', $idSource, $setName);
                $maxColCount = \max(\array_map('count', $breakpoints) ?: [0]);
                $rowClasses = "colcount_$maxColCount $idSource sc-type-$setName" . (\is_numeric($setName) ? " col_$setName" : " col-$setName");

                $colset = ColsetDefinition::create()
                    ->setIdentifier($identifier)
                    ->setTitle("$label: $setName [global]")
                    ->setPublished(true)
                    ->setBreakpoints($breakpoints)
                    ->setRowClasses($rowClasses)
                    ->setUseInside((bool) $inside)
                    ->setUseOutside(false)
                ;

                if ($colset->getRowClasses() !== $rowClasses) {
                    $config->addNote(
                        "Row classes truncated for \"$identifier\" due to length limitations.\n"
                        . "Should be: \"$rowClasses\"\n"
                        . "   Is now: \"" . $colset->getRowClasses() . "\""
                    );
                }

                $colsetDefinitions[$identifier] = $colset;
            }
        }

        return $colsetDefinitions;
    }

    /**
     * @param array $colsConfig
     * @return array<string, BreakpointDTO>
     */
    protected function createBreakpointsFromArray(array $colsConfig): array
    {
        /** @var array<string, BreakpointDTO> $breakpoints */
        $breakpoints = [];

        $colIndex = 0;
        foreach ($colsConfig as $singleCol)
        {
            if (\count($singleCol) < 1) {
                continue;
            }

            $classNames = \explode(' ', \preg_replace('/\s+/i', ' ', $singleCol[0]));
            $colClasses = ClassName::list($classNames, $customClasses);
            $insideClass = $singleCol[1] ?? 'inside';

            foreach ($colClasses as $objColClass)
            {
                $strBreakpoint = $objColClass->breakpoint ?: Constants::UNSPECIFIC_PLACEHOLDER;

                $dto = ($breakpoints[$strBreakpoint] ??= new BreakpointDTO($strBreakpoint));

                if (!$dto->has($colIndex))
                {
                    $dto->set(
                        $colIndex,
                        ColumnDefinition::create()
                            ->setCustomClasses(\implode(' ', $customClasses))
                            ->setInsideClass($insideClass)
                    );
                }

                $col = $dto->get($colIndex);

                switch ($objColClass->type)
                {
                    case ClassName::CLASS_TYPE_COL:
                        $col->setSpan($objColClass->width);
                        break;
                    case ClassName::CLASS_TYPE_OFFSET:
                        $col->setOffset($objColClass->width);
                        break;
                    case ClassName::CLASS_TYPE_ORDER:
                        $col->setOrder($objColClass->width);
                        break;
                }
            }

            $colIndex++;
        }

        // make sure that all breakpoints have the same amount of columns

        $colCount = \max(\array_map('count', $breakpoints) ?: [0]);

        foreach ($breakpoints as $strBreakpoint => $dto)
        {
            if (\count($dto) >= $colCount) continue;

            for ($i = 0; $i < $colCount; $i++)
            {
                if (!$dto->has($i))
                {
                    $dto->set($i, ColumnDefinition::create());
                }
            }
        }

        // replace unspecific placeholders with the smallest breakpoint available or update missing data

        $this->applyUnspecificSizes($breakpoints);

        return $breakpoints;
    }

    /**
     * An unspecific css class is a class, that does not specify a breakpoint,
     *   e.g. col-12, col-offset-8, offset-4, order-3
     *
     * If no breakpoint is specified, these classes are applied equivalently to the columns with the smallest
     *   breakpoint which are already present.
     *
     * Unspecific classes do not overwrite their specific counterparts. New column definitions are created in case
     *   there is no equivalent column defined due to missing specific classes.
     *
     * @param array<string, BreakpointDTO> $breakpointDTOs
     */
    protected function applyUnspecificSizes(array &$breakpointDTOs): void
    {
        $unspecificBreakpointDTO = $breakpointDTOs[Constants::UNSPECIFIC_PLACEHOLDER] ?? null;
        unset($breakpointDTOs[Constants::UNSPECIFIC_PLACEHOLDER]);

        if (empty($unspecificBreakpointDTO)) {
            return;
        }

        $strSmallestBreakpoint = Constants::BREAKPOINTS[0];

        $specificBreakpointDTO = $breakpointDTOs[$strSmallestBreakpoint] ?? null;

        $breakpointDTOs[$strSmallestBreakpoint] = $unspecificBreakpointDTO;
        $unspecificBreakpointDTO->setBreakpoint($strSmallestBreakpoint);

        if ($specificBreakpointDTO === null)
            // the smallest available breakpoint has not yet been set,
            // therefore we just keep the unspecific classes assigned to the smallest breakpoint (xs)
        {
            return;
        }

        // otherwise, the smallest breakpoint is already defined with css classes incorporating specific sizes,
        // therefore we need to apply the unspecific size as a fallback to the smallest breakpoint

        foreach ($specificBreakpointDTO->getColumns() as $colIndex => $specificColDef)
        {
            $unspecificColDef = $breakpointDTOs[$strSmallestBreakpoint]->get($colIndex);

            if (null === $unspecificColDef) {
                $breakpointDTOs[$strSmallestBreakpoint]->set($colIndex, $specificColDef);
                continue;
            }

            if ($span = $specificColDef->getSpan())     $unspecificColDef->setSpan($span);
            if ($offset = $specificColDef->getOffset()) $unspecificColDef->setOffset($offset);
            if ($order = $specificColDef->getOrder())   $unspecificColDef->setOrder($order);
        }
    }
}