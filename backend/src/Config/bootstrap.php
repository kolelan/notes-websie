<?php

declare(strict_types=1);

use Dotenv\Dotenv;

$rootDir = dirname(__DIR__, 2);

if (file_exists($rootDir . '/.env')) {
    Dotenv::createImmutable($rootDir)->safeLoad();
}
