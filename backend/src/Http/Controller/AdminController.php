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

    public function listClassifiers(Request $request, Response $response): Response
    {
        if (!$this->hasKlsSchema()) {
            return $this->json($response, ['data' => []]);
        }

        $stmt = $this->pdo->query(
            'SELECT qual_id, qual_is_del, qual_type_id, qual_namef, qual_names, qual_code, qual_note, qual_vers, tag
             FROM kls.qual
             WHERE qual_is_del = FALSE
             ORDER BY qual_namef ASC'
        );
        $rows = $stmt->fetchAll();

        return $this->json($response, ['data' => $rows]);
    }

    public function createClassifier(Request $request, Response $response): Response
    {
        if (!$this->hasKlsSchema()) {
            return $this->json($response, ['error' => 'KLS schema is not available'], 422);
        }

        $payload = (array)$request->getParsedBody();
        $name = trim((string)($payload['qual_namef'] ?? ''));
        if ($name === '') {
            return $this->json($response, ['error' => 'Field qual_namef is required'], 422);
        }

        $shortName = trim((string)($payload['qual_names'] ?? ''));
        $code = trim((string)($payload['qual_code'] ?? ''));
        $note = trim((string)($payload['qual_note'] ?? ''));
        $qualTypeId = (int)($payload['qual_type_id'] ?? 0);
        if ($qualTypeId <= 0) {
            $qualTypeId = 1;
        }
        $tag = $this->normalizeHstoreInput($payload['tag'] ?? null);

        $stmt = $this->pdo->prepare(
            'INSERT INTO kls.qual (qual_type_id, qual_namef, qual_names, qual_code, qual_note, tag, qual_is_del, qual_vers)
             VALUES (:qual_type_id, :qual_namef, :qual_names, :qual_code, :qual_note, :tag, FALSE, 1)
             RETURNING qual_id'
        );
        $stmt->execute([
            'qual_type_id' => $qualTypeId,
            'qual_namef' => $name,
            'qual_names' => $shortName === '' ? null : $shortName,
            'qual_code' => $code === '' ? null : $code,
            'qual_note' => $note === '' ? null : $note,
            'tag' => $tag,
        ]);
        $id = (string)$stmt->fetchColumn();

        $this->writeAudit(
            (string)$request->getAttribute('user_id', ''),
            'admin.classifier.create',
            'kls.qual',
            $id,
            ['qual_namef' => $name]
        );

        return $this->json($response, ['data' => ['qual_id' => $id]], 201);
    }

    public function updateClassifier(Request $request, Response $response, array $args): Response
    {
        if (!$this->hasKlsSchema()) {
            return $this->json($response, ['error' => 'KLS schema is not available'], 422);
        }

        $id = (string)($args['id'] ?? '');
        if ($id === '') {
            return $this->json($response, ['error' => 'Classifier id is required'], 422);
        }

        $payload = (array)$request->getParsedBody();
        $fields = [];
        $params = ['id' => $id];

        if (array_key_exists('qual_namef', $payload)) {
            $name = trim((string)$payload['qual_namef']);
            if ($name === '') {
                return $this->json($response, ['error' => 'Field qual_namef cannot be empty'], 422);
            }
            $fields[] = 'qual_namef = :qual_namef';
            $params['qual_namef'] = $name;
        }
        if (array_key_exists('qual_names', $payload)) {
            $value = trim((string)$payload['qual_names']);
            $fields[] = 'qual_names = :qual_names';
            $params['qual_names'] = $value === '' ? null : $value;
        }
        if (array_key_exists('qual_code', $payload)) {
            $value = trim((string)$payload['qual_code']);
            $fields[] = 'qual_code = :qual_code';
            $params['qual_code'] = $value === '' ? null : $value;
        }
        if (array_key_exists('qual_note', $payload)) {
            $value = trim((string)$payload['qual_note']);
            $fields[] = 'qual_note = :qual_note';
            $params['qual_note'] = $value === '' ? null : $value;
        }
        if (array_key_exists('qual_type_id', $payload)) {
            $typeId = (int)$payload['qual_type_id'];
            if ($typeId <= 0) {
                return $this->json($response, ['error' => 'Field qual_type_id is invalid'], 422);
            }
            $fields[] = 'qual_type_id = :qual_type_id';
            $params['qual_type_id'] = $typeId;
        }
        if (array_key_exists('tag', $payload)) {
            $fields[] = 'tag = :tag';
            $params['tag'] = $this->normalizeHstoreInput($payload['tag']);
        }

        if ($fields === []) {
            return $this->json($response, ['error' => 'No fields to update'], 422);
        }

        $sql = 'UPDATE kls.qual SET ' . implode(', ', $fields) . ', qual_vers = qual_vers + 1 WHERE qual_id = :id AND qual_is_del = FALSE';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->rowCount() === 0) {
            return $this->json($response, ['error' => 'Classifier not found or unchanged'], 404);
        }

        $this->writeAudit(
            (string)$request->getAttribute('user_id', ''),
            'admin.classifier.update',
            'kls.qual',
            $id,
            ['fields' => array_keys($payload)]
        );

        return $this->json($response, ['data' => ['qual_id' => $id]]);
    }

    public function deleteClassifier(Request $request, Response $response, array $args): Response
    {
        if (!$this->hasKlsSchema()) {
            return $this->json($response, ['error' => 'KLS schema is not available'], 422);
        }

        $id = (string)($args['id'] ?? '');
        if ($id === '') {
            return $this->json($response, ['error' => 'Classifier id is required'], 422);
        }

        $stmt = $this->pdo->prepare('UPDATE kls.qual SET qual_is_del = TRUE, qual_vers = qual_vers + 1 WHERE qual_id = :id AND qual_is_del = FALSE');
        $stmt->execute(['id' => $id]);
        if ($stmt->rowCount() === 0) {
            return $this->json($response, ['error' => 'Classifier not found'], 404);
        }

        $this->pdo->prepare('UPDATE kls.kls SET kls_is_del = TRUE, kls_vers = kls_vers + 1 WHERE qual_id = :id AND kls_is_del = FALSE')
            ->execute(['id' => $id]);

        $this->writeAudit(
            (string)$request->getAttribute('user_id', ''),
            'admin.classifier.delete',
            'kls.qual',
            $id,
            []
        );

        return $response->withStatus(204);
    }

    public function listClassifierSections(Request $request, Response $response, array $args): Response
    {
        if (!$this->hasKlsSchema()) {
            return $this->json($response, ['data' => []]);
        }

        $qualId = (string)($args['id'] ?? '');
        if ($qualId === '') {
            return $this->json($response, ['error' => 'Classifier id is required'], 422);
        }

        $stmt = $this->pdo->prepare(
            'SELECT kls_id, kls_is_del, qual_id, kls_namef, kls_names, kls_note, tags, kls_code, kls_vers,
                    kls_rubrika::text AS kls_rubrika,
                    CASE WHEN nlevel(kls_rubrika) > 1 THEN subpath(kls_rubrika, 0, -1)::text ELSE NULL END AS parent_rubrika
             FROM kls.kls
             WHERE qual_id = :qual_id AND kls_is_del = FALSE
             ORDER BY kls_rubrika::text'
        );
        $stmt->execute(['qual_id' => $qualId]);
        return $this->json($response, ['data' => $stmt->fetchAll()]);
    }

    public function createClassifierSection(Request $request, Response $response, array $args): Response
    {
        if (!$this->hasKlsSchema()) {
            return $this->json($response, ['error' => 'KLS schema is not available'], 422);
        }

        $qualId = (string)($args['id'] ?? '');
        if ($qualId === '') {
            return $this->json($response, ['error' => 'Classifier id is required'], 422);
        }
        $payload = (array)$request->getParsedBody();
        $name = trim((string)($payload['kls_namef'] ?? ''));
        if ($name === '') {
            return $this->json($response, ['error' => 'Field kls_namef is required'], 422);
        }
        $code = trim((string)($payload['kls_code'] ?? ''));
        if ($code === '') {
            return $this->json($response, ['error' => 'Field kls_code is required'], 422);
        }
        $parentId = trim((string)($payload['parent_kls_id'] ?? ''));

        $nextIdStmt = $this->pdo->query('SELECT nextval(\'kls.kls_kls_id_seq\'::regclass)');
        $nextId = (string)$nextIdStmt->fetchColumn();
        if ($nextId === '') {
            return $this->json($response, ['error' => 'Failed to allocate kls_id'], 500);
        }

        $rubrika = $nextId;
        if ($parentId !== '') {
            $parentStmt = $this->pdo->prepare('SELECT kls_rubrika::text AS rubrika FROM kls.kls WHERE kls_id = :id AND qual_id = :qual_id AND kls_is_del = FALSE');
            $parentStmt->execute(['id' => $parentId, 'qual_id' => $qualId]);
            $parent = $parentStmt->fetch();
            if (!$parent) {
                return $this->json($response, ['error' => 'Parent section not found'], 404);
            }
            $rubrika = (string)$parent['rubrika'] . '.' . $nextId;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO kls.kls (kls_id, qual_id, kls_namef, kls_names, kls_note, tags, kls_code, kls_vers, kls_rubrika, kls_is_del)
             VALUES (:kls_id, :qual_id, :kls_namef, :kls_names, :kls_note, :tags, :kls_code, 1, CAST(:kls_rubrika AS ltree), FALSE)'
        );
        $stmt->execute([
            'kls_id' => $nextId,
            'qual_id' => $qualId,
            'kls_namef' => $name,
            'kls_names' => trim((string)($payload['kls_names'] ?? '')) ?: null,
            'kls_note' => trim((string)($payload['kls_note'] ?? '')) ?: null,
            'tags' => $this->normalizeHstoreInput($payload['tags'] ?? null),
            'kls_code' => $code,
            'kls_rubrika' => $rubrika,
        ]);

        $this->writeAudit(
            (string)$request->getAttribute('user_id', ''),
            'admin.classifier.section.create',
            'kls.kls',
            $nextId,
            ['qual_id' => $qualId, 'parent_kls_id' => $parentId]
        );

        return $this->json($response, ['data' => ['kls_id' => $nextId]], 201);
    }

    public function updateClassifierSection(Request $request, Response $response, array $args): Response
    {
        if (!$this->hasKlsSchema()) {
            return $this->json($response, ['error' => 'KLS schema is not available'], 422);
        }

        $id = (string)($args['id'] ?? '');
        if ($id === '') {
            return $this->json($response, ['error' => 'Section id is required'], 422);
        }
        $payload = (array)$request->getParsedBody();
        $fields = [];
        $params = ['id' => $id];

        if (array_key_exists('kls_namef', $payload)) {
            $value = trim((string)$payload['kls_namef']);
            if ($value === '') return $this->json($response, ['error' => 'Field kls_namef cannot be empty'], 422);
            $fields[] = 'kls_namef = :kls_namef';
            $params['kls_namef'] = $value;
        }
        if (array_key_exists('kls_names', $payload)) {
            $value = trim((string)$payload['kls_names']);
            $fields[] = 'kls_names = :kls_names';
            $params['kls_names'] = $value === '' ? null : $value;
        }
        if (array_key_exists('kls_note', $payload)) {
            $value = trim((string)$payload['kls_note']);
            $fields[] = 'kls_note = :kls_note';
            $params['kls_note'] = $value === '' ? null : $value;
        }
        if (array_key_exists('kls_code', $payload)) {
            $value = trim((string)$payload['kls_code']);
            if ($value === '') return $this->json($response, ['error' => 'Field kls_code cannot be empty'], 422);
            $fields[] = 'kls_code = :kls_code';
            $params['kls_code'] = $value;
        }
        if (array_key_exists('tags', $payload)) {
            $fields[] = 'tags = :tags';
            $params['tags'] = $this->normalizeHstoreInput($payload['tags']);
        }

        if ($fields === []) {
            return $this->json($response, ['error' => 'No fields to update'], 422);
        }

        $stmt = $this->pdo->prepare('UPDATE kls.kls SET ' . implode(', ', $fields) . ', kls_vers = kls_vers + 1 WHERE kls_id = :id AND kls_is_del = FALSE');
        $stmt->execute($params);
        if ($stmt->rowCount() === 0) {
            return $this->json($response, ['error' => 'Section not found or unchanged'], 404);
        }

        $this->writeAudit(
            (string)$request->getAttribute('user_id', ''),
            'admin.classifier.section.update',
            'kls.kls',
            $id,
            ['fields' => array_keys($payload)]
        );

        return $this->json($response, ['data' => ['kls_id' => $id]]);
    }

    public function deleteClassifierSection(Request $request, Response $response, array $args): Response
    {
        if (!$this->hasKlsSchema()) {
            return $this->json($response, ['error' => 'KLS schema is not available'], 422);
        }

        $id = (string)($args['id'] ?? '');
        if ($id === '') {
            return $this->json($response, ['error' => 'Section id is required'], 422);
        }

        $stmt = $this->pdo->prepare('UPDATE kls.kls SET kls_is_del = TRUE, kls_vers = kls_vers + 1 WHERE kls_id = :id AND kls_is_del = FALSE');
        $stmt->execute(['id' => $id]);
        if ($stmt->rowCount() === 0) {
            return $this->json($response, ['error' => 'Section not found'], 404);
        }

        $this->writeAudit(
            (string)$request->getAttribute('user_id', ''),
            'admin.classifier.section.delete',
            'kls.kls',
            $id,
            []
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

    private function hasKlsSchema(): bool
    {
        $stmt = $this->pdo->query('SELECT to_regclass(\'kls.qual\') IS NOT NULL AS has_qual, to_regclass(\'kls.kls\') IS NOT NULL AS has_kls');
        $row = $stmt->fetch();
        return (bool)($row['has_qual'] ?? false) && (bool)($row['has_kls'] ?? false);
    }

    private function normalizeHstoreInput(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            return $trimmed === '' ? null : $trimmed;
        }
        if (is_array($value)) {
            $pairs = [];
            foreach ($value as $key => $item) {
                $k = str_replace('"', '\"', (string)$key);
                $v = str_replace('"', '\"', (string)$item);
                $pairs[] = '"' . $k . '"=>"' . $v . '"';
            }
            return $pairs === [] ? null : implode(',', $pairs);
        }
        return null;
    }
}
