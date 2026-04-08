<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Predis\ClientInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;

final class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ClientInterface $redis,
        private readonly int $limit,
        private readonly int $windowSeconds
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $key = $this->buildKey($request);
        try {
            $count = (int)$this->redis->incr($key);
            if ($count === 1) {
                $this->redis->expire($key, $this->windowSeconds);
            }
            $ttl = (int)$this->redis->ttl($key);
        } catch (\Throwable) {
            // Fail-open for availability; request context middleware still logs traces.
            error_log('[rate-limit] redis unavailable, fail-open');
            return $handler->handle($request)->withHeader('X-RateLimit-Bypass', '1');
        }

        if ($count > $this->limit) {
            $retryAfter = max(1, $ttl);
            $response = new SlimResponse(429);
            $response->getBody()->write((string)json_encode(['error' => 'Too many requests'], JSON_UNESCAPED_UNICODE));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Retry-After', (string)$retryAfter);
        }

        return $handler->handle($request)
            ->withHeader('X-RateLimit-Count', (string)$count)
            ->withHeader('X-RateLimit-TTL', (string)$ttl);
    }

    private function buildKey(Request $request): string
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();
        return 'rate:' . $method . ':' . $path . ':' . $ip;
    }
}

