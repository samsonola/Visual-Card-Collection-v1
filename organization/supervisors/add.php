<?php

declare(strict_types=1);

require_once __DIR__ . '/../../core/bootstrap.php';
require_once __DIR__ . '/../../media_service/media.php';

/*
|--------------------------------------------------------------------------
| AUTHENTICATION
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

/*
|--------------------------------------------------------------------------
| AUTHORISATION
|--------------------------------------------------------------------------
*/

if (($decoded->role ?? '') !== 'organisation') {

    json_response([
        'status' => false,
        'message' => 'Access denied.'
    ], 403);

}

$orgId = $decoded->org_id ?? null;

if (!$orgId) {

    json_response([
        'status' => false,
        'message' => 'Organisation not found.'
    ], 401);

}

$conn = db();

/*
|--------------------------------------------------------------------------
| REQUEST
|--------------------------------------------------------------------------
*/

$fullName = trim($_POST['fullName'] ?? '');

$username = trim($_POST['username'] ?? '');

$email = trim($_POST['email'] ?? '');

$password = trim($_POST['password'] ?? '');

$proId = trim($_POST['pro_id'] ?? '');

/*
|--------------------------------------------------------------------------
| VALIDATION
|--------------------------------------------------------------------------
*/

if (

    $fullName === '' ||

    $username === '' ||

    $email === '' ||

    $password === ''

) {

    json_response([

        'status' => false,

        'message' => 'Missing required fields.'

    ],422);

}

/*
|--------------------------------------------------------------------------
| SPLIT NAME
|--------------------------------------------------------------------------
*/

$nameParts = preg_split('/\s+/', $fullName);

$firstName = array_shift($nameParts);

$lastName = trim(implode(' ', $nameParts));

if ($lastName === '') {

    $lastName = '-';

}

/*
|--------------------------------------------------------------------------
| START TRANSACTION
|--------------------------------------------------------------------------
*/

mysqli_begin_transaction($conn);

try {

    /*
    |--------------------------------------------------------------------------
    | USERNAME EXISTS
    |--------------------------------------------------------------------------
    */

    $stmt = mysqli_prepare(

        $conn,

        "

        SELECT id

        FROM supervisor

        WHERE username=?

        LIMIT 1

        "

    );

    mysqli_stmt_bind_param(

        $stmt,

        "s",

        $username

    );

    mysqli_stmt_execute($stmt);

    if (mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0) {

        throw new Exception("Username already exists.");

    }

    /*
    |--------------------------------------------------------------------------
    | EMAIL EXISTS
    |--------------------------------------------------------------------------
    */

    $stmt = mysqli_prepare(

        $conn,

        "

        SELECT id

        FROM supervisor

        WHERE email=?

        LIMIT 1

        "

    );

    mysqli_stmt_bind_param(

        $stmt,

        "s",

        $email

    );

    mysqli_stmt_execute($stmt);

    if (mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0) {

        throw new Exception("Email already exists.");

    }

    /*
    |--------------------------------------------------------------------------
    | VERIFY PROJECT (OPTIONAL)
    |--------------------------------------------------------------------------
    */

    if ($proId !== '') {

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

        if (mysqli_num_rows(mysqli_stmt_get_result($stmt)) === 0) {

            throw new Exception("Project not found.");

        }

    }

    /*
    |--------------------------------------------------------------------------
    | CREATE SUPERVISOR
    |--------------------------------------------------------------------------
    */

    $hashedPassword = password_hash(

        $password,

        PASSWORD_BCRYPT

    );

    $stmt = mysqli_prepare(

        $conn,

        "

        INSERT INTO supervisor(

            firstName,

            lastName,

            username,

            email,

            password,

            profile_photo,

            org_id

        )

        VALUES(

            ?,?,?,?,?,?,?

        )

        "

    );

    $profilePhoto = null;

    mysqli_stmt_bind_param(

        $stmt,

        "sssssss",

        $firstName,

        $lastName,

        $username,

        $email,

        $hashedPassword,

        $profilePhoto,

        $orgId

    );

    mysqli_stmt_execute($stmt);

    $supervisorId = mysqli_insert_id($conn);

    /*
    |--------------------------------------------------------------------------
    | ASSIGN PROJECT (OPTIONAL)
    |--------------------------------------------------------------------------
    */

    if ($proId !== '') {

        $stmt = mysqli_prepare(

            $conn,

            "

            INSERT INTO supervisor_pro_assignment(

                supervisor_id,

                org_id,

                pro_id,

                status

            )

            VALUES(

                ?,?,?, 'active'

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

    mysqli_commit($conn);

    /*
    |--------------------------------------------------------------------------
    | Batch 2 continues...
    |--------------------------------------------------------------------------
    */

        /*
    |--------------------------------------------------------------------------
    | QUEUE PROFILE PHOTO (OPTIONAL)
    |--------------------------------------------------------------------------
    */

    $mediaId = null;

    if (

        isset($_FILES['profile_photo']) &&

        $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK

    ) {

        try {

            $mediaId = queue_media_upload(

                'supervisor',

                'id',

                $supervisorId,

                'profile_photo',

                'supervisors',

                $_FILES['profile_photo']

            );

        } catch (Exception $e) {

            // Never fail supervisor creation because of media upload

            media_log($e->getMessage());

        }

    }

    /*
    |--------------------------------------------------------------------------
    | CACHE INVALIDATION
    |--------------------------------------------------------------------------
    */

    cacheForget(sprintf(
        'organisation:%s:dashboard:cardStats',
        $orgId
    ));

    cacheForget(sprintf(
        'organisation:%s:dashboard:coverage',
        $orgId
    ));

    cacheForget(sprintf(
        'organisation:%s:projects:1:20:%s',
        $orgId,
        md5('')
    ));

    cacheForget(sprintf(
        'organisation:%s:supervisors:1:20:%s',
        $orgId,
        md5('')
    ));

    json_response([

        'status' => true,

        'message' => 'Supervisor created successfully.',

        'data' => [

            'supervisor_id' => $supervisorId,

            'assigned_project' => $proId !== ''
                ? $proId
                : null,

            'media_id' => $mediaId,

            'image_status' => $mediaId
                ? 'queued'
                : 'no_image'

        ]

    ], 201);

} catch (Exception $e) {

    mysqli_rollback($conn);

    json_response([

        'status' => false,

        'message' => $e->getMessage()

    ], 500);

}