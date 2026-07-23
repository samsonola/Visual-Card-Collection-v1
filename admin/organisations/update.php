<?php

require_once __DIR__ . '/../../core/bootstrap.php';

// -------------------------------------------------
// AUTHENTICATION
// -------------------------------------------------

$token = get_bearer_token();

if (!$token) {
    json_response(['error' => 'Unauthorized'], 401);
}

$decoded = jwt_decode($token);

if (!$decoded) {
    json_response(['error' => 'Invalid token'], 401);
}

$conn = db();

// -------------------------------------------------
// INPUT
// -------------------------------------------------

$data = json_decode(file_get_contents('php://input'), true);

$org_id   = trim($data['org_id'] ?? '');
$email    = trim($data['email'] ?? '');
$org_name = trim($data['org_name'] ?? '');
$org_pass = trim($data['org_pass'] ?? '');

// -------------------------------------------------
// VALIDATION
// -------------------------------------------------

// Must provide either org_id or email
if ($org_id === '' && $email === '') {

    json_response([
        'error' => 'Either org_id or email is required.'
    ], 400);

}

// Must provide something to update
if ($org_name === '' && $org_pass === '') {

    json_response([
        'error' => 'Nothing to update.'
    ], 400);

}

$where = '';

if ($org_id !== '') {

    $org_id = mysqli_real_escape_string($conn, $org_id);

    $where = "org_id = '$org_id'";

} else {

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        json_response([
            'error' => 'Invalid email address.'
        ], 400);

    }

    $email = mysqli_real_escape_string($conn, $email);

    $where = "email = '$email'";

}

// -------------------------------------------------
// CHECK ORGANISATION EXISTS
// -------------------------------------------------

$check = mysqli_query(
    $conn,
    "SELECT org_id, email
     FROM organisations
     WHERE $where
     LIMIT 1"
);

if (mysqli_num_rows($check) === 0) {

    json_response([
        'error' => 'Organisation not found.'
    ], 404);

}

$organisation = mysqli_fetch_assoc($check);

// -------------------------------------------------
// BUILD UPDATE
// -------------------------------------------------

$updates = [];

// Update organisation name
if ($org_name !== '') {

    $org_slug = strtolower($org_name);
    $org_slug = preg_replace('/[^a-z0-9]+/', '-', $org_slug);
    $org_slug = trim($org_slug, '-');

    $org_name = mysqli_real_escape_string($conn, $org_name);
    $org_slug = mysqli_real_escape_string($conn, $org_slug);

    $updates[] = "org_name='$org_name'";
    $updates[] = "org_slug='$org_slug'";
}

// Update password
if ($org_pass !== '') {

    $password = mysqli_real_escape_string(
        $conn,
        password_hash($org_pass, PASSWORD_DEFAULT)
    );

    $updates[] = "org_pass='$password'";
}

// -------------------------------------------------
// EXECUTE UPDATE
// -------------------------------------------------

$sql = "

UPDATE organisations

SET

" . implode(",\n", $updates) . "

WHERE $where

LIMIT 1

";

if (!mysqli_query($conn, $sql)) {

    json_response([
        'error' => mysqli_error($conn)
    ], 500);

}

// -------------------------------------------------
// CLEAR CACHE
// -------------------------------------------------

cacheForget('admin:organisations:list');
cacheForget('dashboard:overview');
cacheForget('dashboard:organisation_summary');

// -------------------------------------------------
// RESPONSE
// -------------------------------------------------

json_response([
    'message' => 'Organisation updated successfully.'
]);