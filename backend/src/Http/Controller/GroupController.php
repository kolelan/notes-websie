<?php

declare(strict_types=1);

namespace App\Http\Controller;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class GroupController
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function list(Request $request, Response $response): Response
    {
        $ownerId = (string)$request->getAttribute('user_id', '');

        $stmt = $this->pdo->prepare(
            'SELECT id, name, description, image_url, parent_id, owner_id, created_at, updated_at
             FROM "group"
             WHERE owner_id = :owner_id
             ORDER BY created_at ASC'
        );
        $stmt->execute(['owner_id' => $ownerId]);
        $rows = $stmt->fetchAll();

        $response->getBody()->write((string)json_encode(['data' => $this->buildTree($rows)], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $group = $this->findOwnedGroup((string)$request->getAttribute('user_id', ''), (string)($args['id'] ?? ''));
        if ($group === null) {
            return $this->json($response, ['error' => 'Group not found'], 404);
        }

        return $this->json($response, ['data' => $group]);
    }

    public function create(Request $request, Response $response): Response
    {
        $ownerId = (string)$request->getAttribute('user_id', '');
        $payload = (array)$request->getParsedBody();
        $name = trim((string)($payload['name'] ?? ''));
        $description = trim((string)($payload['description'] ?? ''));
        $imageUrl = trim((string)($payload['image_url'] ?? ''));
        $parentId = trim((string)($payload['parent_id'] ?? ''));

        if ($name === '') {
            return $this->json($response, ['error' => 'Field name is required'], 422);
        }

        if ($parentId !== '' && $this->findOwnedGroup($ownerId, $parentId) === null) {
            return $this->json($response, ['error' => 'Parent group not found'], 422);
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO "group" (name, description, image_url, parent_id, owner_id)
             VALUES (:name, :description, :image_url, :parent_id, :owner_id)
             RETURNING id'
        );
        $stmt->execute([
            'name' => $name,
            'description' => $description,
            'image_url' => $imageUrl === '' ? null : $imageUrl,
            'parent_id' => $parentId === '' ? null : $parentId,
            'owner_id' => $ownerId,
        ]);

        return $this->json($response, ['data' => ['id' => (string)$stmt->fetchColumn()]], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $ownerId = (string)$request->getAttribute('user_id', '');
        $groupId = (string)($args['id'] ?? '');
        $existing = $this->findOwnedGroup($ownerId, $groupId);
        if ($existing === null) {
            return $this->json($response, ['error' => 'Group not found'], 404);
        }

        $payload = (array)$request->getParsedBody();
        $name = trim((string)($payload['name'] ?? $existing['name']));
        $description = trim((string)($payload['description'] ?? $existing['description']));
        $imageUrl = trim((string)($payload['image_url'] ?? (string)($existing['image_url'] ?? '')));
        $hasParent = array_key_exists('parent_id', $payload);
        $parentId = $hasParent ? trim((string)$payload['parent_id']) : (string)($existing['parent_id'] ?? '');

        if ($name === '') {
            return $this->json($response, ['error' => 'Field name is required'], 422);
        }

        if ($parentId !== '') {
            if ($parentId === $groupId) {
                return $this->json($response, ['error' => 'Group cannot be parent of itself'], 422);
            }
            if ($this->findOwnedGroup($ownerId, $parentId) === null) {
                return $this->json($response, ['error' => 'Parent group not found'], 422);
            }
            if ($this->isDescendant($ownerId, $groupId, $parentId)) {
                return $this->json($response, ['error' => 'Cannot move group under its descendant'], 422);
            }
        }

        $stmt = $this->pdo->prepare(
            'UPDATE "group"
             SET name = :name,
                 description = :description,
                 image_url = :image_url,
                 parent_id = :parent_id,
                 updated_at = NOW()
             WHERE id = :id AND owner_id = :owner_id'
        );
        $stmt->execute([
            'id' => $groupId,
            'owner_id' => $ownerId,
            'name' => $name,
            'description' => $description,
            'image_url' => $imageUrl === '' ? null : $imageUrl,
            'parent_id' => $parentId === '' ? null : $parentId,
        ]);

        return $this->json($response, ['data' => ['id' => $groupId]]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $stmt = $this->pdo->prepare('DELETE FROM "group" WHERE id = :id AND owner_id = :owner_id');
        $stmt->execute([
            'id' => $args['id'] ?? '',
            'owner_id' => (string)$request->getAttribute('user_id', ''),
        ]);

        if ($stmt->rowCount() === 0) {
            return $this->json($response, ['error' => 'Group not found'], 404);
        }

        return $response->withStatus(204);
    }

    private function findOwnedGroup(string $ownerId, string $groupId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, description, image_url, parent_id, owner_id, created_at, updated_at
             FROM "group"
             WHERE id = :id AND owner_id = :owner_id
             LIMIT 1'
        );
        $stmt->execute(['id' => $groupId, 'owner_id' => $ownerId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private function isDescendant(string $ownerId, string $groupId, string $candidateParentId): bool
    {
        $current = $candidateParentId;
        while ($current !== '') {
            if ($current === $groupId) {
                return true;
            }

            $stmt = $this->pdo->prepare('SELECT parent_id FROM "group" WHERE id = :id AND owner_id = :owner_id LIMIT 1');
            $stmt->execute(['id' => $current, 'owner_id' => $ownerId]);
            $row = $stmt->fetch();
            if ($row === false || $row['parent_id'] === null) {
                return false;
            }

            $current = (string)$row['parent_id'];
        }

        return false;
    }

    private function buildTree(array $rows): array
    {
        $byId = [];
        foreach ($rows as $row) {
            $row['children'] = [];
            $byId[$row['id']] = $row;
        }

        $tree = [];
        foreach ($byId as $id => $node) {
            $parentId = $node['parent_id'];
            if ($parentId !== null && isset($byId[$parentId])) {
                $byId[$parentId]['children'][] = &$byId[$id];
            } else {
                $tree[] = &$byId[$id];
            }
        }

        return $tree;
    }

    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write((string)json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
