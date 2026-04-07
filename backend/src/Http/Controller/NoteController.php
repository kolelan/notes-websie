<?php

declare(strict_types=1);

namespace App\Http\Controller;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class NoteController
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function list(Request $request, Response $response): Response
    {
        $stmt = $this->pdo->query('SELECT id, title, description, content, owner_id, created_at, updated_at FROM note ORDER BY created_at DESC');
        $notes = $stmt->fetchAll();

        $response->getBody()->write((string)json_encode(['data' => $notes], JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json');
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $stmt = $this->pdo->prepare('SELECT id, title, description, content, owner_id, created_at, updated_at FROM note WHERE id = :id');
        $stmt->execute(['id' => $args['id'] ?? '']);
        $note = $stmt->fetch();

        if ($note === false) {
            $response->getBody()->write((string)json_encode(['error' => 'Note not found'], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write((string)json_encode(['data' => $note], JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json');
    }

    public function create(Request $request, Response $response): Response
    {
        $payload = (array)$request->getParsedBody();
        $title = trim((string)($payload['title'] ?? ''));
        $description = trim((string)($payload['description'] ?? ''));
        $content = trim((string)($payload['content'] ?? ''));
        $ownerId = (string)($payload['owner_id'] ?? '');

        if ($title === '' || $ownerId === '') {
            $response->getBody()->write((string)json_encode(['error' => 'Fields title and owner_id are required'], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO note (title, description, content, owner_id) VALUES (:title, :description, :content, :owner_id) RETURNING id'
        );
        $stmt->execute([
            'title' => $title,
            'description' => $description,
            'content' => $content,
            'owner_id' => $ownerId,
        ]);
        $id = (string)$stmt->fetchColumn();

        $response->getBody()->write((string)json_encode(['data' => ['id' => $id]], JSON_UNESCAPED_UNICODE));

        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $payload = (array)$request->getParsedBody();
        $title = trim((string)($payload['title'] ?? ''));
        $description = trim((string)($payload['description'] ?? ''));
        $content = trim((string)($payload['content'] ?? ''));
        $noteId = (string)($args['id'] ?? '');

        if ($title === '') {
            $response->getBody()->write((string)json_encode(['error' => 'Field title is required'], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE note
             SET title = :title, description = :description, content = :content, updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $noteId,
            'title' => $title,
            'description' => $description,
            'content' => $content,
        ]);

        if ($stmt->rowCount() === 0) {
            $response->getBody()->write((string)json_encode(['error' => 'Note not found'], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write((string)json_encode(['data' => ['id' => $noteId]], JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $stmt = $this->pdo->prepare('DELETE FROM note WHERE id = :id');
        $stmt->execute(['id' => $args['id'] ?? '']);

        if ($stmt->rowCount() === 0) {
            $response->getBody()->write((string)json_encode(['error' => 'Note not found'], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        return $response->withStatus(204);
    }
}
