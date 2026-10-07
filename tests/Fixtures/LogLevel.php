<?php

declare(strict_types=1);

namespace Docuconf\Tests\Fixtures;

enum LogLevel: string
{
    case Debug = 'debug';
    case Info = 'info';
    case Warn = 'warn';
    case Error = 'error';
}
