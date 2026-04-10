<?php

declare(strict_types=1);

namespace App\Http\Controller;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AdminController
{
    private const ALLOWED_SETTING_KEYS = [
        'yandex_metrika',
        'security.waf',
        'public.homepage.hero',
        'public.homepage.tagline',
        'public.features',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function listUsers(Request $request, Response $response): Response
    {
        $query = trim((string)($request->getQueryParams()['q'] ?? ''));
        $role = trim((string)($request->getQueryParams()['role'] ?? ''));
        $isActiveRaw = (string)($request->getQueryParams()['is_active'] ?? '');
        $page = max(1, (int)($request->getQueryParams()['page'] ?? 1));
        $limit = (int)($request->getQueryParams()['limit'] ?? 20);
        if ($limit < 1) {
            $limit = 20;
        }
        if ($limit > 100) {
            $limit = 100;
        }
        $offset = ($page - 1) * $limit;

        $params = [];
        $where = [];
        if ($query !== '') {
            $where[] = '(email ILIKE :q OR name ILIKE :q)';
            $params['q'] = '%' . $query . '%';
        }
        if ($role !== '') {
            $where[] = 'role = :role';
            $params['role'] = $role;
        }
        if ($isActiveRaw !== '') {
            $where[] = 'is_active = :is_active';
            $params['is_active'] = in_array(mb_strtolower($isActiveRaw), ['1', 'true', 'yes'], true);
        }
        $whereSql = $where !== [] ? (' WHERE ' . implode(' AND ', $where)) : '';

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM "user"' . $whereSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sql = 'SELECT id, email, name, role, is_active, created_at
                FROM "user"'
                . $whereSql
                . ' ORDER BY created_at DESC
                    LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $this->json($response, [
            'data' => $stmt->fetchAll(),
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int)max(1, (int)ceil($total / $limit)),
            ],
        ]);
    }

    public function updateUser(Request $request, Response $response, array $args): Response
    {
        $targetUserId = (string)($args['id'] ?? '');
        if ($targetUserId === '') {
            return $this->json($response, ['error' => 'User id is required'], 422);
        }

        $payload = (array)$request->getParsedBody();
        $allowedRoles = ['user', 'admin', 'superadmin'];
        $fields = [];
        $params = ['id' => $targetUserId];

        if (array_key_exists('name', $payload)) {
            $fields[] = 'name = :name';
            $params['name'] = trim((string)$payload['name']);
        }
        if (array_key_exists('role', $payload)) {
            $role = trim((string)$payload['role']);
            if (!in_array($role, $allowedRoles, true)) {
                return $this->json($response, ['error' => 'Invalid role'], 422);
            }
            $fields[] = 'role = :role';
            $params['role'] = $role;
        }
        if (array_key_exists('is_active', $payload)) {
            $fields[] = 'is_active = :is_active';
            $params['is_active'] = (bool)$payload['is_active'];
        }

        if ($fields === []) {
            return $this->json($response, ['error' => 'No fields to update'], 422);
        }

        $stmt = $this->pdo->prepare('UPDATE "user" SET ' . implode(', ', $fields) . ' WHERE id = :id');
        $stmt->execute($params);
        if ($stmt->rowCount() === 0) {
            return $this->json($response, ['error' => 'User not found or unchanged'], 404);
        }

        $this->writeAudit(
            (string)$request->getAttribute('user_id', ''),
            'admin.user.update',
            'user',
            $targetUserId,
            ['fields' => array_keys($payload)]
        );

        return $this->json($response, ['data' => ['id' => $targetUserId]]);
    }

    public function revokeUserSessions(Request $request, Response $response, array $args): Response
    {
        $targetUserId = (string)($args['id'] ?? '');
        if ($targetUserId === '') {
            return $this->json($response, ['error' => 'User id is required'], 422);
        }

        $stmt = $this->pdo->prepare(
            'UPDATE refresh_token
             SET revoked_at = NOW()
             WHERE user_id = :user_id AND revoked_at IS NULL'
        );
        $stmt->execute(['user_id' => $targetUserId]);

        $this->writeAudit(
            (string)$request->getAttribute('user_id', ''),
            'admin.user.logout_all',
            'user',
            $targetUserId,
            ['revoked' => $stmt->rowCount()]
        );

        return $this->json($response, ['data' => ['id' => $targetUserId, 'revoked' => $stmt->rowCount()]]);
    }

    public function deleteUser(Request $request, Response $response, array $args): Response
    {
        $actorRole = (string)$request->getAttribute('user_role', '');
        if ($actorRole !== 'superadmin') {
            return $this->json($response, ['error' => 'Superadmin access required'], 403);
        }

        $actorUserId = (string)$request->getAttribute('user_id', '');
        $targetUserId = (string)($args['id'] ?? '');
        if ($targetUserId === '') {
            return $this->json($response, ['error' => 'User id is required'], 422);
        }
        if ($actorUserId !== '' && $targetUserId === $actorUserId) {
            return $this->json($response, ['error' => 'You cannot delete yourself'], 422);
        }

        $userStmt = $this->pdo->prepare('SELECT id, email, role, is_active FROM "user" WHERE id = :id LIMIT 1');
        $userStmt->execute(['id' => $targetUserId]);
        $user = $userStmt->fetch();
        if (!$user) {
            return $this->json($response, ['error' => 'User not found'], 404);
        }

        if ((string)$user['role'] === 'superadmin' && (bool)$user['is_active']) {
            $countStmt = $this->pdo->query('SELECT COUNT(*) FROM "user" WHERE role = \'superadmin\' AND is_active = TRUE');
            $superadmins = (int)$countStmt->fetchColumn();
            if ($superadmins <= 1) {
                return $this->json($response, ['error' => 'Cannot delete last active superadmin'], 422);
            }
        }

        $this->pdo->beginTransaction();
        try {
            $delStmt = $this->pdo->prepare('DELETE FROM "user" WHERE id = :id');
            $delStmt->execute(['id' => $targetUserId]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            return $this->json($response, ['error' => 'Failed to delete user'], 500);
        }

        $this->writeAudit(
            $actorUserId,
            'admin.user.delete',
            'user',
            $targetUserId,
            [
                'mode' => 'hard',
                'email' => (string)$user['email'],
                'role' => (string)$user['role'],
                'was_active' => (bool)$user['is_active'],
            ]
        );

        return $response->withStatus(204);
    }

    public function listSettings(Request $request, Response $response): Response
    {
        $stmt = $this->pdo->query('SELECT key, value, updated_by, updated_at FROM system_setting ORDER BY key ASC');
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['value'] = $this->decodeJsonValue($row['value'] ?? null);
        }
        unset($row);
        return $this->json($response, ['data' => $rows]);
    }

    public function upsertSetting(Request $request, Response $response, array $args): Response
    {
        $key = trim((string)($args['key'] ?? ''));
        if ($key === '') {
            return $this->json($response, ['error' => 'Setting key is required'], 422);
        }
        if (!$this->isAllowedSettingKey($key)) {
            return $this->json($response, ['error' => 'Setting key is not allowed'], 422);
        }

        $payload = (array)$request->getParsedBody();
        if (!array_key_exists('value', $payload)) {
            return $this->json($response, ['error' => 'Field value is required'], 422);
        }

        $valueJson = json_encode($payload['value'], JSON_UNESCAPED_UNICODE);
        if ($valueJson === false) {
            return $this->json($response, ['error' => 'Invalid value payload'], 422);
        }

        $updatedBy = (string)$request->getAttribute('user_id', '');
        $stmt = $this->pdo->prepare(
            'INSERT INTO system_setting (key, value, updated_by, updated_at)
             VALUES (:key, CAST(:value AS jsonb), :updated_by, NOW())
             ON CONFLICT (key)
             DO UPDATE SET value = EXCLUDED.value, updated_by = EXCLUDED.updated_by, updated_at = NOW()'
        );
        $stmt->execute([
            'key' => $key,
            'value' => $valueJson,
            'updated_by' => $updatedBy === '' ? null : $updatedBy,
        ]);

        $this->writeAudit(
            $updatedBy,
            'admin.setting.upsert',
            'system_setting',
            $key,
            ['value' => $payload['value']]
        );

        return $this->json($response, ['data' => ['key' => $key, 'value' => $payload['value']]]);
    }

    public function listAudit(Request $request, Response $response): Response
    {
        $page = max(1, (int)($request->getQueryParams()['page'] ?? 1));
        $limit = (int)($request->getQueryParams()['limit'] ?? 50);
        $action = trim((string)($request->getQueryParams()['action'] ?? ''));
        $targetType = trim((string)($request->getQueryParams()['target_type'] ?? ''));
        $dateFrom = trim((string)($request->getQueryParams()['date_from'] ?? ''));
        $dateTo = trim((string)($request->getQueryParams()['date_to'] ?? ''));
        if ($limit < 1) {
            $limit = 50;
        }
        if ($limit > 200) {
            $limit = 200;
        }
        $offset = ($page - 1) * $limit;

        $where = [];
        $params = [];
        if ($action !== '') {
            $where[] = 'action = :action';
            $params['action'] = $action;
        }
        if ($targetType !== '') {
            $where[] = 'target_type = :target_type';
            $params['target_type'] = $targetType;
        }
        if ($dateFrom !== '') {
            $where[] = 'created_at >= CAST(:date_from AS timestamptz)';
            $params['date_from'] = $dateFrom;
        }
        if ($dateTo !== '') {
            $where[] = 'created_at <= CAST(:date_to AS timestamptz)';
            $params['date_to'] = $dateTo;
        }
        $whereSql = $where !== [] ? (' WHERE ' . implode(' AND ', $where)) : '';

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM audit_log' . $whereSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            'SELECT id, actor_user_id, action, target_type, target_id, details, created_at
             FROM audit_log
             ' . $whereSql . '
             ORDER BY created_at DESC
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
            $row['details'] = $this->decodeJsonValue($row['details'] ?? null);
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

    private function writeAudit(string $actorUserId, string $action, string $targetType, ?string $targetId, array $details): void
    {
        $detailsJson = json_encode($details, JSON_UNESCAPED_UNICODE);
        if ($detailsJson === false) {
            $detailsJson = '{}';
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_log (actor_user_id, action, target_type, target_id, details)
             VALUES (:actor_user_id, :action, :target_type, :target_id, CAST(:details AS jsonb))'
        );
        $stmt->execute([
            'actor_user_id' => $actorUserId === '' ? null : $actorUserId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'details' => $detailsJson,
        ]);
    }

    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write((string)json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }

    private function decodeJsonValue(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    private function isAllowedSettingKey(string $key): bool
    {
        return in_array($key, self::ALLOWED_SETTING_KEYS, true)
            || str_starts_with($key, 'feature.')
            || str_starts_with($key, 'integration.');
    }
}
