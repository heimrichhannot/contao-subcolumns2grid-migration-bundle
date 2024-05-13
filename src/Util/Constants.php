<?php

namespace HeimrichHannot\Subcolumns2Grid\Util;

final class Constants
{
    public const CE_TYPE_COLSET_START = 'colsetStart';
    public const CE_TYPE_COLSET_PART = 'colsetPart';
    public const CE_TYPE_COLSET_END = 'colsetEnd';
    public const CE_TYPES = [
        self::CE_TYPE_COLSET_START,
        self::CE_TYPE_COLSET_PART,
        self::CE_TYPE_COLSET_END,
    ];

    public const FF_TYPE_FORMCOL_START = 'formcolstart';
    public const FF_TYPE_FORMCOL_PART = 'formcolpart';
    public const FF_TYPE_FORMCOL_END = 'formcolend';
    public const FF_TYPES = [
        self::FF_TYPE_FORMCOL_START,
        self::FF_TYPE_FORMCOL_PART,
        self::FF_TYPE_FORMCOL_END,
    ];

    public const BS_GRID_START_TYPE = 'bs_gridStart';
    public const BS_GRID_SEPARATOR_TYPE = 'bs_gridSeparator';
    public const BS_GRID_STOP_TYPE = 'bs_gridStop';
    public const RENAME_TYPE = [
        self::CE_TYPE_COLSET_START  => self::BS_GRID_START_TYPE,
        self::CE_TYPE_COLSET_PART   => self::BS_GRID_SEPARATOR_TYPE,
        self::CE_TYPE_COLSET_END    => self::BS_GRID_STOP_TYPE,
        self::FF_TYPE_FORMCOL_START => self::BS_GRID_START_TYPE,
        self::FF_TYPE_FORMCOL_PART  => self::BS_GRID_SEPARATOR_TYPE,
        self::FF_TYPE_FORMCOL_END   => self::BS_GRID_STOP_TYPE,
    ];

    public const BREAKPOINTS = ['xs', 'sm', 'md', 'lg', 'xl', 'xxl'];
    public const UNSPECIFIC_PLACEHOLDER = '#{._.}#';
}