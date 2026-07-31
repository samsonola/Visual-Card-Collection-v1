<?php

require_once __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/core/mail.php';

$data = json_decode(file_get_contents("php://input"), true);

$email = trim($data['email'] ?? '');

if (!$email) {

    json_response([
        "status" => false,
        "message" => "Email address is required."
    ], 422);

}

$conn = db();

$userFound = false;

/*
|--------------------------------------------------------------------------
| Check Admin
|--------------------------------------------------------------------------
*/

$stmt = mysqli_prepare(
    $conn,
    "SELECT id,email FROM admin WHERE email=? LIMIT 1"
);

mysqli_stmt_bind_param($stmt, "s", $email);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

if (mysqli_fetch_assoc($result)) {

    $userFound = true;

}

/*
|--------------------------------------------------------------------------
| Supervisor
|--------------------------------------------------------------------------
*/

if (!$userFound) {

    $stmt = mysqli_prepare(
        $conn,
        "SELECT id,email FROM supervisor WHERE email=? LIMIT 1"
    );

    mysqli_stmt_bind_param($stmt, "s", $email);

    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    if (mysqli_fetch_assoc($result)) {

        $userFound = true;

    }

}

/*
|--------------------------------------------------------------------------
| Organisation
|--------------------------------------------------------------------------
*/

if (!$userFound) {

    $stmt = mysqli_prepare(
        $conn,
        "SELECT id,email FROM organisations WHERE email=? LIMIT 1"
    );

    mysqli_stmt_bind_param($stmt, "s", $email);

    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    if (mysqli_fetch_assoc($result)) {

        $userFound = true;

    }

}

/*
|--------------------------------------------------------------------------
| Always return success
|--------------------------------------------------------------------------
*/

if (!$userFound) {

    json_response([
        "status" => true,
        "message" => "If the email exists, a password reset code has been sent."
    ]);

}

/*
|--------------------------------------------------------------------------
| Delete previous unused OTPs
|--------------------------------------------------------------------------
*/

$stmt = mysqli_prepare(
    $conn,
    "DELETE FROM password_reset_tokens WHERE email=?"
);

mysqli_stmt_bind_param($stmt, "s", $email);

mysqli_stmt_execute($stmt);

/*
|--------------------------------------------------------------------------
| Generate OTP
|--------------------------------------------------------------------------
*/

$otp = random_int(100000, 999999);

$hash = password_hash(
    $otp,
    PASSWORD_DEFAULT
);

$expiry = date(
    "Y-m-d H:i:s",
    strtotime("+10 minutes")
);

/*
|--------------------------------------------------------------------------
| Save OTP
|--------------------------------------------------------------------------
*/

$stmt = mysqli_prepare(
    $conn,
    "INSERT INTO password_reset_tokens
    (email,otp_hash,expires_at)
    VALUES (?,?,?)"
);

mysqli_stmt_bind_param(
    $stmt,
    "sss",
    $email,
    $hash,
    $expiry
);

mysqli_stmt_execute($stmt);

/*
|--------------------------------------------------------------------------
| Email
|--------------------------------------------------------------------------
*/

$body = '

<div style="font-family:Arial">

<h2>Password Reset</h2>

<p>Hello,</p>

<p>Use the OTP below to reset your password.</p>

<h1 style="letter-spacing:8px;">'.$otp.'</h1>

<p>This OTP expires in <b>10 minutes</b>.</p>

<p>If you did not request this password reset, kindly ignore this email.</p>

</div>

';

$mailSent = sendMail(

    $email,

    "Password Reset OTP",

    $body

);

if (!$mailSent) {

    log_error("auth", "Unable to send password reset email", [
        "email" => $email
    ]);

    json_response([
        "status" => false,
        "message" => "Unable to send OTP. Please try again later."
    ], 500);

}

log_info("auth", "Password reset OTP sent", [
    "email" => $email
]);

json_response([

    "status" => true,

    "message" => "Password reset OTP sent successfully."

]);