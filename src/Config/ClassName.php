<?php

namespace HeimrichHannot\Subcolumns2Grid\Config;

class ClassName
{
    public const CLASS_TYPE_COL = 'col';
    public const CLASS_TYPE_OFFSET = 'offset';
    public const CLASS_TYPE_ORDER = 'order';

    public string $class;
    public string $type;
    public string $breakpoint;
    public string $width;

    public static function create(string $strClass, array &$customClasses = []): ?self
    {
        if (empty($strClass)) {
            return null;
        }

        $class = new self();
        $class->class = $strClass;

        $rx = "/(?P<type>(?:col-)?offset|col|order)(?:-(?P<breakpoint>xxs|xs|sm|md|lg|xl|xxl))?(?:-(?P<width>\d+))?/i";
        if (!\preg_match_all($rx, $strClass, $matches))
        {
            $class->breakpoint = '';
            $class->width = '';
            $class->type = '';
            $customClasses[] = $strClass;
            return $class;
        }

        $class->breakpoint = $matches['breakpoint'][0] ?? '';
        $class->width = $matches['width'][0] ?? '';

        $type = $matches['type'][0] ?? self::CLASS_TYPE_COL;
        $class->type = \strpos($type, 'offset') !== false
            ? self::CLASS_TYPE_OFFSET : (
            \strpos($type, 'order') !== false
                ? self::CLASS_TYPE_ORDER
                : self::CLASS_TYPE_COL
            );

        return $class;
    }
}