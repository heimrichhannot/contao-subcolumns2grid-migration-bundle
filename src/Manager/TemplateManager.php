<?php

namespace HeimrichHannot\Subcolumns2Grid\Manager;

use HeimrichHannot\Subcolumns2Grid\Config\ColsetDefinition;
use HeimrichHannot\Subcolumns2Grid\Config\ColsetElementDTO;
use HeimrichHannot\Subcolumns2Grid\Config\MigrationConfig;
use HeimrichHannot\Subcolumns2Grid\Util\Constants;

class TemplateManager extends AbstractManager
{
    protected array $templateCache = [];

    /**
     * Copies the templates from this bundle's contao/templates to the project directory.
     *
     * @param array<ColsetDefinition> $colSets
     * @return array The prepared files.
     * @throws \Exception
     */
    public function prepareTemplates(array $colSets): array
    {
        $source = $this->getBundlePath() . '/contao/templates';
        $target = $this->parameterBag->get('kernel.project_dir') . '/contao/templates/elements';

        if (!\is_dir($target) && !\mkdir($target, 0777, true))
        {
            throw new \Exception("Could not create template target directory: \"$target\"");
        }

        $wrappedClasses = [0 => []];
        foreach ($colSets as $colSet)
        {
            $outsideClass = $colSet->getUseOutside() ? $colSet->getOutsideClass() ?: 0 : 0;
            $wrappedClasses[$outsideClass] ??= [];

            if ($colSet->getUseInside() && $insideClass = $colSet->getInsideClass())
            {
                $wrappedClasses[$outsideClass][] = $insideClass;
            }
            else
            {
                $wrappedClasses[$outsideClass] = \array_merge(
                    $wrappedClasses[$outsideClass], $colSet->getInsideClasses()
                );
            }
        }

        $copied = [];
        foreach ($wrappedClasses as $outerClass => $innerClasses)
        {
            if ($outerClass === 0) {
                $outerClass = null;
            }

            foreach (\array_unique($innerClasses) as $innerClass)
            {
                $copied = \array_merge(
                    $copied,
                    $this->copyTemplates($source, $target, $outerClass, $innerClass)
                );
            }
        }

        return $copied;
    }

    /**
     * @param string $sourceDir The source directory.
     * @param string $targetDir The target directory.
     * @param string|null $outerClass The outer class name.
     * @param string|null $innerClass The inner class name.
     * @param array $replace The keys to replace in the template content and source file name.
     * @return array The copied files.
     */
    protected function copyTemplates(
        string $sourceDir,
        string $targetDir,
        string $outerClass = null,
        string $innerClass = null,
        array  $replace = []
    ): array {
        if (!\is_dir($sourceDir)) {
            throw new \RuntimeException('Template source directory not found. Please reinstall the bundle.');
        }

        if (!\is_dir($targetDir)) {
            throw new \RuntimeException('Template target directory not found.');
        }

        $rx = "/ce_bs_gridS(tart|eparator|top)_.+/";

        $replace = \array_merge([
            '{outerClass}' => $outerClass,
            '{innerClass}' => $innerClass,
        ], $replace);

        $search = \array_keys($replace);
        $replace = \array_values($replace);
        $replaceFileName = \array_map([self::class, 'classNamesToFileNamePart'], $replace);

        $copied = [];

        foreach (\scandir($sourceDir) as $file)
        {
            if ($file === '.' || $file === '..') continue;
            if (!\preg_match($rx, $file)) continue;
            if (!$outerClass && \strpos($file, '{outerClass}') !== false) continue;
            if (!$innerClass && \strpos($file, '{innerClass}') !== false) continue;

            $destination = $targetDir . \DIRECTORY_SEPARATOR . \str_replace($search, $replaceFileName, $file);

            if (\file_exists($destination)) continue;

            $sourceFile = $sourceDir . \DIRECTORY_SEPARATOR . $file;

            if ($this->copyTemplateFile($sourceFile, $destination, $file, $search, $replace) === false)
            {
                throw new \RuntimeException('Could not copy template file.');
            }

            $copied[] = $destination;
        }

        return $copied;
    }

    /**
     * @param string $source The source file path.
     * @param string $target The target file path.
     * @param string $cacheKey The cache key for the template content.
     * @param array $search The keys to replace in the template content.
     * @param array $replace The values to replace the keys with.
     * @return false|int The number of bytes written to the file, or false on failure.
     */
    protected function copyTemplateFile(
        string $source,
        string $target,
        string $cacheKey,
        array  $search,
        array  $replace
    ) {
        if (\in_array($cacheKey, \array_keys($this->templateCache), true))
        {
            $content = $this->templateCache[$cacheKey];
        }
        else
        {
            $content = \file_get_contents($source);
            $this->templateCache[$cacheKey] = $content;
        }

        return \file_put_contents($target, \str_replace($search, $replace, $content));
    }

    public function findColumnTemplate(MigrationConfig $config, ColsetElementDTO $ce): ?string
    {
        $def = $config->getSubcolumnDefinition($ce->getIdentifier());

        if (!$def) {
            throw new \DomainException("No sub-column definition found for identifier \"{$ce->getIdentifier()}\". "
                . "One or more database entries in tl_content or tl_form_field might be corrupt.");
        }

        $outerClass = $def->getUseOutside() ? $def->getOutsideClass() : null;

        $innerClass = null;
        if ($def->getUseInside())
        {
            $breakpoints = $def->getBreakpoints();

            foreach ($breakpoints as $breakpoint)
            {
                if ($breakpoint->has($ce->getScOrder()))
                {
                    $innerClass = $breakpoint->get($ce->getScOrder())->getInsideClass();
                }
                elseif ($ce->getType() === Constants::CE_TYPE_COLSET_END && $breakpoint->count())
                {
                    $innerClass = $breakpoint->last()->getInsideClass() ?? $breakpoint->first()->getInsideClass();
                }
                else continue;

                if ($innerClass !== null) break;
            }
        }

        $type = Constants::RENAME_TYPE[$ce->getType()] ?? $ce->getType();

        $vars = [];
        if ($outerClass) {
            $outerHash = \substr(\md5($outerClass), 0, 4);
            $outerClassPart = self::classNamesToFileNamePart($outerClass);
            $outer = "outer_[$outerClassPart]($outerHash)";
            $vars[] = $outer;
        }

        if ($innerClass) {
            $innerHash = \substr(\md5($innerClass), 0, 4);
            $innerClassPart = self::classNamesToFileNamePart($innerClass);
            $inner = "inner_[$innerClassPart]($innerHash)";
            $vars[] = $inner;
        }

        return "ce_{$type}_" . \implode('_', $vars);
    }

    public static function classNamesToFileNamePart(?string $classes): string
    {
        return (string) \preg_replace('/[^a-z0-9-_ ]/i', '', (string) $classes);
    }

    protected function getBundlePath(): string
    {
        return $this->kernel->getBundle('HeimrichHannotSubcolumns2GridMigrationBundle')->getPath();
    }
}