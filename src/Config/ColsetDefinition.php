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
    protected ?bool $published = false;
    protected ?string $cssID;
    protected ?string $rowClasses;
    /**
     * @var array<string, BreakpointDTO>
     */
    protected array $breakpoints = [];

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

    public function getInsideClasses(): array
    {
        $insideClasses = [];
        foreach ($this->breakpoints as $breakpoint) {
            $insideClasses = \array_merge($insideClasses, $breakpoint->getInsideClasses());
        }
        return \array_unique($insideClasses);
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
        return \array_keys($this->breakpoints);
    }

    public function addSize(BreakpointDTO $dto): self
    {
        $this->breakpoints[$dto->getBreakpoint()] = $dto;
        return $this;
    }

    public function getBreakpoints(): array
    {
        return $this->breakpoints;
    }

    /**
     * @param BreakpointDTO[] $breakpoints
     * @return $this
     */
    public function setBreakpoints(array $breakpoints): self
    {
        $this->breakpoints = [];
        foreach ($breakpoints as $breakpoint) {
            if (!$breakpoint instanceof BreakpointDTO) {
                throw new \InvalidArgumentException(sprintf('Invalid size definition. Expected BreakpointDTO, %s given.', \gettype($breakpoint)));
            }
            $this->breakpoints[$breakpoint->getBreakpoint()] = $breakpoint;
        }
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
        foreach ($this->breakpoints as $breakpoint => $dto)
        {
            if (!isset($sizes[$breakpoint])) {
                $sizes[$breakpoint] = [];
            }

            $cols = $dto->getColumns();
            \ksort($cols, \SORT_NUMERIC);
            $firstKey = \array_key_first($cols);
            if ($firstKey > 0) {
                foreach (\range($incrementIndices,  $firstKey + $incrementIndices - 1) as $index) {
                    $sizes[$breakpoint][$index] = ColumnDefinition::create()->asArray();
                }
            }

            foreach ($dto as $index => $size) {
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