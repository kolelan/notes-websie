<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Acl\PermissionService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class GroupController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PermissionService $permissionService
    ) {
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
        $userId = (string)$request->getAttribute('user_id', '');
        $groupId = (string)($args['id'] ?? '');

        // MVP: пока возвращаем только свои группы или те, на которые выдан read через permission
        $group = $this->findOwnedGroup($userId, $groupId);
        if ($group === null) {
            if (!$this->permissionService->canReadGroup($userId, $groupId)) {
                return $this->json($response, ['error' => 'Group not found'], 404);
            }
            $group = $this->findGroupById($groupId);
            if ($group === null) {
                return $this->json($response, ['error' => 'Group not found'], 404);
            }
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

    public function invite(Request $request, Response $response, array $args): Response
    {
        $ownerId = (string)$request->getAttribute('user_id', '');
        $groupId = (string)($args['id'] ?? '');
        $group = $this->findOwnedGroup($ownerId, $groupId);
        if ($group === null) {
            return $this->json($response, ['error' => 'Group not found'], 404);
        }

        $payload = (array)$request->getParsedBody();
        $inviteeEmail = trim((string)($payload['invitee_email'] ?? ''));
        $role = trim((string)($payload['role'] ?? 'reader'));
        $expiresIn = (int)($payload['expires_in'] ?? 604800); // 7 days

        if ($inviteeEmail === '') {
            return $this->json($response, ['error' => 'Field invitee_email is required'], 422);
        }
        if (!in_array($role, ['reader', 'editor', 'manager'], true)) {
            return $this->json($response, ['error' => 'Invalid role'], 422);
        }
        if ($expiresIn <= 0) {
            $expiresIn = 604800;
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = time() + $expiresIn;

        $stmt = $this->pdo->prepare(
            'INSERT INTO invitation (target_group_id, inviter_id, invitee_email, role, token, expires_at)
             VALUES (:target_group_id, :inviter_id, :invitee_email, :role, :token, TO_TIMESTAMP(:expires_at))
             RETURNING id'
        );
        $stmt->execute([
            'target_group_id' => $groupId,
            'inviter_id' => $ownerId,
            'invitee_email' => $inviteeEmail,
            'role' => $role,
            'token' => $token,
            'expires_at' => $expiresAt,
        ]);

        return $this->json($response, [
            'data' => [
                'id' => (string)$stmt->fetchColumn(),
                'token' => $token,
                'expires_at' => $expiresAt,
            ],
        ], 201);
    }

    public function acceptInvite(Request $request, Response $response, array $args): Response
    {
        $userId = (string)$request->getAttribute('user_id', '');
        $groupId = (string)($args['id'] ?? '');
        $payload = (array)$request->getParsedBody();
        $token = trim((string)($payload['token'] ?? ''));

        if ($token === '') {
            return $this->json($response, ['error' => 'Field token is required'], 422);
        }

        $emailStmt = $this->pdo->prepare('SELECT email FROM "user" WHERE id = :id LIMIT 1');
        $emailStmt->execute(['id' => $userId]);
        $user = $emailStmt->fetch();
        if ($user === false) {
            return $this->json($response, ['error' => 'User not found'], 404);
        }

        $invStmt = $this->pdo->prepare(
            'SELECT id, target_group_id, inviter_id, invitee_email, role, token, expires_at
             FROM invitation
             WHERE target_group_id = :group_id AND token = :token
             LIMIT 1'
        );
        $invStmt->execute(['group_id' => $groupId, 'token' => $token]);
        $inv = $invStmt->fetch();

        if ($inv === false || strtotime((string)$inv['expires_at']) < time()) {
            return $this->json($response, ['error' => 'Invitation not found or expired'], 404);
        }

        if (mb_strtolower(trim((string)$inv['invitee_email'])) !== mb_strtolower(trim((string)$user['email']))) {
            return $this->json($response, ['error' => 'Invitation is not for this user'], 403);
        }

        // Ensure group exists (even if owner deleted, FK would delete invitation)
        $groupRow = $this->findGroupById($groupId);
        if ($groupRow === null) {
            return $this->json($response, ['error' => 'Group not found'], 404);
        }

        // Create or reuse a user_group for this shared group (for future group_of_users ACL)
        $userGroupName = 'shares:' . $groupId;
        $ownerId = (string)$groupRow['owner_id'];

        $ugStmt = $this->pdo->prepare('SELECT id FROM user_group WHERE owner_id = :owner_id AND name = :name LIMIT 1');
        $ugStmt->execute(['owner_id' => $ownerId, 'name' => $userGroupName]);
        $userGroupId = $ugStmt->fetchColumn();
        if ($userGroupId === false) {
            $createUg = $this->pdo->prepare('INSERT INTO user_group (name, owner_id) VALUES (:name, :owner_id) RETURNING id');
            $createUg->execute(['name' => $userGroupName, 'owner_id' => $ownerId]);
            $userGroupId = $createUg->fetchColumn();
        }

        $memberStmt = $this->pdo->prepare(
            'INSERT INTO user_group_member (user_group_id, user_id)
             VALUES (:user_group_id, :user_id)
             ON CONFLICT (user_group_id, user_id) DO NOTHING'
        );
        $memberStmt->execute(['user_group_id' => $userGroupId, 'user_id' => $userId]);

        // Grant direct permission to the user (MVP: current PermissionService checks user/public only)
        [$canRead, $canEdit, $canManage] = match ((string)$inv['role']) {
            'manager' => [1, 1, 1],
            'editor' => [1, 1, 0],
            default => [1, 0, 0],
        };

        $permStmt = $this->pdo->prepare(
            'INSERT INTO permission (target_type, target_id, grantee_type, grantee_id, can_read, can_edit, can_manage, can_transfer)
             VALUES (\'group\', :target_id, \'user\', :grantee_id, :can_read, :can_edit, :can_manage, FALSE)
             ON CONFLICT (target_type, target_id, grantee_type, grantee_id)
             DO UPDATE SET
                 can_read = EXCLUDED.can_read,
                 can_edit = EXCLUDED.can_edit,
                 can_manage = EXCLUDED.can_manage'
        );
        $permStmt->execute([
            'target_id' => $groupId,
            'grantee_id' => $userId,
            'can_read' => $canRead,
            'can_edit' => $canEdit,
            'can_manage' => $canManage,
        ]);

        return $this->json($response, ['data' => [
            'group_id' => $groupId,
            'role' => (string)$inv['role'],
            'user_group_id' => (string)$userGroupId,
            'ok' => true,
        ]]);
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

    private function findGroupById(string $groupId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, description, image_url, parent_id, owner_id, created_at, updated_at
             FROM "group"
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $groupId]);
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
