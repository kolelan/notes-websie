<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;

final class RateLimitMiddleware implements MiddlewareInterface
{
    /** @var array<string, array{count:int, reset:int}> */
    private static array $buckets = [];

    public function __construct(
        private readonly int $limit,
        private readonly int $windowSeconds
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $key = $this->buildKey($request);
        $now = time();

        $bucket = self::$buckets[$key] ?? ['count' => 0, 'reset' => $now + $this->windowSeconds];
        if ($bucket['reset'] <= $now) {
            $bucket = ['count' => 0, 'reset' => $now + $this->windowSeconds];
        }

        $bucket['count']++;
        self::$buckets[$key] = $bucket;

        if ($bucket['count'] > $this->limit) {
            $retryAfter = max(1, $bucket['reset'] - $now);
            $response = new SlimResponse(429);
            $response->getBody()->write((string)json_encode(['error' => 'Too many requests'], JSON_UNESCAPED_UNICODE));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Retry-After', (string)$retryAfter);
        }

        return $handler->handle($request);
    }

    private function buildKey(Request $request): string
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();
        return $method . ':' . $path . ':' . $ip;
    }
}

