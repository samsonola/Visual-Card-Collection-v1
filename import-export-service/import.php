<?php

/*
|--------------------------------------------------------------------------
| IMPORT API
|--------------------------------------------------------------------------
|
| Uploads a CSV/XLSX file.
| Creates an import job.
| Pushes the job to Redis.
|
*/

header("Access-Control-Allow-Origin: *");

header("Access-Control-Allow-Methods: POST, OPTIONS");

header(

    "Access-Control-Allow-Headers: Authorization, Content-Type"

);

if (

    $_SERVER['REQUEST_METHOD'] === 'OPTIONS'

) {

    http_response_code(200);

    exit;

}

require_once __DIR__ . '/../core/bootstrap.php';

require_once __DIR__ . '/helpers.php';

require_once __DIR__ . '/queue.php';

require_once __DIR__ . '/validator.php';



/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

$token = get_bearer_token();

if (!$token) {

    json_response([

        'success' => false,

        'message' => 'Unauthorized.'

    ],401);

}

$decoded = jwt_decode($token);

if (!$decoded) {

    json_response([

        'success' => false,

        'message' => 'Invalid token.'

    ],401);

}



/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
*/

$conn = db();



/*
|--------------------------------------------------------------------------
| Adapter
|--------------------------------------------------------------------------
*/

$adapter = trim(

    $_POST['adapter']

    ??

    ''

);

if ($adapter == '') {

    json_response([

        'success' => false,

        'message' => 'Adapter is required.'

    ],422);

}



/*
|--------------------------------------------------------------------------
| Supported Adapters
|--------------------------------------------------------------------------
*/

$allowedAdapters = [

    'community_user',

    'tbl_collection'

];

if (

    !in_array(

        $adapter,

        $allowedAdapters

    )

) {

    json_response([

        'success' => false,

        'message' => 'Unsupported adapter.'

    ],422);

}



/*
|--------------------------------------------------------------------------
| Uploaded File
|--------------------------------------------------------------------------
*/

if (

    !isset($_FILES['file'])

) {

    json_response([

        'success' => false,

        'message' => 'No file uploaded.'

    ],422);

}

$file = $_FILES['file'];



/*
|--------------------------------------------------------------------------
| Validate Upload
|--------------------------------------------------------------------------
*/

try {

    validate_upload_file(

        $file

    );

}

catch (Exception $e) {

    json_response([

        'success' => false,

        'message' => $e->getMessage()

    ],422);

}



/*
|--------------------------------------------------------------------------
| Storage Directory
|--------------------------------------------------------------------------
*/

$directory =

    job_path(

        'imports'

    );

if (

    !is_dir(

        $directory

    )

) {

    mkdir(

        $directory,

        0777,

        true

    );

}



/*
|--------------------------------------------------------------------------
| Store Upload
|--------------------------------------------------------------------------
*/

$extension = detect_file_type(

    $file['name']

);

$storedFilename =

    uuid()

    .

    '.'

    .

    $extension;

$storedPath =

    $directory

    .

    DIRECTORY_SEPARATOR

    .

    $storedFilename;

if (

    !move_uploaded_file(

        $file['tmp_name'],

        $storedPath

    )

) {

    json_response([

        'success' => false,

        'message' => 'Unable to store upload.'

    ],500);

}

/*
|--------------------------------------------------------------------------
| Import Job
|--------------------------------------------------------------------------
*/

$jobUuid = uuid();

$createdBy =

    isset($decoded->id)

    ?

    (int)$decoded->id

    :

    0;

$orgId =

    isset($_POST['org_id'])

    ?

    (int)$_POST['org_id']

    :

    0;

$originalFilename = mysqli_real_escape_string(

    $conn,

    $file['name']

);

$storedFilename = mysqli_real_escape_string(

    $conn,

    $storedFilename

);

$storedPath = mysqli_real_escape_string(

    $conn,

    $storedPath

);

$fileType = mysqli_real_escape_string(

    $conn,

    $extension

);

$fileSize =

    filesize(

        $storedPath

    );

mysqli_begin_transaction(

    $conn

);

try {

    $sql = "

        INSERT INTO import_jobs (

            uuid,

            adapter,

            original_filename,

            stored_filename,

            file_path,

            file_type,

            file_size,

            status,

            created_by,

            org_id

        )

        VALUES (

            '{$jobUuid}',

            '{$adapter}',

            '{$originalFilename}',

            '{$storedFilename}',

            '{$storedPath}',

            '{$fileType}',

            {$fileSize},

            'pending',

            {$createdBy},

            {$orgId}

        )

    ";

    if (

        !mysqli_query(

            $conn,

            $sql

        )

    ) {

        throw new Exception(

            mysqli_error(

                $conn

            )

        );

    }

    $jobId =

        mysqli_insert_id(

            $conn

        );

    /*
    |--------------------------------------------------------------------------
    | Push To Redis
    |--------------------------------------------------------------------------
    */

    queue_import_job([

        'job_id' => $jobId,

        'adapter' => $adapter

    ]);

    mysqli_commit(

        $conn

    );

}
catch (Exception $e) {

    mysqli_rollback(

        $conn

    );

    if (

        file_exists(

            $storedPath

        )

    ) {

        unlink(

            $storedPath

        );

    }

    json_response([

        'success' => false,

        'message' => $e->getMessage()

    ],500);

}



/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

json_response([

    'success' => true,

    'message' => 'Import queued successfully.',

    'job_id' => $jobId,

    'job_uuid' => $jobUuid,

    'status' => 'pending'

]);