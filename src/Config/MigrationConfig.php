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

    protected array $definitions = [];
    /** @var int[] */
    protected array $sources = [];
    protected ?int $from = null;

    public function getDefinitions(): array
    {
        return $this->definitions;
    }

    /** @param ColSetDefinition[] $definitions */
    public function setDefinitions(array $definitions): self
    {
        $this->definitions = $definitions;
        return $this;
    }

    public function removeDefinitions(array $definitions): self
    {
        $this->definitions = [];
        return $this;
    }

    public function addDefinition(ColSetDefinition ...$definitions): self
    {
        foreach ($definitions as $definition) {
            if (in_array($definition, $this->definitions)) {
                continue;
            }
            $this->definitions[] = $definition;
        }
        return $this;
    }

    public function removeDefinition(array $definition): self
    {
        $key = array_search($definition, $this->definitions);
        if ($key !== false)
        {
            unset($this->definitions[$key]);
        }
        return $this;
    }

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

    protected function checkArgument(int $argument, array $validValues, string $name = 'argument'): void
    {
        if (!in_array($argument, $validValues))
        {
            throw new \InvalidArgumentException("Invalid $name value.");
        }
    }
}