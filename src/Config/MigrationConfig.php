<?php

namespace HeimrichHannot\Subcolumns2Grid\Config;

class MigrationConfig
{
    public const SOURCE_DB = 2 ** 9 + 1;  // 513
    public const SOURCE_GLOBALS = 2 ** 9 + 2;  // 514
    protected const SOURCE = [
        self::SOURCE_DB,
        self::SOURCE_GLOBALS,
    ];

    public const FROM_SUBCOLUMNS_MODULE = 2 ** 8 + 1;  // 257
    public const FROM_SUBCOLUMNS_BOOTSTRAP_BUNDLE = 2 ** 8 + 2;  // 258
    protected const FROM = [
        self::FROM_SUBCOLUMNS_MODULE,
        self::FROM_SUBCOLUMNS_BOOTSTRAP_BUNDLE,
    ];

    /** @var int[] */
    protected array $sources = [];
    protected ?int $from = null;
    protected int $gridVersion;
    protected int $parentThemeId;
    /** @var ColSetDefinition[] */
    protected array $globalSubcolumnDefinitions = [];
    protected array $migratedIdentifiers = [];

    public function getSources(): array
    {
        return $this->sources;
    }

    public function addSource(int $source): self
    {
        if (empty($this->sources[$source]))
        {
            $this->checkArgument($source, self::SOURCE, 'fetch');
            $this->sources[$source] = $source;
        }
        return $this;
    }

    public function removeSource(int $source): self
    {
        unset($this->sources[$source]);
        return $this;
    }

    public function hasSource(int $source): bool
    {
        return !empty($this->sources[$source]);
    }

    public function hasAnySource(): bool
    {
        return !empty($this->sources);
    }

    public function getFrom(): int
    {
        return $this->from;
    }

    public function setFrom(int $from): self
    {
        $this->checkArgument($from, self::FROM, 'from');
        $this->from = $from;
        return $this;
    }

    public function getGridVersion(): ?int
    {
        return $this->gridVersion ?? null;
    }

    public function setGridVersion(int $gridVersion): self
    {
        if (!\in_array($gridVersion, [2, 3]))
        {
            throw new \InvalidArgumentException('Invalid grid version.');
        }
        $this->gridVersion = $gridVersion;
        return $this;
    }

    public function getParentThemeId(): ?int
    {
        return $this->parentThemeId ?? null;
    }

    public function setParentThemeId(int $parentThemeId): self
    {
        $this->parentThemeId = $parentThemeId;
        return $this;
    }

    public function getGlobalSubcolumnDefinitions(): array
    {
        return $this->globalSubcolumnDefinitions;
    }

    /**
     * @param array<ColSetDefinition> $globalSubcolumns
     * @return $this
     */
    public function setGlobalSubcolumnDefinitions(array $globalSubcolumns): self
    {
        $this->globalSubcolumnDefinitions = $globalSubcolumns;
        return $this;
    }

    public function getMigratedIdentifiers(): array
    {
        return $this->migratedIdentifiers;
    }

    public function setMigratedIdentifiers(array $migratedIdentifiers): self
    {
        $this->migratedIdentifiers = $migratedIdentifiers;
        return $this;
    }

    protected function checkArgument(int $argument, array $validValues, string $name = 'argument'): void
    {
        if (!in_array($argument, $validValues))
        {
            throw new \InvalidArgumentException("Invalid $name value.");
        }
    }
}