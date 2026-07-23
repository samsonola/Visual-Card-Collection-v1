<?php

// --------------------------------------------
// CORS
// --------------------------------------------

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type, Accept");
header("Access-Control-Max-Age: 86400");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../core/bootstrap.php';

/*
|--------------------------------------------------------------------------
| AUTH
|--------------------------------------------------------------------------
*/

$token = get_bearer_token();

if (!$token) {

    json_response([
        'error' => 'Unauthorized'
    ], 401);

}

$decoded = jwt_decode($token);

if (!$decoded) {

    json_response([
        'error' => 'Invalid token'
    ], 401);

}

$conn = db();

/*
|--------------------------------------------------------------------------
| READ JSON BODY
|--------------------------------------------------------------------------
*/

$data = json_decode(file_get_contents('php://input'), true);

if (!is_array($data)) {

    json_response([
        'error' => 'Invalid JSON payload'
    ], 400);

}

/*
|--------------------------------------------------------------------------
| VALIDATION
|--------------------------------------------------------------------------
*/

$email = trim($data['email'] ?? '');

$org_id = (int)($data['org_id'] ?? 0);

if (

    $email == '' ||

    $org_id <= 0

) {

    json_response([
        'error' => 'Email and org_id are required.'
    ], 422);

}

/*
|--------------------------------------------------------------------------
| DELETE SUPERVISOR
|--------------------------------------------------------------------------
*/

mysqli_begin_transaction($conn);

try {

    $email = mysqli_real_escape_string($conn, $email);

    /*
    --------------------------------------------------
    Verify supervisor exists
    --------------------------------------------------
    */

    $checkSql = "

        SELECT id

        FROM supervisor

        WHERE email = '$email'

        AND org_id = $org_id

        LIMIT 1

    ";

    $checkResult = mysqli_query($conn, $checkSql);

    if (!$checkResult) {

        throw new Exception(
            mysqli_error($conn)
        );

    }

    if (!mysqli_num_rows($checkResult)) {

        mysqli_rollback($conn);

        json_response([
            'error' => 'Supervisor not found.'
        ], 404);

    }

    /*
    --------------------------------------------------
    Delete supervisor
    --------------------------------------------------
    */

    $deleteSql = "

        DELETE FROM supervisor

        WHERE email = '$email'

        AND org_id = $org_id

        LIMIT 1

    ";

    if (!mysqli_query($conn, $deleteSql)) {

        throw new Exception(
            mysqli_error($conn)
        );

    }

    mysqli_commit($conn);

}
catch (Exception $e) {

    mysqli_rollback($conn);

    json_response([
        'error' => $e->getMessage()
    ], 500);

}

/*
|--------------------------------------------------------------------------
| RESPONSE
|--------------------------------------------------------------------------
*/

json_response([

    'success' => true,

    'message' => 'Supervisor deleted.'

]);