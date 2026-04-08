<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

final class RequestContextMiddleware implements MiddlewareInterface
{
    public function process(Request $request, Handler $handler): Response
    {
        $requestId = $request->getHeaderLine('X-Request-Id');
        if ($requestId === '') {
            $requestId = bin2hex(random_bytes(8));
        }

        $start = microtime(true);
        $request = $request->withAttribute('request_id', $requestId);
        $response = $handler->handle($request);
        $elapsedMs = (int)round((microtime(true) - $start) * 1000);

        $userId = (string)$request->getAttribute('user_id', 'guest');
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();
        $status = $response->getStatusCode();

        // Minimal audit trail for troubleshooting and security events.
        error_log(sprintf(
            '[audit] request_id=%s method=%s path=%s status=%d duration_ms=%d user=%s',
            $requestId,
            $method,
            $path,
            $status,
            $elapsedMs,
            $userId === '' ? 'guest' : $userId
        ));

        return $response->withHeader('X-Request-Id', $requestId);
    }
}

