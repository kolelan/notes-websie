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
        $ownerId = (string)$request->getAttribute('user_id', '');
        $groupId = trim((string)($request->getQueryParams()['group_id'] ?? ''));
        $params = ['owner_id' => $ownerId];

        $sql = 'SELECT DISTINCT n.id, n.title, n.description, n.content, n.owner_id, n.created_at, n.updated_at
                FROM note n';
        if ($groupId !== '') {
            $sql .= ' INNER JOIN note_group ng ON ng.note_id = n.id AND ng.group_id = :group_id';
            $params['group_id'] = $groupId;
        }
        $sql .= ' WHERE n.owner_id = :owner_id ORDER BY n.created_at DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $notes = $stmt->fetchAll();

        $response->getBody()->write((string)json_encode(['data' => $notes], JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json');
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, title, description, content, owner_id, created_at, updated_at
             FROM note WHERE id = :id AND owner_id = :owner_id'
        );
        $stmt->execute([
            'id' => $args['id'] ?? '',
            'owner_id' => (string)$request->getAttribute('user_id', ''),
        ]);
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
        $ownerId = (string)$request->getAttribute('user_id', '');

        if ($title === '' || $ownerId === '') {
            $response->getBody()->write((string)json_encode(['error' => 'Authenticated user and title are required'], JSON_UNESCAPED_UNICODE));
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
             WHERE id = :id AND owner_id = :owner_id'
        );
        $stmt->execute([
            'id' => $noteId,
            'owner_id' => (string)$request->getAttribute('user_id', ''),
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
        $stmt = $this->pdo->prepare('DELETE FROM note WHERE id = :id AND owner_id = :owner_id');
        $stmt->execute([
            'id' => $args['id'] ?? '',
            'owner_id' => (string)$request->getAttribute('user_id', ''),
        ]);

        if ($stmt->rowCount() === 0) {
            $response->getBody()->write((string)json_encode(['error' => 'Note not found'], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        return $response->withStatus(204);
    }

    public function attachToGroup(Request $request, Response $response, array $args): Response
    {
        $ownerId = (string)$request->getAttribute('user_id', '');
        $noteId = (string)($args['id'] ?? '');
        $payload = (array)$request->getParsedBody();
        $groupId = trim((string)($payload['group_id'] ?? ''));

        if ($groupId === '') {
            return $this->json($response, ['error' => 'Field group_id is required'], 422);
        }

        if (!$this->noteExistsForOwner($noteId, $ownerId)) {
            return $this->json($response, ['error' => 'Note not found'], 404);
        }

        if (!$this->groupExistsForOwner($groupId, $ownerId)) {
            return $this->json($response, ['error' => 'Group not found'], 404);
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO note_group (note_id, group_id, is_copy)
             VALUES (:note_id, :group_id, FALSE)
             ON CONFLICT (note_id, group_id) DO NOTHING'
        );
        $stmt->execute([
            'note_id' => $noteId,
            'group_id' => $groupId,
        ]);

        return $this->json($response, ['data' => ['note_id' => $noteId, 'group_id' => $groupId, 'is_copy' => false]]);
    }

    public function copyToGroup(Request $request, Response $response, array $args): Response
    {
        $ownerId = (string)$request->getAttribute('user_id', '');
        $sourceNoteId = (string)($args['id'] ?? '');
        $payload = (array)$request->getParsedBody();
        $groupId = trim((string)($payload['group_id'] ?? ''));

        if ($groupId === '') {
            return $this->json($response, ['error' => 'Field group_id is required'], 422);
        }

        if (!$this->groupExistsForOwner($groupId, $ownerId)) {
            return $this->json($response, ['error' => 'Group not found'], 404);
        }

        $sourceStmt = $this->pdo->prepare(
            'SELECT title, description, content, image_preview_url
             FROM note
             WHERE id = :id AND owner_id = :owner_id
             LIMIT 1'
        );
        $sourceStmt->execute([
            'id' => $sourceNoteId,
            'owner_id' => $ownerId,
        ]);
        $source = $sourceStmt->fetch();

        if ($source === false) {
            return $this->json($response, ['error' => 'Source note not found'], 404);
        }

        $createStmt = $this->pdo->prepare(
            'INSERT INTO note (title, description, content, image_preview_url, owner_id)
             VALUES (:title, :description, :content, :image_preview_url, :owner_id)
             RETURNING id'
        );
        $createStmt->execute([
            'title' => $source['title'],
            'description' => $source['description'],
            'content' => $source['content'],
            'image_preview_url' => $source['image_preview_url'],
            'owner_id' => $ownerId,
        ]);
        $newNoteId = (string)$createStmt->fetchColumn();

        $attachStmt = $this->pdo->prepare(
            'INSERT INTO note_group (note_id, group_id, is_copy)
             VALUES (:note_id, :group_id, TRUE)'
        );
        $attachStmt->execute([
            'note_id' => $newNoteId,
            'group_id' => $groupId,
        ]);

        return $this->json($response, ['data' => [
            'source_note_id' => $sourceNoteId,
            'new_note_id' => $newNoteId,
            'group_id' => $groupId,
            'is_copy' => true,
        ]], 201);
    }

    private function noteExistsForOwner(string $noteId, string $ownerId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM note WHERE id = :id AND owner_id = :owner_id LIMIT 1');
        $stmt->execute([
            'id' => $noteId,
            'owner_id' => $ownerId,
        ]);
        return $stmt->fetchColumn() !== false;
    }

    private function groupExistsForOwner(string $groupId, string $ownerId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM "group" WHERE id = :id AND owner_id = :owner_id LIMIT 1');
        $stmt->execute([
            'id' => $groupId,
            'owner_id' => $ownerId,
        ]);
        return $stmt->fetchColumn() !== false;
    }

    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write((string)json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
