<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Auth\JwtService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AuthController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly JwtService $jwtService
    ) {
    }

    public function login(Request $request, Response $response): Response
    {
        $payload = (array)$request->getParsedBody();
        $email = trim((string)($payload['email'] ?? ''));
        $password = (string)($payload['password'] ?? '');

        if ($email === '' || $password === '') {
            return $this->json($response, ['error' => 'Fields email and password are required'], 422);
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, email, role, password_hash, is_active FROM "user" WHERE email = :email LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if ($user === false || !((bool)$user['is_active']) || empty($user['password_hash'])) {
            return $this->json($response, ['error' => 'Invalid credentials'], 401);
        }

        if (!password_verify($password, (string)$user['password_hash'])) {
            return $this->json($response, ['error' => 'Invalid credentials'], 401);
        }

        return $this->issueTokensResponse($response, (string)$user['id'], (string)$user['role']);
    }

    public function register(Request $request, Response $response): Response
    {
        $payload = (array)$request->getParsedBody();
        $email = trim((string)($payload['email'] ?? ''));
        $name = trim((string)($payload['name'] ?? ''));
        $password = (string)($payload['password'] ?? '');

        if ($email === '' || $name === '' || $password === '') {
            return $this->json($response, ['error' => 'Fields email, name and password are required'], 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json($response, ['error' => 'Invalid email'], 422);
        }
        if (mb_strlen($password) < 8) {
            return $this->json($response, ['error' => 'Password must be at least 8 characters'], 422);
        }

        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        if ($passwordHash === false) {
            return $this->json($response, ['error' => 'Failed to hash password'], 500);
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO "user" (email, name, password_hash, role, is_active)
             VALUES (:email, :name, :password_hash, :role, TRUE)
             RETURNING id, role'
        );

        try {
            $stmt->execute([
                'email' => $email,
                'name' => $name,
                'password_hash' => $passwordHash,
                'role' => 'user',
            ]);
        } catch (\Throwable) {
            return $this->json($response, ['error' => 'Email already registered'], 409);
        }

        $row = $stmt->fetch();
        if ($row === false) {
            return $this->json($response, ['error' => 'Failed to create user'], 500);
        }

        return $this->issueTokensResponse($response, (string)$row['id'], (string)$row['role'], 201);
    }

    public function refresh(Request $request, Response $response): Response
    {
        $payload = (array)$request->getParsedBody();
        $token = trim((string)($payload['refresh_token'] ?? ''));

        if ($token === '') {
            return $this->json($response, ['error' => 'Field refresh_token is required'], 422);
        }

        try {
            $decoded = $this->jwtService->decode($token);
            if (($decoded->type ?? '') !== 'refresh') {
                return $this->json($response, ['error' => 'Invalid token type'], 401);
            }
        } catch (\Throwable) {
            return $this->json($response, ['error' => 'Invalid or expired refresh token'], 401);
        }

        $lookup = $this->pdo->prepare(
            'SELECT id, user_id, revoked_at, expires_at FROM refresh_token WHERE token = :token LIMIT 1'
        );
        $lookup->execute(['token' => $token]);
        $row = $lookup->fetch();

        if ($row === false || $row['revoked_at'] !== null || strtotime((string)$row['expires_at']) < time()) {
            return $this->json($response, ['error' => 'Refresh token is not active'], 401);
        }

        $userStmt = $this->pdo->prepare('SELECT id, role, is_active FROM "user" WHERE id = :id LIMIT 1');
        $userStmt->execute(['id' => $row['user_id']]);
        $user = $userStmt->fetch();

        if ($user === false || !((bool)$user['is_active'])) {
            return $this->json($response, ['error' => 'User not active'], 401);
        }

        $revoke = $this->pdo->prepare('UPDATE refresh_token SET revoked_at = NOW() WHERE id = :id');
        $revoke->execute(['id' => $row['id']]);

        $accessToken = $this->jwtService->issueAccessToken((string)$user['id'], (string)$user['role']);
        $refresh = $this->jwtService->issueRefreshToken((string)$user['id']);

        $insert = $this->pdo->prepare(
            'INSERT INTO refresh_token (user_id, token, expires_at) VALUES (:user_id, :token, TO_TIMESTAMP(:expires_at))'
        );
        $insert->execute([
            'user_id' => $user['id'],
            'token' => $refresh['token'],
            'expires_at' => $refresh['expires_at'],
        ]);

        return $this->json($response, [
            'data' => [
                'access_token' => $accessToken,
                'refresh_token' => $refresh['token'],
                'token_type' => 'Bearer',
            ],
        ]);
    }

    public function logout(Request $request, Response $response): Response
    {
        $payload = (array)$request->getParsedBody();
        $token = trim((string)($payload['refresh_token'] ?? ''));

        if ($token !== '') {
            $stmt = $this->pdo->prepare('UPDATE refresh_token SET revoked_at = NOW() WHERE token = :token AND revoked_at IS NULL');
            $stmt->execute(['token' => $token]);
        }

        return $this->json($response, ['data' => ['ok' => true]]);
    }

    public function logoutAll(Request $request, Response $response): Response
    {
        $userId = (string)$request->getAttribute('user_id', '');
        if ($userId === '') {
            return $this->json($response, ['error' => 'Unauthorized'], 401);
        }

        $stmt = $this->pdo->prepare(
            'UPDATE refresh_token
             SET revoked_at = NOW()
             WHERE user_id = :user_id AND revoked_at IS NULL'
        );
        $stmt->execute(['user_id' => $userId]);

        return $this->json($response, ['data' => ['ok' => true, 'revoked' => $stmt->rowCount()]]);
    }

    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write((string)json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }

    private function issueTokensResponse(Response $response, string $userId, string $role, int $status = 200): Response
    {
        $accessToken = $this->jwtService->issueAccessToken($userId, $role);
        $refresh = $this->jwtService->issueRefreshToken($userId);

        $insert = $this->pdo->prepare(
            'INSERT INTO refresh_token (user_id, token, expires_at) VALUES (:user_id, :token, TO_TIMESTAMP(:expires_at))'
        );
        $insert->execute([
            'user_id' => $userId,
            'token' => $refresh['token'],
            'expires_at' => (int)$refresh['expires_at'],
        ]);

        return $this->json($response, [
            'data' => [
                'access_token' => $accessToken,
                'refresh_token' => $refresh['token'],
                'token_type' => 'Bearer',
            ],
        ], $status);
    }
}
