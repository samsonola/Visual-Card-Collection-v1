<?php

declare(strict_types=1);

require_once __DIR__ . '/core/bootstrap.php';

/*
|--------------------------------------------------------------------------
| AUTH
|--------------------------------------------------------------------------
*/

$token = get_bearer_token();

if (!$token) {
    json_response([
        'status' => false,
        'message' => 'Unauthorized'
    ], 401);
}

$decoded = jwt_decode($token);

if (!$decoded) {
    json_response([
        'status' => false,
        'message' => 'Invalid token'
    ], 401);
}

$userId = $decoded['id'];
$role   = $decoded['role'];
$email  = $decoded['email'] ?? null;

/*
|--------------------------------------------------------------------------
| INPUT
|--------------------------------------------------------------------------
*/

$data = json_decode(file_get_contents("php://input"), true);

$oldPassword = trim($data['oldPassword'] ?? '');
$newPassword = trim($data['newPassword'] ?? '');

if (!$oldPassword || !$newPassword) {

    json_response([
        "status" => false,
        "message" => "Old password and new password are required."
    ], 422);

}

$conn = db();

/*
|--------------------------------------------------------------------------
| Determine User Table
|--------------------------------------------------------------------------
*/

$table = '';
$passwordColumn = '';

switch ($role) {

    case 'admin':
        $table = 'admin';
        $passwordColumn = 'password';
        break;

    case 'supervisor':
        $table = 'supervisor';
        $passwordColumn = 'password';
        break;

    case 'organisation':
        $table = 'organisations';
        $passwordColumn = 'org_pass';
        break;

    default:

        json_response([
            "status" => false,
            "message" => "This account cannot change password."
        ], 403);

}

/*
|--------------------------------------------------------------------------
| Fetch Current Password
|--------------------------------------------------------------------------
*/

$stmt = mysqli_prepare(
    $conn,
    "SELECT {$passwordColumn}
     FROM {$table}
     WHERE id=?
     LIMIT 1"
);

mysqli_stmt_bind_param(
    $stmt,
    "i",
    $userId
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

$current = mysqli_fetch_assoc($result);

if (!$current) {

    json_response([
        "status" => false,
        "message" => "User account not found."
    ], 404);

}

/*
|--------------------------------------------------------------------------
| Verify Old Password
|--------------------------------------------------------------------------
*/

if (!password_verify($oldPassword, $current[$passwordColumn])) {

    log_warning("auth", "Incorrect old password", [
        "user_id" => $userId,
        "role"    => $role
    ]);

    json_response([
        "status" => false,
        "message" => "Old password is incorrect."
    ], 401);

}

/*
|--------------------------------------------------------------------------
| Prevent Same Password
|--------------------------------------------------------------------------
*/

if (password_verify($newPassword, $current[$passwordColumn])) {

    json_response([
        "status" => false,
        "message" => "New password must be different from your current password."
    ], 422);

}

/*
|--------------------------------------------------------------------------
| Password Validation
|--------------------------------------------------------------------------
*/

if (strlen($newPassword) < 8) {

    json_response([
        "status" => false,
        "message" => "Password must be at least 8 characters long."
    ], 422);

}

/*
|--------------------------------------------------------------------------
| Hash Password
|--------------------------------------------------------------------------
*/

$newHash = password_hash(
    $newPassword,
    PASSWORD_DEFAULT
);

/*
|--------------------------------------------------------------------------
| Update Password
|--------------------------------------------------------------------------
*/

$stmt = mysqli_prepare(
    $conn,
    "UPDATE {$table}
     SET {$passwordColumn}=?
     WHERE id=?"
);

mysqli_stmt_bind_param(
    $stmt,
    "si",
    $newHash,
    $userId
);

mysqli_stmt_execute($stmt);

if (mysqli_stmt_affected_rows($stmt) <= 0) {

    log_error("auth", "Password update failed", [
        "user_id" => $userId,
        "role"    => $role
    ]);

    json_response([
        "status" => false,
        "message" => "Unable to change password."
    ], 500);

}

/*
|--------------------------------------------------------------------------
| Audit Log
|--------------------------------------------------------------------------
*/

log_info("auth", "Password changed successfully", [
    "user_id" => $userId,
    "email"   => $email,
    "role"    => $role
]);

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

json_response([
    "status" => true,
    "message" => "Password changed successfully."
]);