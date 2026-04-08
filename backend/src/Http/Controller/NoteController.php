<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Acl\PermissionService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class NoteController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PermissionService $permissionService
    ) {
    }

    public function list(Request $request, Response $response): Response
    {
        $ownerId = (string)$request->getAttribute('user_id', '');
        $groupId = trim((string)($request->getQueryParams()['group_id'] ?? ''));
        $tagId = trim((string)($request->getQueryParams()['tag_id'] ?? ''));
        $params = ['owner_id' => $ownerId];

        $sql = 'SELECT DISTINCT n.id, n.title, n.description, n.content, n.owner_id, n.created_at, n.updated_at
                FROM note n';
        if ($groupId !== '') {
            $sql .= ' INNER JOIN note_group ng ON ng.note_id = n.id AND ng.group_id = :group_id';
            $params['group_id'] = $groupId;
        }
        if ($tagId !== '') {
            $sql .= ' INNER JOIN note_tag nt ON nt.note_id = n.id AND nt.tag_id = :tag_id';
            $params['tag_id'] = $tagId;
        }
        $sql .= ' WHERE n.owner_id = :owner_id ORDER BY n.created_at DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $notes = $stmt->fetchAll();

        $response->getBody()->write((string)json_encode(['data' => $notes], JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json');
    }

    public function overview(Request $request, Response $response): Response
    {
        $ownerId = (string)$request->getAttribute('user_id', '');
        $groupId = trim((string)($request->getQueryParams()['group_id'] ?? ''));
        $tagId = trim((string)($request->getQueryParams()['tag_id'] ?? ''));

        $params = ['owner_id' => $ownerId];
        $groupFilter = '';
        $tagFilter = '';
        if ($groupId !== '') {
            $groupFilter = ' AND EXISTS (
                SELECT 1 FROM note_group ngf WHERE ngf.note_id = n.id AND ngf.group_id = :group_id
            )';
            $params['group_id'] = $groupId;
        }
        if ($tagId !== '') {
            $tagFilter = ' AND EXISTS (
                SELECT 1 FROM note_tag ntf WHERE ntf.note_id = n.id AND ntf.tag_id = :tag_id
            )';
            $params['tag_id'] = $tagId;
        }

        $sql = 'SELECT
                    n.id,
                    n.title,
                    n.description,
                    n.content,
                    n.owner_id,
                    n.updated_at,
                    u.name AS author_name,
                    COALESCE(BOOL_OR(p.grantee_type = \'public\' AND p.can_read = TRUE), FALSE) AS is_public,
                    COALESCE(
                        json_agg(DISTINCT jsonb_build_object(\'id\', g.id, \'name\', g.name))
                            FILTER (WHERE g.id IS NOT NULL),
                        \'[]\'::json
                    ) AS groups,
                    COALESCE(
                        json_agg(DISTINCT jsonb_build_object(\'id\', t.id, \'name\', t.name))
                            FILTER (WHERE t.id IS NOT NULL),
                        \'[]\'::json
                    ) AS tags
                FROM note n
                LEFT JOIN "user" u ON u.id = n.owner_id
                LEFT JOIN note_group ng ON ng.note_id = n.id
                LEFT JOIN "group" g ON g.id = ng.group_id
                LEFT JOIN note_tag nt ON nt.note_id = n.id
                LEFT JOIN tag t ON t.id = nt.tag_id
                LEFT JOIN permission p ON p.target_type = \'note\' AND p.target_id = n.id
                WHERE n.owner_id = :owner_id'
                . $groupFilter
                . $tagFilter
                . ' GROUP BY n.id, u.name
                    ORDER BY n.updated_at DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $this->json($response, ['data' => $stmt->fetchAll()]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $noteId = (string)($args['id'] ?? '');
        $userId = (string)$request->getAttribute('user_id', '');

        if (!$this->permissionService->canReadNote($userId, $noteId)) {
            $response->getBody()->write((string)json_encode(['error' => 'Note not found'], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, title, description, content, owner_id, created_at, updated_at
             FROM note WHERE id = :id'
        );
        $stmt->execute(['id' => $noteId]);
        $note = $stmt->fetch();

        if ($note === false) {
            $response->getBody()->write((string)json_encode(['error' => 'Note not found'], JSON_UNESCAPED_UNICODE));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write((string)json_encode(['data' => $note], JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json');
    }

    public function publicShow(Request $request, Response $response, array $args): Response
    {
        $noteId = (string)($args['id'] ?? '');
        if (!$this->permissionService->canReadNote(null, $noteId)) {
            return $this->json($response, ['error' => 'Note not found'], 404);
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, title, description, content, owner_id, created_at, updated_at
             FROM note WHERE id = :id'
        );
        $stmt->execute(['id' => $noteId]);
        $note = $stmt->fetch();

        if ($note === false) {
            return $this->json($response, ['error' => 'Note not found'], 404);
        }

        return $this->json($response, ['data' => $note]);
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

    public function listTags(Request $request, Response $response): Response
    {
        $ownerId = (string)$request->getAttribute('user_id', '');
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT t.id, t.name
             FROM tag t
             INNER JOIN note_tag nt ON nt.tag_id = t.id
             INNER JOIN note n ON n.id = nt.note_id
             WHERE n.owner_id = :owner_id
             ORDER BY t.name ASC'
        );
        $stmt->execute(['owner_id' => $ownerId]);

        return $this->json($response, ['data' => $stmt->fetchAll()]);
    }

    public function addTag(Request $request, Response $response, array $args): Response
    {
        $ownerId = (string)$request->getAttribute('user_id', '');
        $noteId = (string)($args['id'] ?? '');
        $payload = (array)$request->getParsedBody();
        $tagName = trim((string)($payload['name'] ?? ''));

        if ($tagName === '') {
            return $this->json($response, ['error' => 'Field name is required'], 422);
        }

        if (!$this->noteExistsForOwner($noteId, $ownerId)) {
            return $this->json($response, ['error' => 'Note not found'], 404);
        }

        $tagStmt = $this->pdo->prepare(
            'INSERT INTO tag (name) VALUES (:name)
             ON CONFLICT (name) DO UPDATE SET name = EXCLUDED.name
             RETURNING id, name'
        );
        $tagStmt->execute(['name' => $tagName]);
        $tag = $tagStmt->fetch();

        $linkStmt = $this->pdo->prepare(
            'INSERT INTO note_tag (note_id, tag_id)
             VALUES (:note_id, :tag_id)
             ON CONFLICT (note_id, tag_id) DO NOTHING'
        );
        $linkStmt->execute([
            'note_id' => $noteId,
            'tag_id' => $tag['id'],
        ]);

        return $this->json($response, ['data' => [
            'note_id' => $noteId,
            'tag_id' => $tag['id'],
            'name' => $tag['name'],
        ]], 201);
    }

    public function removeTag(Request $request, Response $response, array $args): Response
    {
        $ownerId = (string)$request->getAttribute('user_id', '');
        $noteId = (string)($args['id'] ?? '');
        $tagId = (string)($args['tagId'] ?? '');

        if (!$this->noteExistsForOwner($noteId, $ownerId)) {
            return $this->json($response, ['error' => 'Note not found'], 404);
        }

        $stmt = $this->pdo->prepare(
            'DELETE FROM note_tag
             WHERE note_id = :note_id
               AND tag_id = :tag_id'
        );
        $stmt->execute([
            'note_id' => $noteId,
            'tag_id' => $tagId,
        ]);

        return $response->withStatus(204);
    }

    public function setPublic(Request $request, Response $response, array $args): Response
    {
        $ownerId = (string)$request->getAttribute('user_id', '');
        $noteId = (string)($args['id'] ?? '');
        $payload = (array)$request->getParsedBody();
        $isPublic = (bool)($payload['is_public'] ?? false);

        if (!$this->noteExistsForOwner($noteId, $ownerId)) {
            return $this->json($response, ['error' => 'Note not found'], 404);
        }

        if ($isPublic) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO permission (target_type, target_id, grantee_type, grantee_id, can_read, can_edit, can_manage, can_transfer)
                 VALUES (\'note\', :target_id, \'public\', NULL, TRUE, FALSE, FALSE, FALSE)
                 ON CONFLICT (target_type, target_id, grantee_type, grantee_id)
                 DO UPDATE SET can_read = TRUE'
            );
            $stmt->execute(['target_id' => $noteId]);
        } else {
            $stmt = $this->pdo->prepare(
                'DELETE FROM permission
                 WHERE target_type = \'note\' AND target_id = :target_id AND grantee_type = \'public\''
            );
            $stmt->execute(['target_id' => $noteId]);
        }

        return $this->json($response, ['data' => ['id' => $noteId, 'is_public' => $isPublic]]);
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
