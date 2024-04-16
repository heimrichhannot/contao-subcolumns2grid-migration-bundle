<?php

namespace HeimrichHannot\Subcolumns2Grid\Config;

use Exception;

class ColsetDefinition implements \Countable
{
    protected ?int $count;
    protected ?string $identifier;
    protected ?string $title;
    protected ?bool $useOutside;
    protected ?string $outsideClass;
    protected ?bool $useInside;
    protected ?string $insideClass = 'inside';
    protected ?bool $published = false;
    protected ?string $cssID;
    protected ?string $rowClasses;
    /** @var array<string, array<int, ColumnDefinition>> */
    protected array $sizeDefinitions = [];

    protected ?int $migratedId = null;

    public function getIdentifier(): ?string
    {
        return $this->identifier;
    }

    public function setIdentifier(string $identifier): self
    {
        $this->identifier = $identifier;
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function getUseOutside(): ?bool
    {
        return $this->useOutside;
    }

    public function setUseOutside(bool $useOutside): self
    {
        $this->useOutside = $useOutside;
        return $this;
    }

    public function getOutsideClass(): ?string
    {
        return $this->outsideClass;
    }

    public function setOutsideClass(string $outsideClass): self
    {
        $this->outsideClass = $outsideClass;
        return $this;
    }

    public function getUseInside(): ?bool
    {
        return $this->useInside;
    }

    public function setUseInside(bool $useInside): self
    {
        $this->useInside = $useInside;
        return $this;
    }

    public function getInsideClass(): ?string
    {
        return $this->insideClass;
    }

    public function setInsideClass(string $insideClass): self
    {
        $this->insideClass = $insideClass;
        return $this;
    }

    public function getPublished(): ?bool
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
        return \array_keys($this->sizeDefinitions);
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
                throw new \InvalidArgumentException(sprintf('Invalid size definition. Expected array, %s given.', \gettype($sizeWrapper)));
            }
            foreach ($sizeWrapper as $colNumber => $size) {
                if (!\is_int($colNumber)) {
                    throw new \InvalidArgumentException(sprintf('Invalid size definition. Key must be an integer, %s given.', gettype($colNumber)));
                }
                if (!$size instanceof ColumnDefinition) {
                    throw new \InvalidArgumentException(sprintf('Invalid size definition. Value must be an instance of ColumnDefinition, %s given.', gettype($size)));
                }
            }
        }
        $this->sizeDefinitions = $sizeDefinitions;
        return $this;
    }

    public function getMigratedId(): ?int
    {
        return $this->migratedId;
    }

    public function setMigratedId(?int $migratedId): self
    {
        $this->migratedId = $migratedId;
        return $this;
    }

    public function setRowClasses(string $rowClasses): self
    {
        if (\strlen($rowClasses) <= 64) {
            $this->rowClasses = $rowClasses;
            return $this;
        }

        $arrClasses = \explode(' ', $rowClasses);
        $arrClasses = \array_unique($arrClasses);
        while (\strlen($rowClasses = \implode(' ', $arrClasses)) > 64) {
            \array_pop($arrClasses);
        }

        $this->rowClasses = $rowClasses;
        return $this;
    }

    public function getRowClasses(): ?string
    {
        return $this->rowClasses ?? null;
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
                foreach (\range($incrementIndices,  $firstKey + $incrementIndices - 1) as $index) {
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