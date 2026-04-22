<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Auth\JwtService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class MenuController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly JwtService $jwtService
    ) {
    }

    public function getByCode(Request $request, Response $response, array $args): Response
    {
        $code = trim((string)($args['code'] ?? ''));
        if ($code === '') {
            return $this->json($response, ['error' => 'Menu code is required'], 422);
        }

        $auth = $this->resolveAuth($request);
        $stmt = $this->pdo->prepare(
            'SELECT q.qual_id,
                    q.qual_code,
                    q.tag::text AS qual_tag,
                    k.kls_id,
                    k.kls_namef,
                    k.kls_names,
                    k.kls_code,
                    k.kls_rubrika::text AS kls_rubrika,
                    k.tags::text AS item_tags
             FROM kls.qual q
             JOIN kls.kls k ON k.qual_id = q.qual_id
             WHERE q.qual_code = :qual_code
               AND q.qual_is_del = FALSE
               AND k.kls_is_del = FALSE
             ORDER BY k.kls_rubrika'
        );
        $stmt->execute(['qual_code' => $code]);
        $rows = $stmt->fetchAll();

        if ($rows === []) {
            return $this->json($response, ['data' => ['code' => $code, 'items' => []]]);
        }

        $root = [];
        $byRubrika = [];
        foreach ($rows as $row) {
            $qualTags = $this->parseHstore((string)($row['qual_tag'] ?? ''));
            $itemTags = $this->parseHstore((string)($row['item_tags'] ?? ''));
            $tags = array_replace($qualTags, $itemTags);
            if (!$this->canShowByTags($tags, $auth['is_authenticated'], $auth['role'])) {
                continue;
            }
            $rubrika = (string)$row['kls_rubrika'];
            $node = [
                'id' => (string)$row['kls_id'],
                'code' => (string)$row['kls_code'],
                'title' => (string)($row['kls_names'] ?: $row['kls_namef']),
                'url' => (string)($tags['url'] ?? '#'),
                'rubrika' => $rubrika,
                'children' => [],
            ];
            $byRubrika[$rubrika] = $node;
        }

        foreach ($byRubrika as $rubrika => $node) {
            $parentRubrika = $this->parentRubrika((string)$rubrika);
            if ($parentRubrika !== null && isset($byRubrika[$parentRubrika])) {
                $byRubrika[$parentRubrika]['children'][] = $node;
            } else {
                $root[] = $node;
            }
        }

        return $this->json($response, ['data' => ['code' => $code, 'items' => array_values($root)]]);
    }

    private function resolveAuth(Request $request): array
    {
        $header = $request->getHeaderLine('Authorization');
        if (!str_starts_with($header, 'Bearer ')) {
            return ['is_authenticated' => false, 'role' => 'guest'];
        }
        $token = trim(substr($header, 7));
        if ($token === '') {
            return ['is_authenticated' => false, 'role' => 'guest'];
        }
        try {
            $payload = $this->jwtService->decode($token);
            return [
                'is_authenticated' => true,
                'role' => (string)($payload->role ?? 'user'),
            ];
        } catch (\Throwable) {
            return ['is_authenticated' => false, 'role' => 'guest'];
        }
    }

    private function parseHstore(string $value): array
    {
        if (trim($value) === '') return [];
        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"\s*=>\s*"((?:[^"\\\\]|\\\\.)*)"/', $value, $matches, PREG_SET_ORDER);
        $result = [];
        foreach ($matches as $m) {
            $key = stripcslashes($m[1]);
            $val = stripcslashes($m[2]);
            $result[$key] = $val;
        }
        return $result;
    }

    private function parseRoleList(string $value): array
    {
        return array_values(array_filter(array_map(
            static fn(string $part): string => strtolower(trim($part)),
            explode(',', $value)
        )));
    }

    private function canShowByTags(array $tags, bool $isAuthenticated, string $role): bool
    {
        $authorized = strtolower((string)($tags['authorized'] ?? ''));
        if (!$isAuthenticated && $authorized === 'false') {
            return false;
        }

        $role = strtolower($role);
        $effectiveRoles = $this->expandEffectiveRoles($role, $isAuthenticated);
        $allowRolesRaw = trim((string)($tags['role'] ?? ''));
        if ($allowRolesRaw !== '') {
            $allowRoles = $this->parseRoleList($allowRolesRaw);
            if (!in_array('all', $allowRoles, true)) {
                if (!$isAuthenticated) return false;
                if ($allowRoles !== [] && !$this->hasIntersection($allowRoles, $effectiveRoles)) {
                    return false;
                }
            }
        }

        $denyRolesRaw = trim((string)($tags['not_role'] ?? ''));
        if ($denyRolesRaw !== '') {
            $denyRoles = $this->parseRoleList($denyRolesRaw);
            if (in_array('all', $denyRoles, true)) {
                return false;
            }
            if (!$isAuthenticated && in_array('guest', $denyRoles, true)) {
                return false;
            }
            if ($this->hasIntersection($denyRoles, $effectiveRoles)) {
                return false;
            }
        }

        return true;
    }

    private function expandEffectiveRoles(string $role, bool $isAuthenticated): array
    {
        if (!$isAuthenticated) {
            return ['guest'];
        }

        $roles = [$role];
        if ($role === 'superadmin') {
            $roles[] = 'admin';
            $roles[] = 'user';
        } elseif ($role === 'admin') {
            $roles[] = 'user';
        }

        return array_values(array_unique(array_filter($roles)));
    }

    private function hasIntersection(array $left, array $right): bool
    {
        foreach ($left as $value) {
            if (in_array($value, $right, true)) {
                return true;
            }
        }
        return false;
    }

    private function parentRubrika(string $rubrika): ?string
    {
        if (!str_contains($rubrika, '.')) return null;
        $idx = strrpos($rubrika, '.');
        if ($idx === false) return null;
        return substr($rubrika, 0, $idx);
    }

    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write((string)json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}

