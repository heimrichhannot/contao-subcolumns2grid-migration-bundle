<?php

namespace HeimrichHannot\Subcolumns2Grid\Config;

class ColSetDefinition implements \Countable
{
    protected int $count;
    protected string $name;
    protected bool $useOutside;
    protected string $outsideClass;
    protected bool $useInside;
    protected string $insideClass = 'inside';
    protected bool $published;
    protected string $cssID;
    protected array $columnSizes = [];

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
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
        return array_keys($this->columnSizes);
    }

    public function addSize(string $breakpoint, ColSizeDefinition $size): self
    {
        $this->columnSizes[$breakpoint] = $size;
        return $this;
    }

    public function getColumnSizes(): array
    {
        return $this->columnSizes;
    }

    public function setColumnSizes(array $sizes): self
    {
        $this->columnSizes = $sizes;
        return $this;
    }

    public function count(): int
    {
        return $this->count;
    }

    public static function create(): self
    {
        return new self();
    }
}