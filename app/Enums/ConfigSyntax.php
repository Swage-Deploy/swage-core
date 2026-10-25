<?php

namespace App\Enums;

enum ConfigSyntax
{
    case Nginx;
    case Ini;
    case Mysql;
    case Redis;
}
