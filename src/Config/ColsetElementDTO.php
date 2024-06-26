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
        'pid' => 'pid',
        'type' => 'type',
        'customTpl' => 'customTpl',
        'scChildren' => 'sc_childs',
        'scParent' => 'sc_parent',
        'scType' => 'sc_type',
        'scName' => 'sc_name',
        'scColumnset' => 'sc_columnset',
        'scOrder' => 'sc_sortid',
        'scColumnsetId' => 'columnset_id',
        'scAddContainer' => 'addContainer',
    ];

    protected ?int $id;
    protected ?int $pid;
    protected ?string $type;
    protected ?string $scChildren = null;
    protected ?int $scParent = null;
    protected ?string $scType = null;
    protected ?string $scName = null;
    protected ?int $scOrder = null;
    protected ?string $identifier = null;
    protected ?int $scColumnsetId = null;
    protected bool $scAddContainer = false;
    protected string $customTpl = "";
    protected array $columnsMap = self::DEFAULT_COLUMNS_MAP;
    protected string $table = 'tl_content';
    protected ?ColsetElementDTO $startDTO = null;

    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function getPid(): int
    {
        return $this->pid;
    }

    public function setPid(int $pid): self
    {
        $this->pid = $pid;
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function getScChildren(): ?string
    {
        return $this->scChildren;
    }

    public function setScChildren(string $scChildren): self
    {
        $this->scChildren = $scChildren;
        return $this;
    }

    public function getScParent(): ?int
    {
        return $this->scParent;
    }

    public function setScParent(int $scParent): self
    {
        $this->scParent = $scParent;
        return $this;
    }

    public function getScType(): ?string
    {
        return $this->scType;
    }

    public function setScType(string $scType): self
    {
        $this->scType = $scType;
        return $this;
    }

    public function getScName(): ?string
    {
        return $this->scName;
    }

    public function setScName(string $scName): self
    {
        $this->scName = $scName;
        return $this;
    }

    public function getScOrder(): ?int
    {
        return $this->scOrder;
    }

    public function setScOrder($scOrder): self
    {
        $this->scOrder = $scOrder;
        return $this;
    }

    public function getIdentifier(): ?string
    {
        return $this->identifier;
    }

    public function setIdentifier(string $identifier): self
    {
        $this->identifier = $identifier;
        return $this;
    }

    public function getScColumnsetId(): ?int
    {
        return $this->scColumnsetId;
    }

    public function setScColumnsetId($scColumnsetId): self
    {
        $this->scColumnsetId = empty($scColumnsetId) ? null : (int) $scColumnsetId;
        return $this;
    }

    public function getScAddContainer(): bool
    {
        return $this->scAddContainer;
    }

    public function setScAddContainer(bool $scAddContainer): self
    {
        $this->scAddContainer = $scAddContainer;
        return $this;
    }

    public function getCustomTpl(): string
    {
        return $this->customTpl;
    }

    public function setCustomTpl(string $customTpl): self
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

    public function getTable(): ?string
    {
        return $this->table ?? null;
    }

    public function setTable(string $table): self
    {
        $this->table = $table;
        return $this;
    }

    public function getStartDTO(): ?ColsetElementDTO
    {
        return $this->startDTO;
    }

    public function setStartDTO(ColsetElementDTO $startDTO): self
    {
        $this->startDTO = $startDTO;
        return $this;
    }

    public function getMappedValue(array $container, string $key): ?string
    {
        return $container[$this->columnsMap[$key]] ?? null;
    }

    public function setRow(array $row): self
    {
        $this->id = $this->getMappedValue($row, 'id') ?? $this->id ?? null;
        $this->pid = $this->getMappedValue($row, 'pid') ?? $this->pid ?? null;
        $this->type = $this->getMappedValue($row, 'type') ?? $this->type ?? null;
        $this->customTpl = $this->getMappedValue($row, 'customTpl') ?? $this->customTpl ?? null;
        $this->scChildren = $this->getMappedValue($row, 'scChildren') ?? $this->scChildren ?? null;
        $this->scParent = $this->getMappedValue($row, 'scParent') ?? $this->scParent ?? null;
        $this->scType = $this->getMappedValue($row, 'scType') ?? $this->scType ?? null;
        $this->scName = $this->getMappedValue($row, 'scName') ?? $this->scName ?? null;
        $this->scOrder = $this->getMappedValue($row, 'scOrder') ?? $this->scOrder ?? null;
        $this->identifier = $this->getMappedValue($row, 'scColumnset') ?? $this->identifier ?? null;
        $this->setScColumnsetId($this->getMappedValue($row, 'scColumnsetId') ?? $this->scColumnsetId ?? null);
        $this->scAddContainer = $this->getMappedValue($row, 'scAddContainer') ?? $this->scAddContainer;
        return $this;
    }

    public static function fromRow(array $row, ?array $columnsMap = null): self
    {
        return (new self())->updateColumnsMap($columnsMap)->setRow($row);
    }

    public function isValid(): bool
    {
        return (
            $this->id
            and $this->pid
            and $this->type
            and $this->scParent
            and $this->scType || $this->identifier
        );
    }
}