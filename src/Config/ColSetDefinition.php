<?php

namespace HeimrichHannot\Subcolumns2Grid\Config;

use Exception;

class ColSetDefinition implements \Countable
{
    protected int $count;
    protected string $identifier;
    protected string $title;
    protected bool $useOutside;
    protected string $outsideClass;
    protected bool $useInside;
    protected string $insideClass = 'inside';
    protected bool $published;
    protected string $cssID;
    /** @var array<string, array<int, ColumnDefinition>> */
    protected array $sizeDefinitions = [];

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function setIdentifier(string $identifier): self
    {
        $this->identifier = $identifier;
        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function getUseOutside(): bool
    {
        return $this->useOutside;
    }

    public function setUseOutside(bool $useOutside): self
    {
        $this->useOutside = $useOutside;
        return $this;
    }

    public function getOutsideClass(): string
    {
        return $this->outsideClass;
    }

    public function setOutsideClass(string $outsideClass): self
    {
        $this->outsideClass = $outsideClass;
        return $this;
    }

    public function getUseInside(): bool
    {
        return $this->useInside;
    }

    public function setUseInside(bool $useInside): self
    {
        $this->useInside = $useInside;
        return $this;
    }

    public function getInsideClass(): string
    {
        return $this->insideClass;
    }

    public function setInsideClass(string $insideClass): self
    {
        $this->insideClass = $insideClass;
        return $this;
    }

    public function getPublished(): bool
    {
        return $this->published;
    }

    public function setPublished(bool $published): self
    {
        $this->published = $published;
        return $this;
    }

    public function getSizes(): array
    {
        return array_keys($this->sizeDefinitions);
    }

    public function addSize(string $breakpoint, ColumnDefinition $size): self
    {
        $this->sizeDefinitions[$breakpoint] = $size;
        return $this;
    }

    public function getSizeDefinitions(): array
    {
        return $this->sizeDefinitions;
    }

    /**
     * @param array<string, array<int, ColumnDefinition>> $sizeDefinitions
     * @return $this
     */
    public function setSizeDefinitions(array $sizeDefinitions): self
    {
        foreach ($sizeDefinitions as $sizeWrapper) {
            if (!\is_array($sizeWrapper)) {
                throw new \InvalidArgumentException('Invalid size definition.');
            }
            foreach ($sizeWrapper as $colNumber => $size) {
                if (!\is_integer($colNumber) || !$size instanceof ColumnDefinition) {
                    throw new \InvalidArgumentException('Invalid size definition.');
                }
            }
        }
        $this->sizeDefinitions = $sizeDefinitions;
        return $this;
    }

    public function count(): int
    {
        return $this->count;
    }

    public function asArray(int $incrementIndices = 0): array
    {
        $sizes = [];
        foreach ($this->sizeDefinitions as $breakpoint => $sizeWrapper)
        {
            if (!isset($sizes[$breakpoint])) {
                $sizes[$breakpoint] = [];
            }

            \ksort($sizeWrapper, \SORT_NUMERIC);
            $firstKey = \array_key_first($sizeWrapper);
            if ($firstKey > 0) {
                foreach (range($incrementIndices,  $firstKey + $incrementIndices - 1) as $index) {
                    $sizes[$breakpoint][$index] = ColumnDefinition::create()->asArray();
                }
            }

            foreach ($sizeWrapper as $index => $size) {
                $sizes[$breakpoint][$index + $incrementIndices] = $size->asArray();
            }
        }
        return $sizes;
    }

    public static function create(): self
    {
        return new self();
    }
}