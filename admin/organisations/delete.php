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

$org_id = trim($data['org_id'] ?? '');

if ($org_id == '') {

    json_response([
        'error' => 'org_id is required.'
    ], 400);

}

$org_id = mysqli_real_escape_string($conn, $org_id);

// -------------------------------------------------
// CHECK EXISTS
// -------------------------------------------------

$check = mysqli_query(
    $conn,
    "SELECT id FROM organisations WHERE org_id='$org_id' LIMIT 1"
);

if (mysqli_num_rows($check) == 0) {

    json_response([
        'error' => 'Organisation not found.'
    ], 404);

}

// -------------------------------------------------
// DELETE
// -------------------------------------------------

if (!mysqli_query(
    $conn,
    "DELETE FROM organisations WHERE org_id='$org_id' LIMIT 1"
)) {

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

    'message' => 'Organisation deleted successfully.'

]);