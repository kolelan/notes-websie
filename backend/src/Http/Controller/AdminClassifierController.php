<?php

declare(strict_types=1);

namespace App\Http\Controller;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AdminClassifierController
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function listClassifiers(Request $request, Response $response): Response
    {
        if (!$this->hasKlsSchema()) {
            return $this->json($response, ['data' => [], 'meta' => ['page' => 1, 'limit' => 20, 'total' => 0, 'pages' => 1]]);
        }
        $page = max(1, (int)($request->getQueryParams()['page'] ?? 1));
        $limit = max(1, min(200, (int)($request->getQueryParams()['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $params = [];
        $where = [];
        $id = trim((string)($request->getQueryParams()['qual_id'] ?? ''));
        if ($id !== '') {
            $where[] = 'q.qual_id = :qual_id';
            $params['qual_id'] = $id;
        }
        $this->addIlikeFilter($where, $params, 'q.qual_code', 'qual_code', $request);
        $this->addIlikeFilter($where, $params, 'q.qual_namef', 'qual_namef', $request);
        $this->addIlikeFilter($where, $params, 'q.qual_names', 'qual_names', $request);
        $this->addIlikeFilter($where, $params, 'q.qual_note', 'qual_note', $request);
        $whereSql = $where === [] ? '' : (' WHERE ' . implode(' AND ', $where));

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM kls.qual q' . $whereSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            'SELECT q.qual_id, q.qual_is_del, q.qual_type_id, q.qual_namef, q.qual_names, q.qual_code, q.qual_note, q.qual_vers, q.tag
             FROM kls.qual q' . $whereSql . '
             ORDER BY q.qual_namef ASC
             LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $this->json($response, [
            'data' => $stmt->fetchAll(),
            'meta' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => (int)max(1, ceil($total / $limit))],
        ]);
    }

    public function createClassifier(Request $request, Response $response): Response
    {
        if (!$this->hasKlsSchema()) return $this->json($response, ['error' => 'KLS schema is not available'], 422);
        $payload = (array)$request->getParsedBody();
        $name = trim((string)($payload['qual_namef'] ?? ''));
        if ($name === '') return $this->json($response, ['error' => 'Field qual_namef is required'], 422);

        $qualTypeId = (int)($payload['qual_type_id'] ?? 0);
        if ($qualTypeId <= 0) {
            $qualTypeId = $this->getDefaultQualTypeId();
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO kls.qual (qual_is_del, qual_vers, qual_type_id, qual_namef, qual_names, qual_code, qual_note, tag)
             VALUES (FALSE, 1, :qual_type_id, :qual_namef, :qual_names, :qual_code, :qual_note, :tag)
             RETURNING qual_id'
        );
        $stmt->execute([
            'qual_type_id' => $qualTypeId,
            'qual_namef' => $name,
            'qual_names' => $this->nullIfEmpty($payload['qual_names'] ?? null),
            'qual_code' => $this->nullIfEmpty($payload['qual_code'] ?? null),
            'qual_note' => $this->nullIfEmpty($payload['qual_note'] ?? null),
            'tag' => $this->toHstoreString($payload['tag'] ?? null),
        ]);
        $id = (string)$stmt->fetchColumn();
        $this->audit($request, 'admin.classifier.create', 'kls.qual', $id, ['qual_namef' => $name]);
        return $this->json($response, ['data' => ['qual_id' => $id]], 201);
    }

    public function updateClassifier(Request $request, Response $response, array $args): Response
    {
        if (!$this->hasKlsSchema()) return $this->json($response, ['error' => 'KLS schema is not available'], 422);
        $id = trim((string)($args['id'] ?? ''));
        if ($id === '') return $this->json($response, ['error' => 'Classifier id is required'], 422);
        $payload = (array)$request->getParsedBody();
        $fields = [];
        $params = ['id' => $id];

        $this->addSetField($fields, $params, $payload, 'qual_namef', true);
        $this->addSetField($fields, $params, $payload, 'qual_names');
        $this->addSetField($fields, $params, $payload, 'qual_code');
        $this->addSetField($fields, $params, $payload, 'qual_note');
        if (array_key_exists('qual_type_id', $payload) && (int)$payload['qual_type_id'] > 0) {
            $fields[] = 'qual_type_id = :qual_type_id';
            $params['qual_type_id'] = (int)$payload['qual_type_id'];
        }
        if (array_key_exists('tag', $payload)) {
            $fields[] = 'tag = :tag';
            $params['tag'] = $this->toHstoreString($payload['tag']);
        }
        if ($fields === []) return $this->json($response, ['error' => 'No fields to update'], 422);

        $stmt = $this->pdo->prepare('UPDATE kls.qual SET ' . implode(', ', $fields) . ', qual_vers = qual_vers + 1 WHERE qual_id = :id');
        $stmt->execute($params);
        if ($stmt->rowCount() === 0) return $this->json($response, ['error' => 'Classifier not found or unchanged'], 404);
        $this->audit($request, 'admin.classifier.update', 'kls.qual', $id, ['fields' => array_keys($payload)]);
        return $this->json($response, ['data' => ['qual_id' => $id]]);
    }

    public function deleteClassifier(Request $request, Response $response, array $args): Response
    {
        if (!$this->hasKlsSchema()) return $this->json($response, ['error' => 'KLS schema is not available'], 422);
        $id = trim((string)($args['id'] ?? ''));
        if ($id === '') return $this->json($response, ['error' => 'Classifier id is required'], 422);
        $stmt = $this->pdo->prepare('DELETE FROM kls.qual WHERE qual_id = :id');
        $stmt->execute(['id' => $id]);
        if ($stmt->rowCount() === 0) return $this->json($response, ['error' => 'Classifier not found'], 404);
        $this->audit($request, 'admin.classifier.delete', 'kls.qual', $id, []);
        return $response->withStatus(204);
    }

    public function copyClassifier(Request $request, Response $response, array $args): Response
    {
        if (!$this->hasKlsSchema()) return $this->json($response, ['error' => 'KLS schema is not available'], 422);
        $id = trim((string)($args['id'] ?? ''));
        if ($id === '') return $this->json($response, ['error' => 'Classifier id is required'], 422);
        $stmt = $this->pdo->prepare('SELECT qual_type_id, qual_namef, qual_names, qual_code, qual_note, tag FROM kls.qual WHERE qual_id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!$row) return $this->json($response, ['error' => 'Classifier not found'], 404);
        $hash = $this->suffixHash();
        $newCode = ($row['qual_code'] ?? 'QUAL') . '_' . $hash;
        $insert = $this->pdo->prepare(
            'INSERT INTO kls.qual (qual_is_del, qual_vers, qual_type_id, qual_namef, qual_names, qual_code, qual_note, tag)
             VALUES (FALSE, 1, :qual_type_id, :qual_namef, :qual_names, :qual_code, :qual_note, :tag)
             RETURNING qual_id'
        );
        $insert->execute([
            'qual_type_id' => (int)$row['qual_type_id'],
            'qual_namef' => (string)$row['qual_namef'],
            'qual_names' => $row['qual_names'],
            'qual_code' => $newCode,
            'qual_note' => $row['qual_note'],
            'tag' => $row['tag'],
        ]);
        $newId = (string)$insert->fetchColumn();
        $this->audit($request, 'admin.classifier.copy', 'kls.qual', $newId, ['source_id' => $id]);
        return $this->json($response, ['data' => ['qual_id' => $newId]], 201);
    }

    public function bulkClassifierAction(Request $request, Response $response): Response
    {
        if (!$this->hasKlsSchema()) return $this->json($response, ['error' => 'KLS schema is not available'], 422);
        $payload = (array)$request->getParsedBody();
        $ids = array_values(array_filter(array_map('strval', (array)($payload['ids'] ?? []))));
        $action = trim((string)($payload['action'] ?? ''));
        if ($ids === [] || !in_array($action, ['enable', 'disable', 'delete'], true)) {
            return $this->json($response, ['error' => 'Fields ids[] and valid action are required'], 422);
        }
        $count = $this->applyBulkAction('kls.qual', 'qual_id', 'qual_is_del', $ids, $action);
        $this->audit($request, 'admin.classifier.bulk', 'kls.qual', null, ['action' => $action, 'count' => $count]);
        return $this->json($response, ['data' => ['count' => $count]]);
    }

    public function listClassifierItems(Request $request, Response $response): Response
    {
        if (!$this->hasKlsSchema()) return $this->json($response, ['error' => 'KLS schema is not available'], 422);
        $page = max(1, (int)($request->getQueryParams()['page'] ?? 1));
        $limit = max(1, min(200, (int)($request->getQueryParams()['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        $params = [];
        $where = [];
        $id = trim((string)($request->getQueryParams()['kls_id'] ?? ''));
        if ($id !== '') {
            $where[] = 'v.kls_id::text = :kls_id';
            $params['kls_id'] = $id;
        }
        $this->addExactFilter($where, $params, 'v.qual_id', 'qual_id', $request);
        $this->addIlikeFilter($where, $params, 'v.qual_code', 'qual_code', $request);
        $this->addIlikeFilter($where, $params, 'v.qual_namef', 'qual_namef', $request);
        $this->addIlikeFilter($where, $params, 'v.kls_code', 'kls_code', $request);
        $this->addIlikeFilter($where, $params, 'v.kls_namef', 'kls_namef', $request);
        $this->addIlikeFilter($where, $params, 'v.kls_names', 'kls_names', $request);
        $this->addIlikeFilter($where, $params, 'v.kls_note', 'kls_note', $request);
        $this->addIlikeFilter($where, $params, 'v.kls_code_parent', 'kls_code_parent', $request);
        $this->addIlikeFilter($where, $params, 'v.kls_namef_parent', 'kls_namef_parent', $request);
        $whereSql = $where === [] ? '' : (' WHERE ' . implode(' AND ', $where));

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM kls.v_kls v' . $whereSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            'SELECT v.kls_id, v.qual_id, v.kls_namef, v.kls_names, v.kls_note, v.tags, v.kls_code, v.kls_rubrika::text AS kls_rubrika,
                    v.kls_id_parent, v.kls_code_parent, v.kls_namef_parent, v.qual_code, v.qual_namef
             FROM kls.v_kls v' . $whereSql . '
             ORDER BY v.kls_rubrika::text
             LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $this->json($response, [
            'data' => $stmt->fetchAll(),
            'meta' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => (int)max(1, ceil($total / $limit))],
        ]);
    }

    public function createClassifierItem(Request $request, Response $response): Response
    {
        if (!$this->hasKlsSchema()) return $this->json($response, ['error' => 'KLS schema is not available'], 422);
        $payload = (array)$request->getParsedBody();
        $qualId = trim((string)($payload['qual_id'] ?? ''));
        $namef = trim((string)($payload['kls_namef'] ?? ''));
        $code = trim((string)($payload['kls_code'] ?? ''));
        if ($qualId === '' || $namef === '' || $code === '') {
            return $this->json($response, ['error' => 'Fields qual_id, kls_namef, kls_code are required'], 422);
        }

        $newId = (string)$this->pdo->query('SELECT nextval(\'kls.kls_kls_id_seq\'::regclass)')->fetchColumn();
        $parentId = trim((string)($payload['parent_kls_id'] ?? ''));
        $parentRubrika = $this->resolveParentRubrika($qualId, $parentId);
        if ($parentId !== '' && $parentRubrika === null) {
            return $this->json($response, ['error' => 'Parent item not found'], 404);
        }

        $requestedRubrika = trim((string)($payload['kls_rubrika'] ?? ''));
        if ($requestedRubrika !== '' && !$this->isValidRubrikaText($requestedRubrika)) {
            return $this->json($response, ['error' => 'Field kls_rubrika has invalid format. Use numbers with dots, for example 1.2.3'], 422);
        }

        $rubrika = $requestedRubrika !== ''
            ? $this->validateCreateRubrika($requestedRubrika, $parentRubrika)
            : $this->buildNextRubrikaAtLevel($qualId, $parentRubrika);
        if ($rubrika === null) {
            return $this->json($response, ['error' => 'Field kls_rubrika does not match selected parent level'], 422);
        }

        $this->pdo->beginTransaction();
        try {
            $this->shiftSiblingRubrika($qualId, $rubrika, +1);
            $stmt = $this->pdo->prepare(
                'INSERT INTO kls.kls (kls_id, kls_is_del, qual_id, kls_namef, kls_names, kls_note, tags, kls_code, kls_vers, kls_rubrika)
                 VALUES (:kls_id, FALSE, :qual_id, :kls_namef, :kls_names, :kls_note, :tags, :kls_code, 1, CAST(:kls_rubrika AS ltree))'
            );
            $stmt->execute([
                'kls_id' => $newId,
                'qual_id' => $qualId,
                'kls_namef' => $namef,
                'kls_names' => $this->nullIfEmpty($payload['kls_names'] ?? null),
                'kls_note' => $this->nullIfEmpty($payload['kls_note'] ?? null),
                'tags' => $this->toHstoreString($payload['tags'] ?? null),
                'kls_code' => $code,
                'kls_rubrika' => $rubrika,
            ]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            return $this->json($response, ['error' => 'Failed to create item with requested kls_rubrika'], 422);
        }
        $this->audit($request, 'admin.classifier.item.create', 'kls.kls', $newId, ['qual_id' => $qualId]);
        return $this->json($response, ['data' => ['kls_id' => $newId]], 201);
    }

    public function updateClassifierItem(Request $request, Response $response, array $args): Response
    {
        if (!$this->hasKlsSchema()) return $this->json($response, ['error' => 'KLS schema is not available'], 422);
        $id = trim((string)($args['id'] ?? ''));
        if ($id === '') return $this->json($response, ['error' => 'Item id is required'], 422);
        $payload = (array)$request->getParsedBody();
        $currentStmt = $this->pdo->prepare('SELECT qual_id, kls_rubrika::text AS rubrika FROM kls.kls WHERE kls_id = :id LIMIT 1');
        $currentStmt->execute(['id' => $id]);
        $current = $currentStmt->fetch();
        if (!$current) return $this->json($response, ['error' => 'Item not found'], 404);

        $fields = [];
        $params = ['id' => $id];
        if (array_key_exists('qual_id', $payload)) {
            $nextQualId = trim((string)$payload['qual_id']);
            if ($nextQualId === '') {
                return $this->json($response, ['error' => 'Field qual_id cannot be empty'], 422);
            }
            $existsStmt = $this->pdo->prepare('SELECT 1 FROM kls.qual WHERE qual_id::text = :qual_id LIMIT 1');
            $existsStmt->execute(['qual_id' => $nextQualId]);
            if (!$existsStmt->fetchColumn()) {
                return $this->json($response, ['error' => 'Target classifier (qual_id) not found'], 404);
            }
            $fields[] = 'qual_id = :qual_id';
            $params['qual_id'] = $nextQualId;
        }
        $this->addSetField($fields, $params, $payload, 'kls_namef', true);
        $this->addSetField($fields, $params, $payload, 'kls_names');
        $this->addSetField($fields, $params, $payload, 'kls_note');
        $this->addSetField($fields, $params, $payload, 'kls_code', true);
        if (array_key_exists('tags', $payload)) {
            $fields[] = 'tags = :tags';
            $params['tags'] = $this->toHstoreString($payload['tags']);
        }
        $requestedRubrika = trim((string)($payload['kls_rubrika'] ?? ''));
        $currentRubrika = (string)$current['rubrika'];
        $currentQualId = (string)$current['qual_id'];
        $shouldUpdateRubrika = $requestedRubrika !== '' && $requestedRubrika !== $currentRubrika;
        if ($requestedRubrika !== '' && !$this->isValidRubrikaText($requestedRubrika)) {
            return $this->json($response, ['error' => 'Field kls_rubrika has invalid format. Use numbers with dots, for example 1.2.3'], 422);
        }

        if ($fields === [] && !$shouldUpdateRubrika) return $this->json($response, ['error' => 'No fields to update'], 422);

        $this->pdo->beginTransaction();
        try {
            if ($shouldUpdateRubrika) {
                $this->reorderRubrikaOnSameLevel($currentQualId, $id, $currentRubrika, $requestedRubrika);
            }
            if ($fields !== []) {
                $stmt = $this->pdo->prepare('UPDATE kls.kls SET ' . implode(', ', $fields) . ', kls_vers = kls_vers + 1 WHERE kls_id = :id');
                $stmt->execute($params);
                if ($stmt->rowCount() === 0 && !$shouldUpdateRubrika) {
                    $this->pdo->rollBack();
                    return $this->json($response, ['error' => 'Item not found or unchanged'], 404);
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            $payload = ['error' => 'Failed to update kls_rubrika on current level'];
            if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
                $payload['details'] = $e->getMessage();
            }
            return $this->json($response, $payload, 422);
        }
        $this->audit($request, 'admin.classifier.item.update', 'kls.kls', $id, ['fields' => array_keys($payload)]);
        return $this->json($response, ['data' => ['kls_id' => $id]]);
    }

    public function deleteClassifierItem(Request $request, Response $response, array $args): Response
    {
        if (!$this->hasKlsSchema()) return $this->json($response, ['error' => 'KLS schema is not available'], 422);
        $id = trim((string)($args['id'] ?? ''));
        if ($id === '') return $this->json($response, ['error' => 'Item id is required'], 422);
        $stmt = $this->pdo->prepare('DELETE FROM kls.kls WHERE kls_id = :id');
        $stmt->execute(['id' => $id]);
        if ($stmt->rowCount() === 0) return $this->json($response, ['error' => 'Item not found'], 404);
        $this->audit($request, 'admin.classifier.item.delete', 'kls.kls', $id, []);
        return $response->withStatus(204);
    }

    public function copyClassifierItem(Request $request, Response $response, array $args): Response
    {
        if (!$this->hasKlsSchema()) return $this->json($response, ['error' => 'KLS schema is not available'], 422);
        $id = trim((string)($args['id'] ?? ''));
        if ($id === '') return $this->json($response, ['error' => 'Item id is required'], 422);
        $stmt = $this->pdo->prepare('SELECT qual_id, kls_namef, kls_names, kls_note, tags, kls_code, kls_rubrika::text AS kls_rubrika FROM kls.kls WHERE kls_id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!$row) return $this->json($response, ['error' => 'Item not found'], 404);

        $newId = (string)$this->pdo->query('SELECT nextval(\'kls.kls_kls_id_seq\'::regclass)')->fetchColumn();
        $parentRubrika = null;
        $rubrikaText = (string)$row['kls_rubrika'];
        if (str_contains($rubrikaText, '.')) {
            $parentRubrika = substr($rubrikaText, 0, (int)strrpos($rubrikaText, '.'));
        }
        $newRubrika = ($parentRubrika ? ($parentRubrika . '.') : '') . $newId;
        $newCode = (string)$row['kls_code'] . '_' . $this->suffixHash();

        $insert = $this->pdo->prepare(
            'INSERT INTO kls.kls (kls_id, kls_is_del, qual_id, kls_namef, kls_names, kls_note, tags, kls_code, kls_vers, kls_rubrika)
             VALUES (:kls_id, FALSE, :qual_id, :kls_namef, :kls_names, :kls_note, :tags, :kls_code, 1, CAST(:kls_rubrika AS ltree))'
        );
        $insert->execute([
            'kls_id' => $newId,
            'qual_id' => $row['qual_id'],
            'kls_namef' => $row['kls_namef'],
            'kls_names' => $row['kls_names'],
            'kls_note' => $row['kls_note'],
            'tags' => $row['tags'],
            'kls_code' => $newCode,
            'kls_rubrika' => $newRubrika,
        ]);
        $this->audit($request, 'admin.classifier.item.copy', 'kls.kls', $newId, ['source_id' => $id]);
        return $this->json($response, ['data' => ['kls_id' => $newId]], 201);
    }

    public function bulkClassifierItemAction(Request $request, Response $response): Response
    {
        if (!$this->hasKlsSchema()) return $this->json($response, ['error' => 'KLS schema is not available'], 422);
        $payload = (array)$request->getParsedBody();
        $ids = array_values(array_filter(array_map('strval', (array)($payload['ids'] ?? []))));
        $action = trim((string)($payload['action'] ?? ''));
        if ($ids === [] || !in_array($action, ['enable', 'disable', 'delete'], true)) {
            return $this->json($response, ['error' => 'Fields ids[] and valid action are required'], 422);
        }
        $count = $this->applyBulkAction('kls.kls', 'kls_id', 'kls_is_del', $ids, $action);
        $this->audit($request, 'admin.classifier.item.bulk', 'kls.kls', null, ['action' => $action, 'count' => $count]);
        return $this->json($response, ['data' => ['count' => $count]]);
    }

    private function resolveParentRubrika(string $qualId, string $parentId): ?string
    {
        if ($parentId === '') return null;
        $parent = $this->pdo->prepare('SELECT kls_rubrika::text AS rubrika FROM kls.kls WHERE kls_id = :id AND qual_id = :qual_id LIMIT 1');
        $parent->execute(['id' => $parentId, 'qual_id' => $qualId]);
        $parentRow = $parent->fetch();
        if (!$parentRow) return null;
        return (string)$parentRow['rubrika'];
    }

    private function isValidRubrikaText(string $rubrika): bool
    {
        return preg_match('/^\d+(?:\.\d+)*$/', $rubrika) === 1;
    }

    private function validateCreateRubrika(string $rubrika, ?string $parentRubrika): ?string
    {
        if ($parentRubrika === null) {
            return str_contains($rubrika, '.') ? null : $rubrika;
        }
        if (!str_starts_with($rubrika, $parentRubrika . '.')) return null;
        $tail = substr($rubrika, strlen($parentRubrika) + 1);
        if ($tail === false || str_contains($tail, '.')) return null;
        return $rubrika;
    }

    private function buildNextRubrikaAtLevel(string $qualId, ?string $parentRubrika): string
    {
        if ($parentRubrika === null) {
            $stmt = $this->pdo->prepare(
                "SELECT COALESCE(MAX((kls_rubrika::text)::int), 0) AS max_idx
                 FROM kls.kls
                 WHERE qual_id = :qual_id AND nlevel(kls_rubrika) = 1"
            );
            $stmt->execute(['qual_id' => $qualId]);
            return (string)(((int)$stmt->fetchColumn()) + 1);
        }
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(MAX(split_part(kls_rubrika::text, '.', nlevel(kls_rubrika))::int), 0) AS max_idx
             FROM kls.kls
             WHERE qual_id = :qual_id
               AND nlevel(kls_rubrika) = nlevel(CAST(:parent AS ltree)) + 1
               AND subpath(kls_rubrika, 0, nlevel(kls_rubrika) - 1) = CAST(:parent AS ltree)"
        );
        $stmt->execute(['qual_id' => $qualId, 'parent' => $parentRubrika]);
        $next = ((int)$stmt->fetchColumn()) + 1;
        return $parentRubrika . '.' . $next;
    }

    private function reorderRubrikaOnSameLevel(string $qualId, string $currentId, string $currentRubrika, string $requestedRubrika): void
    {
        [$currentParent, $currentIndex, $currentLevel] = $this->rubrikaMeta($currentRubrika);
        [$targetParent, $targetIndex, $targetLevel] = $this->rubrikaMeta($requestedRubrika);
        if ($currentLevel !== $targetLevel || $currentParent !== $targetParent) {
            throw new \RuntimeException('Rubrika can be changed only within current nesting level.');
        }
        if ($currentIndex === $targetIndex) return;

        // Avoid index collision: move current subtree to temporary slot first.
        $tempRubrika = $this->buildTemporaryRubrika($qualId, $currentParent, $currentLevel);
        $this->moveRubrikaSubtree($qualId, $currentRubrika, $tempRubrika);

        if ($targetIndex < $currentIndex) {
            $stmt = $this->pdo->prepare(
                'SELECT kls_rubrika::text AS rubrika
                 FROM kls.kls
                 WHERE qual_id = :qual_id
                   AND kls_id::text <> :current_id
                   AND nlevel(kls_rubrika) = CAST(:level AS int)
                   AND split_part(kls_rubrika::text, \'.\', CAST(:level AS int))::int >= CAST(:target_idx AS int)
                   AND split_part(kls_rubrika::text, \'.\', CAST(:level AS int))::int < CAST(:current_idx AS int)
                   AND (
                        (:parent = \'\' AND nlevel(kls_rubrika) = 1)
                        OR (:parent <> \'\' AND subpath(kls_rubrika, 0, nlevel(kls_rubrika) - 1) = CAST(:parent AS ltree))
                   )
                 ORDER BY split_part(kls_rubrika::text, \'.\', CAST(:level AS int))::int DESC'
            );
            $stmt->execute([
                'qual_id' => $qualId,
                'current_id' => $currentId,
                'level' => $currentLevel,
                'target_idx' => $targetIndex,
                'current_idx' => $currentIndex,
                'parent' => $currentParent,
            ]);
            $this->shiftRowsByList($qualId, $stmt->fetchAll(), +1);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT kls_rubrika::text AS rubrika
                 FROM kls.kls
                 WHERE qual_id = :qual_id
                   AND kls_id::text <> :current_id
                   AND nlevel(kls_rubrika) = CAST(:level AS int)
                   AND split_part(kls_rubrika::text, \'.\', CAST(:level AS int))::int <= CAST(:target_idx AS int)
                   AND split_part(kls_rubrika::text, \'.\', CAST(:level AS int))::int > CAST(:current_idx AS int)
                   AND (
                        (:parent = \'\' AND nlevel(kls_rubrika) = 1)
                        OR (:parent <> \'\' AND subpath(kls_rubrika, 0, nlevel(kls_rubrika) - 1) = CAST(:parent AS ltree))
                   )
                 ORDER BY split_part(kls_rubrika::text, \'.\', CAST(:level AS int))::int ASC'
            );
            $stmt->execute([
                'qual_id' => $qualId,
                'current_id' => $currentId,
                'level' => $currentLevel,
                'target_idx' => $targetIndex,
                'current_idx' => $currentIndex,
                'parent' => $currentParent,
            ]);
            $this->shiftRowsByList($qualId, $stmt->fetchAll(), -1);
        }

        $this->moveRubrikaSubtree($qualId, $tempRubrika, $requestedRubrika);
    }

    private function shiftSiblingRubrika(string $qualId, string $fromRubrika, int $delta): void
    {
        [$parent, $fromIndex, $level] = $this->rubrikaMeta($fromRubrika);
        $stmt = $this->pdo->prepare(
            'SELECT kls_rubrika::text AS rubrika
             FROM kls.kls
             WHERE qual_id = :qual_id
               AND nlevel(kls_rubrika) = CAST(:level AS int)
               AND split_part(kls_rubrika::text, \'.\', CAST(:level AS int))::int >= CAST(:from_idx AS int)
               AND (
                    (:parent = \'\' AND nlevel(kls_rubrika) = 1)
                    OR (:parent <> \'\' AND subpath(kls_rubrika, 0, nlevel(kls_rubrika) - 1) = CAST(:parent AS ltree))
               )
             ORDER BY split_part(kls_rubrika::text, \'.\', CAST(:level AS int))::int DESC'
        );
        $stmt->execute([
            'qual_id' => $qualId,
            'level' => $level,
            'from_idx' => $fromIndex,
            'parent' => $parent,
        ]);
        $this->shiftRowsByList($qualId, $stmt->fetchAll(), $delta);
    }

    private function shiftRowsByList(string $qualId, array $rows, int $delta): void
    {
        foreach ($rows as $row) {
            $oldRubrika = (string)($row['rubrika'] ?? '');
            if ($oldRubrika === '') continue;
            [$parent, $index] = $this->rubrikaMeta($oldRubrika);
            $newRubrika = $this->composeRubrika($parent, $index + $delta);
            $this->moveRubrikaSubtree($qualId, $oldRubrika, $newRubrika);
        }
    }

    private function moveRubrikaSubtree(string $qualId, string $fromRubrika, string $toRubrika): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE kls.kls
             SET kls_rubrika = CAST(
                 CASE
                     WHEN kls_rubrika::text = CAST(:from_rubrika AS text) THEN CAST(:to_rubrika AS text)
                     ELSE CAST(:to_rubrika AS text) || \'.\' || substring(kls_rubrika::text from char_length(CAST(:from_rubrika AS text)) + 2)
                 END
                 AS ltree
             )
             WHERE qual_id = :qual_id
               AND kls_rubrika <@ CAST(:from_rubrika AS ltree)'
        );
        $stmt->execute([
            'to_rubrika' => $toRubrika,
            'from_rubrika' => $fromRubrika,
            'qual_id' => $qualId,
        ]);
    }

    private function rubrikaMeta(string $rubrika): array
    {
        $parts = explode('.', $rubrika);
        $index = (int)array_pop($parts);
        $parent = implode('.', $parts);
        return [$parent, $index, count($parts) + 1];
    }

    private function composeRubrika(string $parent, int $index): string
    {
        return $parent === '' ? (string)$index : ($parent . '.' . $index);
    }

    private function buildTemporaryRubrika(string $qualId, string $parent, int $level): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(MAX(split_part(kls_rubrika::text, \'.\', CAST(:level AS int))::int), 0) AS max_idx
             FROM kls.kls
             WHERE qual_id = :qual_id
               AND nlevel(kls_rubrika) = CAST(:level AS int)
               AND (
                    (:parent = \'\' AND nlevel(kls_rubrika) = 1)
                    OR (:parent <> \'\' AND subpath(kls_rubrika, 0, nlevel(kls_rubrika) - 1) = CAST(:parent AS ltree))
               )'
        );
        $stmt->execute([
            'qual_id' => $qualId,
            'level' => $level,
            'parent' => $parent,
        ]);
        $max = (int)$stmt->fetchColumn();
        return $this->composeRubrika($parent, $max + 1000);
    }

    private function applyBulkAction(string $table, string $idField, string $softDeleteField, array $ids, string $action): int
    {
        $holders = [];
        $params = [];
        foreach ($ids as $idx => $id) {
            $key = 'id' . $idx;
            $holders[] = ':' . $key;
            $params[$key] = $id;
        }
        $in = implode(',', $holders);
        if ($action === 'delete') {
            $stmt = $this->pdo->prepare("DELETE FROM {$table} WHERE {$idField}::text IN ({$in})");
            $stmt->execute($params);
            return $stmt->rowCount();
        }
        $value = $action === 'disable';
        $stmt = $this->pdo->prepare("UPDATE {$table} SET {$softDeleteField} = :value WHERE {$idField}::text IN ({$in})");
        $stmt->bindValue(':value', $value, PDO::PARAM_BOOL);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->execute();
        return $stmt->rowCount();
    }

    private function addSetField(array &$fields, array &$params, array $payload, string $field, bool $requiredNonEmpty = false): void
    {
        if (!array_key_exists($field, $payload)) return;
        $value = $this->nullIfEmpty($payload[$field]);
        if ($requiredNonEmpty && $value === null) return;
        $fields[] = $field . ' = :' . $field;
        $params[$field] = $value;
    }

    private function addIlikeFilter(array &$where, array &$params, string $column, string $queryName, Request $request): void
    {
        $value = trim((string)($request->getQueryParams()[$queryName] ?? ''));
        if ($value === '') return;
        $where[] = "{$column} ILIKE :{$queryName}";
        $params[$queryName] = '%' . $value . '%';
    }

    private function addExactFilter(array &$where, array &$params, string $column, string $queryName, Request $request): void
    {
        $value = trim((string)($request->getQueryParams()[$queryName] ?? ''));
        if ($value === '') return;
        $where[] = "{$column}::text = :{$queryName}";
        $params[$queryName] = $value;
    }

    private function nullIfEmpty(mixed $value): ?string
    {
        if ($value === null) return null;
        $s = trim((string)$value);
        return $s === '' ? null : $s;
    }

    private function toHstoreString(mixed $value): ?string
    {
        if ($value === null) return null;
        if (is_string($value)) {
            $trimmed = trim($value);
            return $trimmed === '' ? null : $trimmed;
        }
        if (is_array($value)) {
            $pairs = [];
            foreach ($value as $k => $v) {
                $key = str_replace('"', '\"', (string)$k);
                $val = str_replace('"', '\"', (string)$v);
                $pairs[] = '"' . $key . '"=>"' . $val . '"';
            }
            return $pairs === [] ? null : implode(',', $pairs);
        }
        return null;
    }

    private function getDefaultQualTypeId(): int
    {
        $stmt = $this->pdo->query('SELECT qual_type_id FROM kls.qual_type WHERE qual_type_is_del = FALSE ORDER BY qual_type_id LIMIT 1');
        $id = (int)$stmt->fetchColumn();
        return $id > 0 ? $id : 1;
    }

    private function suffixHash(): string
    {
        return substr(bin2hex(random_bytes(4)), 0, 8);
    }

    private function hasKlsSchema(): bool
    {
        $stmt = $this->pdo->query('SELECT to_regclass(\'kls.qual\') IS NOT NULL AS has_qual, to_regclass(\'kls.kls\') IS NOT NULL AS has_kls');
        $row = $stmt->fetch();
        return (bool)($row['has_qual'] ?? false) && (bool)($row['has_kls'] ?? false);
    }

    private function audit(Request $request, string $action, string $targetType, ?string $targetId, array $details): void
    {
        $detailsJson = json_encode($details, JSON_UNESCAPED_UNICODE);
        if ($detailsJson === false) $detailsJson = '{}';
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_log (actor_user_id, action, target_type, target_id, details)
             VALUES (:actor_user_id, :action, :target_type, :target_id, CAST(:details AS jsonb))'
        );
        $actor = (string)$request->getAttribute('user_id', '');
        $stmt->execute([
            'actor_user_id' => $actor === '' ? null : $actor,
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
}

