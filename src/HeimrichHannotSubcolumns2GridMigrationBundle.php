<?php

namespace HeimrichHannot\Subcolumns2Grid;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class HeimrichHannotSubcolumns2GridMigrationBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}