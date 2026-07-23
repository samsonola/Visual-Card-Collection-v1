<?php

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function jwt_encode(array $payload): string {
    $secret      = $_ENV['JWT_SECRET'];
    $issued_at   = time();
    $expire      = $issued_at + (int)$_ENV['JWT_EXPIRY'];  // e.g 3600 = 1hr

    $data = array_merge($payload, [
        'iat' => $issued_at,
        'exp' => $expire,
    ]);

    return JWT::encode($data, $secret, 'HS256');
}

function jwt_refresh_encode(array $payload): string {
    $secret      = $_ENV['JWT_REFRESH_SECRET'];
    $issued_at   = time();
    $expire      = $issued_at + (int)$_ENV['JWT_REFRESH_EXPIRY']; // e.g 604800 = 7days

    $data = array_merge($payload, [
        'iat'  => $issued_at,
        'exp'  => $expire,
        'type' => 'refresh'
    ]);

    return JWT::encode($data, $secret, 'HS256');
}

function jwt_decode(string $token): ?object {
    try {
        return JWT::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));
    } catch (Exception $e) {
        log_error('auth', 'JWT decode failed: ' . $e->getMessage());
        return null;
    }
}

function jwt_refresh_decode(string $token): ?object {
    try {
        $decoded = JWT::decode($token, new Key($_ENV['JWT_REFRESH_SECRET'], 'HS256'));

        if (!isset($decoded->type) || $decoded->type !== 'refresh') {
            return null;
        }

        return $decoded;
    } catch (Exception $e) {
        log_error('auth', 'Refresh token decode failed: ' . $e->getMessage());
        return null;
    }
}

function get_bearer_token(): ?string { //JWT header decode
    $headers = getallheaders();
    $auth    = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    if (str_starts_with($auth, 'Bearer ')) {
        return substr($auth, 7);
    }

    return null;
}