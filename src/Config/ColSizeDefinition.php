<?php

namespace HeimrichHannot\Subcolumns2Grid\Config;

class ColSizeDefinition
{
    protected ?string $span = null;
    protected ?string $offset = null;
    protected ?string $order = null;
    protected ?string $verticalAlign = null;
    protected string $customClasses = '';

    public function getSpan(): string
    {
        return $this->span;
    }

    public function setSpan(?string $span): self
    {
        $this->span = $span;
        return $this;
    }

    public function getOffset(): ?string
    {
        return $this->offset;
    }

    public function setOffset(?string $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    public function getOrder(): ?string
    {
        return $this->order;
    }

    public function setOrder(?string $order): self
    {
        $this->order = $order;
        return $this;
    }

    public function getVerticalAlign(): string
    {
        return $this->verticalAlign;
    }

    public function setVerticalAlign(string $verticalAlign): self
    {
        if (!in_array($verticalAlign, ['start', 'center', 'end'])) {
            throw new \InvalidArgumentException('Invalid vertical align value.');
        }
        $this->verticalAlign = $verticalAlign;
        return $this;
    }

    public function getCustomClasses(): string
    {
        return $this->customClasses;
    }

    public function setCustomClasses(string $customClasses): self
    {
        $this->customClasses = $customClasses;
        return $this;
    }

    public static function create(): self
    {
        return new self();
    }
}