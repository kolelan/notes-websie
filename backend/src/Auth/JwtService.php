<?php

declare(strict_types=1);

namespace App\Auth;

final class JwtService
{
    private string $secret;
    private int $accessTtl;
    private int $refreshTtl;

    public function __construct()
    {
        $this->secret = (string)(getenv('JWT_SECRET') ?: 'change_this_secret');
        $this->accessTtl = (int)(getenv('JWT_ACCESS_TTL') ?: 900);
        $this->refreshTtl = (int)(getenv('JWT_REFRESH_TTL') ?: 604800);
    }

    public function issueAccessToken(string $userId, string $role): string
    {
        $now = time();
        $payload = [
            'sub' => $userId,
            'role' => $role,
            'type' => 'access',
            'iat' => $now,
            'exp' => $now + $this->accessTtl,
        ];

        return $this->encode($payload);
    }

    public function issueRefreshToken(string $userId): array
    {
        $now = time();
        $expiresAt = $now + $this->refreshTtl;
        $payload = [
            'sub' => $userId,
            'type' => 'refresh',
            'iat' => $now,
            'exp' => $expiresAt,
        ];

        return [
            'token' => $this->encode($payload),
            'expires_at' => $expiresAt,
        ];
    }

    public function decode(string $token): object
    {
        [$headerPart, $payloadPart, $signaturePart] = explode('.', $token . '..');
        if ($headerPart === '' || $payloadPart === '' || $signaturePart === '') {
            throw new \RuntimeException('Malformed token');
        }

        $signature = $this->base64UrlDecode($signaturePart);
        $signedData = $headerPart . '.' . $payloadPart;
        $expected = hash_hmac('sha256', $signedData, $this->secret, true);

        if (!hash_equals($expected, $signature)) {
            throw new \RuntimeException('Invalid signature');
        }

        $payloadRaw = $this->base64UrlDecode($payloadPart);
        $payload = json_decode($payloadRaw, false, 512, JSON_THROW_ON_ERROR);

        if (!isset($payload->exp) || (int)$payload->exp < time()) {
            throw new \RuntimeException('Token expired');
        }

        return $payload;
    }

    private function encode(array $payload): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];

        $headerEncoded = $this->base64UrlEncode((string)json_encode($header, JSON_THROW_ON_ERROR));
        $payloadEncoded = $this->base64UrlEncode((string)json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, $this->secret, true);
        $signatureEncoded = $this->base64UrlEncode($signature);

        return $headerEncoded . '.' . $payloadEncoded . '.' . $signatureEncoded;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder > 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        if ($decoded === false) {
            throw new \RuntimeException('Invalid token encoding');
        }

        return $decoded;
    }
}
