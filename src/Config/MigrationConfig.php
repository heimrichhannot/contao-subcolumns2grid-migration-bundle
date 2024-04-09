<?php

namespace HeimrichHannot\Subcolumns2Grid\Config;

class MigrationConfig
{
    public const FETCH_DB = 2 ** 9 + 1;  // 513
    public const FETCH_CONFIG = 2 ** 9 + 2;  // 514
    protected const FETCH = [
        self::FETCH_DB,
        self::FETCH_CONFIG,
    ];

    public const FROM_SUBCOLUMNS_MODULE = 2 ** 8 + 1;  // 257
    public const FROM_SUBCOLUMNS_BOOTSTRAP_BUNDLE = 2 ** 8 + 2;  // 258
    protected const FROM = [
        self::FROM_SUBCOLUMNS_MODULE,
        self::FROM_SUBCOLUMNS_BOOTSTRAP_BUNDLE,
    ];

    protected array $definitions = [];
    /** @var int[] */
    protected array $fetch = [];
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

    public function getFetch(): array
    {
        return $this->fetch;
    }

    public function addFetch(int $fetch): self
    {
        if (empty($this->fetch[$fetch]))
        {
            $this->checkArgument($fetch, self::FETCH, 'fetch');
            $this->fetch[$fetch] = $fetch;
        }
        return $this;
    }

    public function removeFetch(int $fetch): self
    {
        unset($this->fetch[$fetch]);
        return $this;
    }

    public function hasFetch(int $fetch): bool
    {
        return !empty($this->fetch[$fetch]);
    }

    public function hasAnyFetch(): bool
    {
        return !empty($this->fetch);
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