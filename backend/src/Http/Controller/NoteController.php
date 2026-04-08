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
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['groups'] = $this->decodeJsonArray($row['groups'] ?? null);
            $row['tags'] = $this->decodeJsonArray($row['tags'] ?? null);
            $row['is_public'] = (bool)($row['is_public'] ?? false);
        }
        unset($row);

        return $this->json($response, ['data' => $rows]);
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

    public function publicList(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        $authorId = trim((string)($query['author_id'] ?? ''));
        $authorNameLike = trim((string)($query['author_name_like'] ?? ''));
        $titleLike = trim((string)($query['title_like'] ?? ''));
        $descriptionLike = trim((string)($query['description_like'] ?? ''));
        $publishedFrom = trim((string)($query['published_from'] ?? ''));
        $publishedTo = trim((string)($query['published_to'] ?? ''));
        $tagId = trim((string)($query['tag_id'] ?? ''));
        $groupId = trim((string)($query['group_id'] ?? ''));
        $sortByRaw = trim((string)($query['sort_by'] ?? 'published_at'));
        $sortDirRaw = trim((string)($query['sort_dir'] ?? 'desc'));
        $page = max(1, (int)($query['page'] ?? 1));
        $limit = (int)($query['limit'] ?? 20);
        if ($limit < 1) {
            $limit = 20;
        }
        if ($limit > 100) {
            $limit = 100;
        }
        $offset = ($page - 1) * $limit;

        $sortMap = [
            'published_at' => 'n.updated_at',
            'title' => 'n.title',
            'author' => 'u.name',
        ];
        $sortBy = $sortMap[$sortByRaw] ?? $sortMap['published_at'];
        $sortDir = mb_strtolower($sortDirRaw) === 'asc' ? 'ASC' : 'DESC';

        $params = [];
        $where = ['p.target_type = \'note\'', 'p.grantee_type = \'public\'', 'p.can_read = TRUE'];
        if ($authorId !== '') {
            $where[] = 'n.owner_id = :author_id';
            $params['author_id'] = $authorId;
        }
        if ($authorNameLike !== '') {
            $where[] = 'u.name ILIKE :author_name_like';
            $params['author_name_like'] = '%' . $authorNameLike . '%';
        }
        if ($titleLike !== '') {
            $where[] = 'n.title ILIKE :title_like';
            $params['title_like'] = '%' . $titleLike . '%';
        }
        if ($descriptionLike !== '') {
            $where[] = 'n.description ILIKE :description_like';
            $params['description_like'] = '%' . $descriptionLike . '%';
        }
        if ($publishedFrom !== '') {
            $where[] = 'n.updated_at >= CAST(:published_from AS timestamptz)';
            $params['published_from'] = $publishedFrom;
        }
        if ($publishedTo !== '') {
            $where[] = 'n.updated_at <= CAST(:published_to AS timestamptz)';
            $params['published_to'] = $publishedTo;
        }
        if ($tagId !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM note_tag ntf WHERE ntf.note_id = n.id AND ntf.tag_id = :tag_id)';
            $params['tag_id'] = $tagId;
        }
        if ($groupId !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM note_group ngf WHERE ngf.note_id = n.id AND ngf.group_id = :group_id)';
            $params['group_id'] = $groupId;
        }
        $whereSql = implode(' AND ', $where);

        $countStmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT n.id)
             FROM note n
             INNER JOIN "user" u ON u.id = n.owner_id
             INNER JOIN permission p ON p.target_id = n.id
             WHERE ' . $whereSql
        );
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            'SELECT
                n.id,
                n.title,
                n.description,
                n.content,
                n.owner_id,
                n.updated_at AS published_at,
                u.name AS author_name,
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
             INNER JOIN "user" u ON u.id = n.owner_id
             INNER JOIN permission p ON p.target_id = n.id
             LEFT JOIN note_group ng ON ng.note_id = n.id
             LEFT JOIN "group" g ON g.id = ng.group_id
             LEFT JOIN note_tag nt ON nt.note_id = n.id
             LEFT JOIN tag t ON t.id = nt.tag_id
             WHERE ' . $whereSql . '
             GROUP BY n.id, u.name
             ORDER BY ' . $sortBy . ' ' . $sortDir . '
             LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['groups'] = $this->decodeJsonArray($row['groups'] ?? null);
            $row['tags'] = $this->decodeJsonArray($row['tags'] ?? null);
        }
        unset($row);

        return $this->json($response, [
            'data' => $rows,
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int)max(1, (int)ceil($total / $limit)),
            ],
        ]);
    }

    public function publicFilterAuthors(Request $request, Response $response): Response
    {
        $stmt = $this->pdo->query(
            'SELECT
                u.id,
                u.name,
                COUNT(DISTINCT n.id)::int AS notes_count
             FROM permission p
             INNER JOIN note n ON n.id = p.target_id
             INNER JOIN "user" u ON u.id = n.owner_id
             WHERE p.target_type = \'note\' AND p.grantee_type = \'public\' AND p.can_read = TRUE
             GROUP BY u.id, u.name
             ORDER BY u.name ASC'
        );
        return $this->json($response, ['data' => $stmt->fetchAll()]);
    }

    public function publicFilterTags(Request $request, Response $response): Response
    {
        $stmt = $this->pdo->query(
            'SELECT
                t.id,
                t.name,
                COUNT(DISTINCT n.id)::int AS notes_count
             FROM permission p
             INNER JOIN note n ON n.id = p.target_id
             INNER JOIN note_tag nt ON nt.note_id = n.id
             INNER JOIN tag t ON t.id = nt.tag_id
             WHERE p.target_type = \'note\' AND p.grantee_type = \'public\' AND p.can_read = TRUE
             GROUP BY t.id, t.name
             ORDER BY t.name ASC'
        );
        return $this->json($response, ['data' => $stmt->fetchAll()]);
    }

    public function publicFilterGroups(Request $request, Response $response): Response
    {
        $authorId = trim((string)($request->getQueryParams()['author_id'] ?? ''));
        $params = [];
        $authorFilter = '';
        if ($authorId !== '') {
            $authorFilter = ' AND n.owner_id = :author_id';
            $params['author_id'] = $authorId;
        }

        $stmt = $this->pdo->prepare(
            'SELECT
                g.id,
                g.name,
                COUNT(DISTINCT n.id)::int AS notes_count
             FROM permission p
             INNER JOIN note n ON n.id = p.target_id
             INNER JOIN note_group ng ON ng.note_id = n.id
             INNER JOIN "group" g ON g.id = ng.group_id
             WHERE p.target_type = \'note\' AND p.grantee_type = \'public\' AND p.can_read = TRUE'
             . $authorFilter .
            ' GROUP BY g.id, g.name
              ORDER BY g.name ASC'
        );
        $stmt->execute($params);
        return $this->json($response, ['data' => $stmt->fetchAll()]);
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

    public function detachFromGroup(Request $request, Response $response, array $args): Response
    {
        $ownerId = (string)$request->getAttribute('user_id', '');
        $noteId = (string)($args['id'] ?? '');
        $groupId = (string)($args['groupId'] ?? '');

        if ($groupId === '') {
            return $this->json($response, ['error' => 'Field groupId is required'], 422);
        }

        if (!$this->noteExistsForOwner($noteId, $ownerId)) {
            return $this->json($response, ['error' => 'Note not found'], 404);
        }

        if (!$this->groupExistsForOwner($groupId, $ownerId)) {
            return $this->json($response, ['error' => 'Group not found'], 404);
        }

        $stmt = $this->pdo->prepare(
            'DELETE FROM note_group
             WHERE note_id = :note_id
               AND group_id = :group_id'
        );
        $stmt->execute([
            'note_id' => $noteId,
            'group_id' => $groupId,
        ]);

        return $response->withStatus(204);
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

    private function decodeJsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value)) {
            return [];
        }
        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return [];
        }
        return $decoded;
    }
}
