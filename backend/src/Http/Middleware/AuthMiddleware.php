<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\JwtService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;

final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly JwtService $jwtService)
    {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $header = $request->getHeaderLine('Authorization');
        if (!str_starts_with($header, 'Bearer ')) {
            return $this->unauthorized('Missing bearer token');
        }

        $token = trim(substr($header, 7));
        if ($token === '') {
            return $this->unauthorized('Invalid bearer token');
        }

        try {
            $payload = $this->jwtService->decode($token);
            if (($payload->type ?? '') !== 'access') {
                return $this->unauthorized('Invalid token type');
            }
        } catch (\Throwable) {
            return $this->unauthorized('Invalid or expired token');
        }

        $request = $request
            ->withAttribute('user_id', (string)$payload->sub)
            ->withAttribute('user_role', (string)($payload->role ?? 'user'));

        return $handler->handle($request);
    }

    private function unauthorized(string $message): Response
    {
        $response = new SlimResponse(401);
        $response->getBody()->write((string)json_encode(['error' => $message], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
