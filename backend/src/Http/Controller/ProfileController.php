<?php

declare(strict_types=1);

namespace App\Http\Controller;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ProfileController
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function me(Request $request, Response $response): Response
    {
        $userId = (string)$request->getAttribute('user_id', '');
        if ($userId === '') {
            return $this->json($response, ['error' => 'Unauthorized'], 401);
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, email, name, role, is_active, created_at
             FROM "user"
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return $this->json($response, ['error' => 'User not found'], 404);
        }

        return $this->json($response, ['data' => $row]);
    }

    public function updateMe(Request $request, Response $response): Response
    {
        $userId = (string)$request->getAttribute('user_id', '');
        if ($userId === '') {
            return $this->json($response, ['error' => 'Unauthorized'], 401);
        }

        $payload = (array)$request->getParsedBody();
        $name = trim((string)($payload['name'] ?? ''));
        if ($name === '') {
            return $this->json($response, ['error' => 'Field name is required'], 422);
        }

        $stmt = $this->pdo->prepare('UPDATE "user" SET name = :name WHERE id = :id');
        $stmt->execute([
            'id' => $userId,
            'name' => $name,
        ]);

        return $this->json($response, ['data' => ['id' => $userId, 'name' => $name]]);
    }

    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write((string)json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
