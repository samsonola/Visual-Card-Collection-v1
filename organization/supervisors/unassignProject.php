<?php

declare(strict_types=1);

require_once __DIR__ . '/../../core/bootstrap.php';

/*
|--------------------------------------------------------------------------
| AUTHENTICATION
|--------------------------------------------------------------------------
*/

$token = get_bearer_token();

if (!$token) {

    json_response([
        'status'  => false,
        'message' => 'Unauthorized'
    ], 401);

}

$decoded = jwt_decode($token);

if (!$decoded) {

    json_response([
        'status'  => false,
        'message' => 'Invalid token'
    ], 401);

}

/*
|--------------------------------------------------------------------------
| AUTHORISATION
|--------------------------------------------------------------------------
*/

if (($decoded->role ?? '') !== 'organisation') {

    json_response([
        'status'  => false,
        'message' => 'Access denied.'
    ], 403);

}

$orgId = $decoded->org_id ?? null;

if (!$orgId) {

    json_response([
        'status'  => false,
        'message' => 'Organisation not found.'
    ], 401);

}

$conn = db();

/*
|--------------------------------------------------------------------------
| REQUEST
|--------------------------------------------------------------------------
*/

$input = json_decode(file_get_contents('php://input'), true);

$supervisorId = (int)($input['supervisor_id'] ?? 0);

$proId = trim($input['pro_id'] ?? '');

if (

    $supervisorId <= 0 ||

    $proId === ''

) {

    json_response([

        'status' => false,

        'message' => 'supervisor_id and pro_id are required.'

    ], 422);

}

/*
|--------------------------------------------------------------------------
| TRANSACTION
|--------------------------------------------------------------------------
*/

mysqli_begin_transaction($conn);

try {

    /*
    |--------------------------------------------------------------------------
    | VERIFY SUPERVISOR
    |--------------------------------------------------------------------------
    */

    $stmt = mysqli_prepare(

        $conn,

        "
        SELECT id
        FROM supervisor
        WHERE id=?
        AND org_id=?
        LIMIT 1
        "

    );

    mysqli_stmt_bind_param(

        $stmt,

        "is",

        $supervisorId,

        $orgId

    );

    mysqli_stmt_execute($stmt);

    $supervisor = mysqli_fetch_assoc(

        mysqli_stmt_get_result($stmt)

    );

    if (!$supervisor) {

        throw new Exception(

            'Supervisor not found.'

        );

    }

    /*
    |--------------------------------------------------------------------------
    | VERIFY PROJECT
    |--------------------------------------------------------------------------
    */

    $stmt = mysqli_prepare(

        $conn,

        "
        SELECT pro_id
        FROM organisations_project
        WHERE pro_id=?
        AND org_id=?
        LIMIT 1
        "

    );

    mysqli_stmt_bind_param(

        $stmt,

        "ss",

        $proId,

        $orgId

    );

    mysqli_stmt_execute($stmt);

    $project = mysqli_fetch_assoc(

        mysqli_stmt_get_result($stmt)

    );

    if (!$project) {

        throw new Exception(

            'Project not found.'

        );

    }

    /*
    |--------------------------------------------------------------------------
    | VERIFY ACTIVE ASSIGNMENT
    |--------------------------------------------------------------------------
    */

    $stmt = mysqli_prepare(

        $conn,

        "
        SELECT id

        FROM supervisor_pro_assignment

        WHERE supervisor_id=?

        AND pro_id=?

        AND org_id=?

        AND status='active'

        AND unassigned_at IS NULL

        LIMIT 1
        "

    );

    mysqli_stmt_bind_param(

        $stmt,

        "iss",

        $supervisorId,

        $proId,

        $orgId

    );

    mysqli_stmt_execute($stmt);

    $assignment = mysqli_fetch_assoc(

        mysqli_stmt_get_result($stmt)

    );

    if (!$assignment) {

        throw new Exception(

            'No active assignment found.'

        );

    }

    /*
    |--------------------------------------------------------------------------
    | UNASSIGN PROJECT
    |--------------------------------------------------------------------------
    */

    $stmt = mysqli_prepare(

        $conn,

        "
        UPDATE supervisor_pro_assignment

        SET

            status='inactive',

            unassigned_at=NOW()

        WHERE id=?
        "

    );

    $assignmentId = (int)$assignment['id'];

    mysqli_stmt_bind_param(

        $stmt,

        "i",

        $assignmentId

    );

    mysqli_stmt_execute($stmt);

    /*
    |--------------------------------------------------------------------------
    | COMMIT
    |--------------------------------------------------------------------------
    */

    mysqli_commit($conn);

    /*
    |--------------------------------------------------------------------------
    | CLEAR CACHE
    |--------------------------------------------------------------------------
    */

    cacheForget(sprintf(
        'organisation:%s:supervisors:1:20:%s',
        $orgId,
        md5('')
    ));

    cacheForget(sprintf(
        'organisation:%s:projects:1:20:%s',
        $orgId,
        md5('')
    ));

    cacheForget(sprintf(
        'organisation:%s:dashboard:cardStats',
        $orgId
    ));

    cacheForget(sprintf(
        'organisation:%s:dashboard:coverage',
        $orgId
    ));

    /*
    |--------------------------------------------------------------------------
    | RESPONSE
    |--------------------------------------------------------------------------
    */

    json_response([

        'status' => true,

        'message' => 'Supervisor unassigned successfully.',

        'data' => [

            'supervisor_id' => $supervisorId,

            'pro_id' => $proId,

            'status' => 'inactive',

            'unassigned_at' => date('Y-m-d H:i:s')

        ]

    ], 200);

} catch (Exception $e) {

    mysqli_rollback($conn);

    json_response([

        'status' => false,

        'message' => $e->getMessage()

    ], 400);

}