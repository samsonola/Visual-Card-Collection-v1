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
        'status'  => false,
        'message' => 'Unauthorized.'
    ], 401);

}

$decoded = jwt_decode($token);

if (!$decoded) {

    json_response([
        'status'  => false,
        'message' => 'Invalid token.'
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
$orgName = $decoded->name ?? null;

if (!$orgId || !$orgName) {

    json_response([
        'status'  => false,
        'message' => 'Organisation details not found.'
    ], 401);

}

$conn = db();

/*
|--------------------------------------------------------------------------
| VALIDATION
|--------------------------------------------------------------------------
*/

$projectName = trim($_POST['pro_name'] ?? '');

if ($projectName === '') {

    json_response([
        'status'  => false,
        'message' => 'Project name is required.'
    ], 422);

}

if (strlen($projectName) < 3) {

    json_response([
        'status'  => false,
        'message' => 'Project name must be at least 3 characters.'
    ], 422);

}

/*
|--------------------------------------------------------------------------
| DUPLICATE PROJECT
|--------------------------------------------------------------------------
*/

$stmt = mysqli_prepare(
    $conn,
    "
    SELECT id
    FROM organisations_project
    WHERE org_id=?
    AND LOWER(pro_name)=LOWER(?)
    LIMIT 1
    "
);

mysqli_stmt_bind_param(
    $stmt,
    "ss",
    $orgId,
    $projectName
);

mysqli_stmt_execute($stmt);

if (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {

    json_response([
        'status'  => false,
        'message' => 'A project with this name already exists.'
    ], 409);

}

/*
|--------------------------------------------------------------------------
| GENERATE UNIQUE PROJECT ID
|--------------------------------------------------------------------------
*/

do {

    $proId = strtoupper(substr(bin2hex(random_bytes(8)), 0, 8));

    $stmt = mysqli_prepare(
        $conn,
        "
        SELECT id
        FROM organisations_project
        WHERE pro_id=?
        LIMIT 1
        "
    );

    mysqli_stmt_bind_param(
        $stmt,
        "s",
        $proId
    );

    mysqli_stmt_execute($stmt);

    $exists = mysqli_fetch_assoc(
        mysqli_stmt_get_result($stmt)
    );

} while ($exists);

/*
|--------------------------------------------------------------------------
| SLUG
|--------------------------------------------------------------------------
*/

$slug = strtolower($projectName);

$slug = preg_replace('/[^a-z0-9]+/', '-', $slug);

$slug = trim($slug, '-');

/*
|--------------------------------------------------------------------------
| INSERT
|--------------------------------------------------------------------------
*/

mysqli_begin_transaction($conn);

try {

    $stmt = mysqli_prepare(
        $conn,
        "
        INSERT INTO organisations_project
        (
            pro_name,
            pro_id,
            org_name,
            org_id,
            pro_slug
        )
        VALUES
        (
            ?,
            ?,
            ?,
            ?,
            ?
        )
        "
    );

    mysqli_stmt_bind_param(
        $stmt,
        "sssss",
        $projectName,
        $proId,
        $orgName,
        $orgId,
        $slug
    );

    mysqli_stmt_execute($stmt);

    if (mysqli_stmt_affected_rows($stmt) <= 0) {

        throw new Exception('Unable to create project.');

    }

    $projectId = mysqli_insert_id($conn);

    mysqli_commit($conn);

} catch (Throwable $e) {

    mysqli_rollback($conn);

    json_response([
        'status'  => false,
        'message' => $e->getMessage()
    ], 500);

}

/*
|--------------------------------------------------------------------------
| BATCH 2 CONTINUES HERE
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| QUEUE PROJECT IMAGE (OPTIONAL)
|--------------------------------------------------------------------------
*/

$mediaId = null;

if (

    isset($_FILES['project_image']) &&

    $_FILES['project_image']['error'] === UPLOAD_ERR_OK

) {

    try {

        $mediaId = queue_media_upload(

            'organisations_project',

            'id',

            $projectId,

            'project_image',

            'projects',

            $_FILES['project_image']

        );

    } catch (Throwable $e) {

        media_log(

            'Project Image Upload: ' . $e->getMessage()

        );

    }

}

/*
|--------------------------------------------------------------------------
| CLEAR CACHE
|--------------------------------------------------------------------------
|
| Remove dashboard and project cache so the newly created
| project is immediately visible.
|--------------------------------------------------------------------------
*/

cacheForget(sprintf(
    'organisation:%s:projects:%d:%d:%s',
    $orgId,
    1,
    20,
    md5('')
));

cacheForget("organisation:{$orgId}:dashboard:cardStats");

cacheForget("organisation:{$orgId}:dashboard:coverage");

/*
|--------------------------------------------------------------------------
| LOG
|--------------------------------------------------------------------------
*/

log_info(

    'projects',

    'Project created successfully',

    [

        'project_id'   => $projectId,

        'pro_id'       => $proId,

        'project_name' => $projectName,

        'organisation' => $orgId,

        'created_by'   => $decoded->id

    ]

);



json_response([

    'status'  => true,

    'message' => 'Project created successfully.',

    'data' => [

        'id' => $projectId,

        'pro_id' => $proId,

        'project_name' => $projectName,

        'organisation' => [

            'org_id' => $orgId,

            'org_name' => $orgName

        ],

        'image' => [

            'media_id' => $mediaId,

            'status' => $mediaId
                ? 'queued'
                : 'no_image'

        ]

    ]

]);