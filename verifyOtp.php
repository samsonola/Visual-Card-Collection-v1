<?php

require_once __DIR__ . '/core/bootstrap.php';

$data = json_decode(file_get_contents("php://input"), true);

$email = trim($data['email'] ?? '');
$otp   = trim($data['otp'] ?? '');

if (!$email || !$otp) {

    json_response([
        "status" => false,
        "message" => "Email and OTP are required."
    ], 422);

}

$conn = db();

/*
|--------------------------------------------------------------------------
| Fetch latest unused OTP
|--------------------------------------------------------------------------
*/

$stmt = mysqli_prepare(
    $conn,
    "SELECT *
     FROM password_reset_tokens
     WHERE email=?
     AND used=0
     ORDER BY id DESC
     LIMIT 1"
);

mysqli_stmt_bind_param($stmt, "s", $email);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

$token = mysqli_fetch_assoc($result);

if (!$token) {

    json_response([
        "status" => false,
        "message" => "Invalid OTP."
    ], 401);

}

/*
|--------------------------------------------------------------------------
| Check expiry
|--------------------------------------------------------------------------
*/

if (strtotime($token['expires_at']) < time()) {

    json_response([
        "status" => false,
        "message" => "OTP has expired."
    ], 401);

}

/*
|--------------------------------------------------------------------------
| Verify OTP
|--------------------------------------------------------------------------
*/

if (!password_verify($otp, $token['otp_hash'])) {

    log_warning("auth", "Invalid password reset OTP", [
        "email" => $email
    ]);

    json_response([
        "status" => false,
        "message" => "Invalid OTP."
    ], 401);

}

/*
|--------------------------------------------------------------------------
| Generate temporary reset token
|--------------------------------------------------------------------------
*/

$payload = [

    "email" => $email,

    "type" => "password_reset",

    "exp" => time() + (60 * 10)

];

$resetToken = jwt_encode($payload);

/*
|--------------------------------------------------------------------------
| Mark OTP used
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
    $token['id']
);

mysqli_stmt_execute($stmt);

log_info("auth", "Password reset OTP verified", [
    "email" => $email
]);

json_response([

    "status" => true,

    "message" => "OTP verified successfully.",

    "resetToken" => $resetToken

]);