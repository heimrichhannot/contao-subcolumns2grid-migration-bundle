<?php

namespace HeimrichHannot\Subcolumns2Grid\Config;

class ContentElementDTO
{
    protected ?int $id;
    protected ?string $type;
    protected ?string $sc_child;
    protected ?int $sc_parent;
    protected ?string $sc_type;
    protected ?string $sc_name;
    protected ?string $identifier;
    protected ?string $customTpl = null;

    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): ContentElementDTO
    {
        $this->id = $id;
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): ContentElementDTO
    {
        $this->type = $type;
        return $this;
    }

    public function getScChild(): string
    {
        return $this->sc_child;
    }

    public function setScChild(string $sc_child): ContentElementDTO
    {
        $this->sc_child = $sc_child;
        return $this;
    }

    public function getScParent(): int
    {
        return $this->sc_parent;
    }

    public function setScParent(int $sc_parent): ContentElementDTO
    {
        $this->sc_parent = $sc_parent;
        return $this;
    }

    public function getScType(): string
    {
        return $this->sc_type;
    }

    public function setScType(string $sc_type): ContentElementDTO
    {
        $this->sc_type = $sc_type;
        return $this;
    }

    public function getScName(): string
    {
        return $this->sc_name;
    }

    public function setScName(string $sc_name): ContentElementDTO
    {
        $this->sc_name = $sc_name;
        return $this;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function setIdentifier(string $identifier): ContentElementDTO
    {
        $this->identifier = $identifier;
        return $this;
    }

    public function getCustomTpl(): string
    {
        return $this->customTpl;
    }

    public function setCustomTpl(string $customTpl): ContentElementDTO
    {
        $this->customTpl = $customTpl;
        return $this;
    }

    public function setRow(array $row): ContentElementDTO
    {
        $this->id = $row['id'] ?? $this->id ?? null;
        $this->type = $row['type'] ?? $this->type ?? null;
        $this->sc_child = $row['sc_child'] ?? $this->sc_child ?? null;
        $this->sc_parent = $row['sc_parent'] ?? $this->sc_parent ?? null;
        $this->sc_type = $row['sc_type'] ?? $this->sc_type ?? null;
        $this->sc_name = $row['sc_name'] ?? $this->sc_name ?? null;
        $this->identifier = $row['sc_columnset'] ?? $this->identifier ?? null;
        $this->customTpl = $row['customTpl'] ?? $this->customTpl ?? null;
        return $this;
    }

    public static function fromRow(array $row): self
    {
        return (new static())->setRow($row);
    }
}