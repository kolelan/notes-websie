<?php

declare(strict_types=1);

namespace App\Cache;

use Predis\Client;

final class RedisFactory
{
    public static function createFromEnv(): Client
    {
        $host = (string)(getenv('REDIS_HOST') ?: '127.0.0.1');
        $port = (int)(getenv('REDIS_PORT') ?: 6379);

        return new Client([
            'scheme' => 'tcp',
            'host' => $host,
            'port' => $port,
            'timeout' => 1.0,
            'read_write_timeout' => 1.0,
        ]);
    }
}

