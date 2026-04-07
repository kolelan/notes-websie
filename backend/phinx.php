<?php

declare(strict_types=1);

return [
    'paths' => [
        'migrations' => __DIR__ . '/database/migrations',
        'seeds' => __DIR__ . '/database/seeds',
    ],
    'environments' => [
        'default_environment' => 'development',
        'development' => [
            'adapter' => 'pgsql',
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'name' => getenv('DB_NAME') ?: 'notes_website',
            'user' => getenv('DB_USER') ?: 'postgres',
            'pass' => getenv('DB_PASSWORD') ?: 'postgres',
            'port' => (int)(getenv('DB_PORT') ?: 5432),
            'charset' => 'utf8',
        ],
        'production' => [
            'adapter' => 'pgsql',
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'name' => getenv('DB_NAME') ?: 'notes_website',
            'user' => getenv('DB_USER') ?: 'postgres',
            'pass' => getenv('DB_PASSWORD') ?: 'postgres',
            'port' => (int)(getenv('DB_PORT') ?: 5432),
            'charset' => 'utf8',
        ],
    ],
    'version_order' => 'creation',
];
