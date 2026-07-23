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

$org_name = trim($data['org_name'] ?? '');
$email    = trim($data['email'] ?? '');
$org_pass = trim($data['org_pass'] ?? '');

// -------------------------------------------------
// VALIDATION
// -------------------------------------------------

if ($org_name === '' || $email === '' || $org_pass === '') {

    json_response([
        'error' => 'Organisation name, email and password are required.'
    ], 400);

}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

    json_response([
        'error' => 'Invalid email address.'
    ], 400);

}

// -------------------------------------------------
// CHECK EMAIL
// -------------------------------------------------

$email = mysqli_real_escape_string($conn, $email);

$check = mysqli_query(
    $conn,
    "SELECT id FROM organisations WHERE email='$email' LIMIT 1"
);

if (mysqli_num_rows($check) > 0) {

    json_response([
        'error' => 'Email already exists.'
    ], 409);

}

// -------------------------------------------------
// GENERATE UNIQUE ORG ID
// -------------------------------------------------

do {

    $org_id = random_int(1000000, 9999999);

    $exists = mysqli_query(
        $conn,
        "SELECT id FROM organisations WHERE org_id='$org_id' LIMIT 1"
    );

} while (mysqli_num_rows($exists) > 0);

// -------------------------------------------------
// GENERATE SLUG
// -------------------------------------------------

$org_slug = strtolower($org_name);

$org_slug = preg_replace('/[^a-z0-9]+/', '-', $org_slug);

$org_slug = trim($org_slug, '-');

// -------------------------------------------------
// HASH PASSWORD
// -------------------------------------------------

$hashedPassword = password_hash($org_pass, PASSWORD_DEFAULT);

// -------------------------------------------------
// ESCAPE
// -------------------------------------------------

$org_name = mysqli_real_escape_string($conn, $org_name);
$org_slug = mysqli_real_escape_string($conn, $org_slug);
$hashedPassword = mysqli_real_escape_string($conn, $hashedPassword);

// -------------------------------------------------
// INSERT
// -------------------------------------------------

$sql = "

INSERT INTO organisations (

    org_id,
    org_name,
    org_slug,
    callback_url,
    org_pass,
    email,
    date_created

)

VALUES (

    '$org_id',
    '$org_name',
    '$org_slug',
    NULL,
    '$hashedPassword',
    '$email',
    NOW()

)

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

    'message' => 'Organisation created successfully.',

    'organisation' => [

        'org_id' => (int)$org_id,

        'org_name' => $org_name,

        'org_slug' => $org_slug,

        'email' => $email

    ]

], 201);