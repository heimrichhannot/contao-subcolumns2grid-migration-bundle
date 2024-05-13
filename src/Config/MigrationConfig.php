<?php

namespace HeimrichHannot\Subcolumns2Grid\Config;

use HeimrichHannot\Subcolumns2Grid\Exception\ConfigException;

class MigrationConfig
{
    public const SOURCE_DB = 2 ** 9 + 1;  // 513
    public const SOURCE_GLOBALS = 2 ** 9 + 2;  // 514
    protected const SOURCE = [
        self::SOURCE_DB,
        self::SOURCE_GLOBALS,
    ];
    public const NAME_SOURCES = [
        self::SOURCE_DB => 'Database',
        self::SOURCE_GLOBALS => 'Globals',
    ];

    public const FROM_SUBCOLUMNS_MODULE = 2 ** 8 + 1;  // 257
    public const FROM_SUBCOLUMNS_BOOTSTRAP_BUNDLE = 2 ** 8 + 2;  // 258
    public const FROM = [
        self::FROM_SUBCOLUMNS_MODULE,
        self::FROM_SUBCOLUMNS_BOOTSTRAP_BUNDLE,
    ];

    /** @var int[] */
    protected array $sources = [];
    protected ?int $from = null;
    protected ?string $profile = null;
    protected int $gridVersion;
    protected int $parentThemeId;
    /** @var array<string, ColsetDefinition> */
    protected array $globalSubcolumnDefinitions = [];
    protected array $migratedIdentifiers = [];
    /** @var string[] $notes */
    protected array $notes = [];

    public function getSources(): array
    {
        return $this->sources;
    }

    public function getSourcesNamed(): array
    {
        return \array_map(static function ($source) {
            return static::NAME_SOURCES[$source] ?? $source;
        }, $this->getSources());
    }

    /**
     * @throws ConfigException
     * @internal Use getSourcesNamed() instead and filter invalid sources manually.
     */
    public function getValidSourcesNamed(): array
    {
        $names = $this->getSourcesNamed();
        foreach ($names as $name) {
            if (!\is_string($name)) {
                $source = @\strval($name) ?? 'unknown';
                throw new ConfigException("Invalid source found: \"$source\".");
            }
        }
        return $names;
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

    public function hasFrom(): bool
    {
        return !empty($this->from);
    }

    public function setFrom(int $from): self
    {
        $this->checkArgument($from, self::FROM, 'from');
        $this->from = $from;
        return $this;
    }

    public function getProfile(): ?string
    {
        return $this->profile;
    }

    public function setProfile(string $profile): self
    {
        if (\strpos($profile, 'yaml') !== false) {
            throw new \InvalidArgumentException('YAML profiles are not supported.'
                . ' Please check your profile option argument or contao config.');
        }

        $profile = \str_replace('boostrap', 'bootstrap', $profile);  // fix legacy typo

        if ($profile === 'bootstrap') {
            $profile = 'bootstrap3';
        }

        $this->profile = $profile;
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

    /** @return ColumnDefinition[] */
    public function getGlobalSubcolumnDefinitions(): array
    {
        return $this->globalSubcolumnDefinitions;
    }

    public function getSubcolumnDefinition(string $identifier): ?ColsetDefinition
    {
        # todo: extend with db sets
        return $this->globalSubcolumnDefinitions[$identifier] ?? null;
    }

    /**
     * @param array<string, ColsetDefinition> $globalSubcolumns
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

    public function addMigratedIdentifiers(string ...$migratedIdentifiers): self
    {
        $this->migratedIdentifiers = \array_merge($this->migratedIdentifiers, $migratedIdentifiers);
        return $this;
    }

    public function getNotes(): array
    {
        return $this->notes;
    }

    public function addNotes(string ...$notes): self
    {
        $this->notes = \array_merge($this->notes, $notes);
        return $this;
    }

    public function addNote(string $note): self
    {
        $this->notes[] = $note;
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