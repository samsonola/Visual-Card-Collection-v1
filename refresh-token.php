<?php

require_once __DIR__ . '/core/bootstrap.php';

$data          = json_decode(file_get_contents('php://input'), true);
$refresh_token = $data['refresh_token'] ?? null;

if (!$refresh_token) {
    json_response(['error' => 'Refresh token is required'], 422);
}

// Decode and validate refresh token
$decoded = jwt_refresh_decode($refresh_token);

if (!$decoded) {
    log_warning('auth', 'Invalid or expired refresh token');
    json_response(['error' => 'Invalid or expired refresh token'], 401);
}

// Rebuild payload from decoded token (strip JWT reserved claims)
$payload = array_diff_key(
    (array) $decoded,
    array_flip(['iat', 'exp', 'type'])
);

// Issue new access token
$new_token = jwt_encode($payload);

log_info('auth', 'Token refreshed', ['role' => $decoded->role ?? 'unknown']);

json_response([
    'token' => $new_token
]);