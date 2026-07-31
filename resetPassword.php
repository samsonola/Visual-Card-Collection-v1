<?php

require_once __DIR__ . '/core/bootstrap.php';

$data = json_decode(file_get_contents("php://input"), true);

$resetToken = trim($data['resetToken'] ?? '');
$newPassword = trim($data['newPassword'] ?? '');

if (!$resetToken || !$newPassword) {

    json_response([
        "status" => false,
        "message" => "Reset token and new password are required."
    ], 422);

}

try {

    $payload = jwt_decode($resetToken);

} catch (Exception $e) {

    json_response([
        "status" => false,
        "message" => "Invalid or expired reset token."
    ], 401);

}

if (
    empty($payload['email']) ||
    empty($payload['token_id']) ||
    ($payload['type'] ?? '') !== 'password_reset'
) {

    json_response([
        "status" => false,
        "message" => "Invalid reset token."
    ], 401);

}

$conn = db();

/*
|--------------------------------------------------------------------------
| Validate password reset request
|--------------------------------------------------------------------------
*/

$stmt = mysqli_prepare(
    $conn,
    "SELECT *
     FROM password_reset_tokens
     WHERE id=?
     AND email=?
     AND verified=1
     AND used=0
     LIMIT 1"
);

mysqli_stmt_bind_param(
    $stmt,
    "is",
    $payload['token_id'],
    $payload['email']
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

$reset = mysqli_fetch_assoc($result);

if (!$reset) {

    json_response([
        "status" => false,
        "message" => "Reset request is no longer valid."
    ], 401);

}

/*
|--------------------------------------------------------------------------
| Hash password
|--------------------------------------------------------------------------
*/

$newHash = password_hash(
    $newPassword,
    PASSWORD_DEFAULT
);

$email = $payload['email'];

$updated = false;

/*
|--------------------------------------------------------------------------
| Admin
|--------------------------------------------------------------------------
*/

$stmt = mysqli_prepare(
    $conn,
    "UPDATE admin
     SET password=?
     WHERE email=?"
);

mysqli_stmt_bind_param(
    $stmt,
    "ss",
    $newHash,
    $email
);

mysqli_stmt_execute($stmt);

if (mysqli_stmt_affected_rows($stmt) > 0) {

    $updated = true;

}

/*
|--------------------------------------------------------------------------
| Supervisor
|--------------------------------------------------------------------------
*/

if (!$updated) {

    $stmt = mysqli_prepare(
        $conn,
        "UPDATE supervisor
         SET password=?
         WHERE email=?"
    );

    mysqli_stmt_bind_param(
        $stmt,
        "ss",
        $newHash,
        $email
    );

    mysqli_stmt_execute($stmt);

    if (mysqli_stmt_affected_rows($stmt) > 0) {

        $updated = true;

    }

}

/*
|--------------------------------------------------------------------------
| Organisation
|--------------------------------------------------------------------------
*/

if (!$updated) {

    $stmt = mysqli_prepare(
        $conn,
        "UPDATE organisations
         SET org_pass=?
         WHERE email=?"
    );

    mysqli_stmt_bind_param(
        $stmt,
        "ss",
        $newHash,
        $email
    );

    mysqli_stmt_execute($stmt);

    if (mysqli_stmt_affected_rows($stmt) > 0) {

        $updated = true;

    }

}

if (!$updated) {

    json_response([
        "status" => false,
        "message" => "Unable to update password."
    ], 500);

}

/*
|--------------------------------------------------------------------------
| Mark reset completed
|--------------------------------------------------------------------------
*/

$stmt = mysqli_prepare(
    $conn,
    "UPDATE password_reset_tokens
     SET used=1
     WHERE id=?"
);

mysqli_stmt_bind_param(
    $stmt,
    "i",
    $payload['token_id']
);

mysqli_stmt_execute($stmt);

log_info("auth", "Password reset successful", [
    "email" => $email
]);

json_response([

    "status" => true,

    "message" => "Password reset successfully."

]);