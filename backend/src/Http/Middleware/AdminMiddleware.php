<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;

final class AdminMiddleware implements MiddlewareInterface
{
    public function process(Request $request, Handler $handler): Response
    {
        $role = (string)$request->getAttribute('user_role', '');
        if (!in_array($role, ['admin', 'superadmin'], true)) {
            $response = new SlimResponse(403);
            $response->getBody()->write((string)json_encode(['error' => 'Admin access required'], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json');
        }

        return $handler->handle($request);
    }
}
