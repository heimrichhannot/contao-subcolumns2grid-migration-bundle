<?php

namespace HeimrichHannot\Subcolumns2Grid\Config;

class ColsetElementDTO
{
    /**
     * @var string[]
     * @internal Use the getter and setter methods to access the properties.
     *   The map should not be used in userland code.
     */
    public const DEFAULT_COLUMNS_MAP = [
        'id' => 'id',
        'type' => 'type',
        'customTpl' => 'customTpl',
        'scChildren' => 'sc_childs',
        'scParent' => 'sc_parent',
        'scType' => 'sc_type',
        'scName' => 'sc_name',
        'scColumnset' => 'sc_columnset',
    ];

    protected ?int $id;
    protected ?string $type;
    protected ?string $scChildren;
    protected ?int $scParent;
    protected ?string $scType;
    protected ?string $scName;
    protected ?string $identifier;
    protected ?string $customTpl = null;
    protected array $columnsMap = self::DEFAULT_COLUMNS_MAP;
    protected string $table = 'tl_content';

    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): ColsetElementDTO
    {
        $this->id = $id;
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): ColsetElementDTO
    {
        $this->type = $type;
        return $this;
    }

    public function getScChildren(): string
    {
        return $this->scChildren;
    }

    public function setScChildren(string $scChildren): ColsetElementDTO
    {
        $this->scChildren = $scChildren;
        return $this;
    }

    public function getScParent(): int
    {
        return $this->scParent;
    }

    public function setScParent(int $scParent): ColsetElementDTO
    {
        $this->scParent = $scParent;
        return $this;
    }

    public function getScType(): string
    {
        return $this->scType;
    }

    public function setScType(string $scType): ColsetElementDTO
    {
        $this->scType = $scType;
        return $this;
    }

    public function getScName(): string
    {
        return $this->scName;
    }

    public function setScName(string $scName): ColsetElementDTO
    {
        $this->scName = $scName;
        return $this;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function setIdentifier(string $identifier): ColsetElementDTO
    {
        $this->identifier = $identifier;
        return $this;
    }

    public function getCustomTpl(): string
    {
        return $this->customTpl;
    }

    public function setCustomTpl(string $customTpl): ColsetElementDTO
    {
        $this->customTpl = $customTpl;
        return $this;
    }

    public function getColumnsMap(): array
    {
        return $this->columnsMap;
    }

    public function updateColumnsMap(?array $columnsMap): self
    {
        if ($columnsMap !== null) {
            $this->columnsMap = \array_merge($this->columnsMap, $columnsMap);
        }
        return $this;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function setTable(string $table): self
    {
        $this->table = $table;
        return $this;
    }

    public function getMappedValue(array $container, string $key): ?string
    {
        return $container[$this->columnsMap[$key]] ?? null;
    }

    public function setRow(array $row): self
    {
        $this->id = $this->getMappedValue($row, 'id') ?? $this->id ?? null;
        $this->type = $this->getMappedValue($row, 'type') ?? $this->type ?? null;
        $this->scChildren = $this->getMappedValue($row, 'scChildren') ?? $this->scChildren ?? null;
        $this->scParent = $this->getMappedValue($row, 'scParent') ?? $this->scParent ?? null;
        $this->scType = $this->getMappedValue($row, 'scType') ?? $this->scType ?? null;
        $this->scName = $this->getMappedValue($row, 'scName') ?? $this->scName ?? null;
        $this->identifier = $this->getMappedValue($row, 'scColumnset') ?? $this->identifier ?? null;
        $this->customTpl = $this->getMappedValue($row, 'customTpl') ?? $this->customTpl ?? null;
        return $this;
    }

    public static function fromRow(array $row, ?array $columnsMap = null): self
    {
        return (new self())->updateColumnsMap($columnsMap)->setRow($row);
    }
}