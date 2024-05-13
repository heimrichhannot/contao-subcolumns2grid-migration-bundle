<?php

namespace HeimrichHannot\Subcolumns2Grid\Config;

class CommandConfig
{
    protected bool $isDryRun = false;
    protected bool $skipConfirmations = false;

    public function isDryRun(): bool
    {
        return $this->isDryRun;
    }

    public function setDryRun(bool $isDryRun): self
    {
        $this->isDryRun = $isDryRun;
        return $this;
    }

    public function skipConfirmations(): bool
    {
        return $this->skipConfirmations;
    }

    public function setSkipConfirmations(bool $skipConfirmations): self
    {
        $this->skipConfirmations = $skipConfirmations;
        return $this;
    }

    public static function create(): self
    {
        return new self();
    }
}