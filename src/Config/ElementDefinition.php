<?php

namespace HeimrichHannot\Subcolumns2Grid\Config;

class ElementDefinition
{
    protected string $source;
    protected string $profile;
    protected string $name;
    protected int $pid;
    protected ?array $children;
    protected int $sorting;

    protected string $sc_gap;
    protected string $sc_gap_default;
    protected bool $sc_equalize;
    protected string $sc_color;
}