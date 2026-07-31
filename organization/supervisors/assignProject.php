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
    | VERIFY SUPERVISOR BELONGS TO ORGANISATION
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
    | VERIFY PROJECT BELONGS TO ORGANISATION
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
    | CHECK EXISTING ASSIGNMENT
    |--------------------------------------------------------------------------
    */

    $stmt = mysqli_prepare(

        $conn,

        "
        SELECT

            id,

            status,

            unassigned_at

        FROM supervisor_pro_assignment

        WHERE supervisor_id=?

        AND pro_id=?

        AND org_id=?

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

    $existing = mysqli_fetch_assoc(

        mysqli_stmt_get_result($stmt)

    );

    /*
    |--------------------------------------------------------------------------
    | ASSIGN / REACTIVATE
    |--------------------------------------------------------------------------
    */

    if ($existing) {

        /*
        |--------------------------------------------------------------------------
        | Already Active
        |--------------------------------------------------------------------------
        */

        if (

            $existing['status'] === 'active' &&

            $existing['unassigned_at'] === null

        ) {

            throw new Exception(
                'Supervisor is already assigned to this project.'
            );

        }

        /*
        |--------------------------------------------------------------------------
        | Reactivate Existing Assignment
        |--------------------------------------------------------------------------
        */

        $stmt = mysqli_prepare(

            $conn,

            "
            UPDATE supervisor_pro_assignment

            SET

                status='active',

                assigned_at=NOW(),

                unassigned_at=NULL

            WHERE id=?
            "

        );

        $assignmentId = (int)$existing['id'];

        mysqli_stmt_bind_param(

            $stmt,

            "i",

            $assignmentId

        );

        mysqli_stmt_execute($stmt);

    } else {

        /*
        |--------------------------------------------------------------------------
        | CREATE NEW ASSIGNMENT
        |--------------------------------------------------------------------------
        */

        $stmt = mysqli_prepare(

            $conn,

            "
            INSERT INTO supervisor_pro_assignment
            (
                supervisor_id,
                org_id,
                pro_id,
                status
            )
            VALUES
            (
                ?,
                ?,
                ?,
                'active'
            )
            "

        );

        mysqli_stmt_bind_param(

            $stmt,

            "iss",

            $supervisorId,

            $orgId,

            $proId

        );

        mysqli_stmt_execute($stmt);

    }

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

    /*
    |--------------------------------------------------------------------------
    | RESPONSE
    |--------------------------------------------------------------------------
    */

    json_response([

        'status' => true,

        'message' => 'Project assigned successfully.',

        'data' => [

            'supervisor_id' => $supervisorId,

            'pro_id' => $proId,

            'status' => 'active'

        ]

    ], 200);

} catch (Exception $e) {

    mysqli_rollback($conn);

    json_response([

        'status' => false,

        'message' => $e->getMessage()

    ], 400);

}