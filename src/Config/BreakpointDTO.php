<?php

namespace HeimrichHannot\Subcolumns2Grid\Config;

use Countable;

class BreakpointDTO implements Countable
{
    protected ?string $breakpoint;
    /** @var ColumnDefinition[] $columns */
    protected array $columns = [];

    public function __construct(string $breakpoint) {
        $this->breakpoint = $breakpoint;
    }

    public function getBreakpoint(): ?string
    {
        return $this->breakpoint;
    }

    public function setBreakpoint(?string $breakpoint): self
    {
        $this->breakpoint = $breakpoint;
        return $this;
    }

    /** @return ColumnDefinition[] */
    public function getColumns(): array
    {
        return $this->columns;
    }

    public function setColumns(array $columns): self
    {
        foreach ($columns as $colNumber => $colDef) {
            if (!\is_int($colNumber)) {
                throw new \InvalidArgumentException(sprintf('Invalid size definition. Key must be an integer, %s given.', gettype($colNumber)));
            }
            if (!$colDef instanceof ColumnDefinition) {
                throw new \InvalidArgumentException(sprintf('Invalid size definition. Value must be an instance of ColumnDefinition, %s given.', gettype($colDef)));
            }
        }
        $this->columns = $columns;
        return $this;
    }

    public function getInsideClasses(): array
    {
        $insideClasses = [];
        \array_walk($this->columns, static function (ColumnDefinition $v) use (&$insideClasses) {
            $insideClasses[] = $v->getInsideClass();
        });
        return \array_unique($insideClasses);
    }

    public function set(int $index, ColumnDefinition $colsetDefinition): self
    {
        $this->columns[$index] = $colsetDefinition;
        return $this;
    }

    public function get(int $colIndex): ?ColumnDefinition
    {
        return $this->columns[$colIndex] ?? null;
    }

    public function has(int $colIndex): bool
    {
        return isset($this->columns[$colIndex]) && $this->columns[$colIndex] !== null;
    }

    public function count(): int
    {
        return \count($this->columns);
    }
}