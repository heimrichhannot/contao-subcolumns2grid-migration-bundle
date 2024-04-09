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
    protected array $sizes = [];

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
        return array_keys($this->sizes);
    }

    public function addSize(ColSizeDefinition ...$sizes): self
    {
        foreach ($sizes as $size) {
            if (empty($size->getBreakpoint())) {
                throw new \InvalidArgumentException('ColSizeDefinition->breakpoint must not be empty.');
            }
            $this->sizes[$size->getBreakpoint()] = $size;
        }
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