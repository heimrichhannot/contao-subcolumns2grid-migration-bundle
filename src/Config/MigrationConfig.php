<?php

namespace HeimrichHannot\Subcolumns2Grid\Config;

class MigrationConfig
{
    public const FROM_SUBCOLUMNS_MODULE = 1;
    public const FROM_SUBCOLUMNS_BOOTSTRAP_BUNDLE = 2;
    protected ?int $from = null;

    public function getFrom(): int
    {
        return $this->from;
    }

    public function setFrom(int $from): static
    {
        $this->from = $from;
        return $this;
    }
}