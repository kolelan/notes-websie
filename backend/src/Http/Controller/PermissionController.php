<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Acl\PermissionService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PermissionController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PermissionService $permissionService
    ) {
    }

    public function listForTarget(Request $request, Response $response, array $args): Response
    {
        $ownerId = (string)$request->getAttribute('user_id', '');
        $targetType = (string)($args['type'] ?? '');
        $targetId = (string)($args['id'] ?? '');

        if (!$this->ownerCanManageTarget($ownerId, $targetType, $targetId)) {
            return $this->json($response, ['error' => 'Target not found'], 404);
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, target_type, target_id, grantee_type, grantee_id, can_read, can_edit, can_manage, can_transfer, inherited_from
             FROM permission
             WHERE target_type = :target_type AND target_id = :target_id
             ORDER BY id ASC'
        );
        $stmt->execute(['target_type' => $targetType, 'target_id' => $targetId]);

        return $this->json($response, ['data' => $stmt->fetchAll()]);
    }

    public function create(Request $request, Response $response): Response
    {
        $ownerId = (string)$request->getAttribute('user_id', '');
        $payload = (array)$request->getParsedBody();

        $targetType = trim((string)($payload['target_type'] ?? ''));
        $targetId = trim((string)($payload['target_id'] ?? ''));
        $granteeType = trim((string)($payload['grantee_type'] ?? ''));
        $granteeId = isset($payload['grantee_id']) ? trim((string)$payload['grantee_id']) : null;

        if ($targetType === '' || $targetId === '' || $granteeType === '') {
            return $this->json($response, ['error' => 'Fields target_type, target_id, grantee_type are required'], 422);
        }

        if (!in_array($targetType, ['note', 'group'], true)) {
            return $this->json($response, ['error' => 'Invalid target_type'], 422);
        }

        if (!in_array($granteeType, ['user', 'public'], true)) {
            return $this->json($response, ['error' => 'Invalid grantee_type'], 422);
        }

        if ($granteeType === 'user' && ($granteeId === null || $granteeId === '')) {
            return $this->json($response, ['error' => 'Field grantee_id is required for grantee_type=user'], 422);
        }
        if ($granteeType === 'public') {
            $granteeId = null;
        }

        if (!$this->ownerCanManageTarget($ownerId, $targetType, $targetId)) {
            return $this->json($response, ['error' => 'Target not found'], 404);
        }

        $canRead = $this->toDbBool($payload['can_read'] ?? false);
        $canEdit = $this->toDbBool($payload['can_edit'] ?? false);
        $canManage = $this->toDbBool($payload['can_manage'] ?? false);
        $canTransfer = $this->toDbBool($payload['can_transfer'] ?? false);

        $stmt = $this->pdo->prepare(
            'INSERT INTO permission (target_type, target_id, grantee_type, grantee_id, can_read, can_edit, can_manage, can_transfer)
             VALUES (:target_type, :target_id, :grantee_type, :grantee_id, :can_read, :can_edit, :can_manage, :can_transfer)
             RETURNING id'
        );
        $stmt->execute([
            'target_type' => $targetType,
            'target_id' => $targetId,
            'grantee_type' => $granteeType,
            'grantee_id' => $granteeId,
            'can_read' => $canRead,
            'can_edit' => $canEdit,
            'can_manage' => $canManage,
            'can_transfer' => $canTransfer,
        ]);

        return $this->json($response, ['data' => ['id' => (string)$stmt->fetchColumn()]], 201);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $ownerId = (string)$request->getAttribute('user_id', '');
        $permissionId = (string)($args['id'] ?? '');

        // Ensure owner can manage the target of this permission
        $stmt = $this->pdo->prepare('SELECT target_type, target_id FROM permission WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $permissionId]);
        $perm = $stmt->fetch();
        if ($perm === false || !$this->ownerCanManageTarget($ownerId, (string)$perm['target_type'], (string)$perm['target_id'])) {
            return $this->json($response, ['error' => 'Permission not found'], 404);
        }

        $del = $this->pdo->prepare('DELETE FROM permission WHERE id = :id');
        $del->execute(['id' => $permissionId]);

        return $response->withStatus(204);
    }

    private function ownerCanManageTarget(string $ownerId, string $targetType, string $targetId): bool
    {
        if ($targetType === 'note') {
            return $this->permissionService->isNoteOwner($ownerId, $targetId);
        }
        if ($targetType === 'group') {
            return $this->permissionService->isGroupOwner($ownerId, $targetId);
        }
        return false;
    }

    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write((string)json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }

    private function toDbBool(mixed $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_int($value)) {
            return $value === 1 ? 1 : 0;
        }
        if (is_string($value)) {
            $v = strtolower(trim($value));
            if (in_array($v, ['1', 'true', 'yes', 'y', 'on'], true)) {
                return 1;
            }
            return 0;
        }

        return 0;
    }
}

