<?php

/*
|--------------------------------------------------------------------------
| EXPORT API
|--------------------------------------------------------------------------
|
| Creates an export job.
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

        'success'=>false,

        'message'=>'Adapter is required.'

    ],422);

}

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

        'success'=>false,

        'message'=>'Unsupported adapter.'

    ],422);

}



/*
|--------------------------------------------------------------------------
| Export Type
|--------------------------------------------------------------------------
*/

$exportType = strtolower(

    trim(

        $_POST['export_type']

        ??

        'csv'

    )

);

if (

    !in_array(

        $exportType,

        [

            'csv',

            'xlsx'

        ]

    )

) {

    json_response([

        'success'=>false,

        'message'=>'Invalid export type.'

    ],422);

}



/*
|--------------------------------------------------------------------------
| Export Profile
|--------------------------------------------------------------------------
*/

$profile = trim(

    $_POST['profile']

    ??

    'full'

);



/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$filters = [];

if (

    isset($_POST['filters'])

) {

    $filters = json_decode(

        $_POST['filters'],

        true

    );

    if (

        !is_array(

            $filters

        )

    ) {

        json_response([

            'success'=>false,

            'message'=>'Invalid filters.'

        ],422);

    }

}


/*
|--------------------------------------------------------------------------
| Create Job
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

$filterJson = mysqli_real_escape_string(

    $conn,

    json_encode(

        $filters

    )

);

mysqli_begin_transaction(

    $conn

);

try {

    $sql = "

        INSERT INTO export_jobs (

            uuid,

            adapter,

            export_type,

            status,

            filters,

            export_profile,

            created_by,

            org_id

        )

        VALUES (

            '{$jobUuid}',

            '{$adapter}',

            '{$exportType}',

            'pending',

            '{$filterJson}',

            '{$profile}',

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

    $jobId = mysqli_insert_id(

        $conn

    );

    /*
    |--------------------------------------------------------------------------
    | Queue Job
    |--------------------------------------------------------------------------
    */

    queue_export_job([

        'job_id'=>$jobId,

        'adapter'=>$adapter

    ]);

    mysqli_commit(

        $conn

    );

}
catch(Exception $e){

    mysqli_rollback(

        $conn

    );

    json_response([

        'success'=>false,

        'message'=>$e->getMessage()

    ],500);

}



/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

json_response([

    'success'=>true,

    'message'=>'Export queued successfully.',

    'job_id'=>$jobId,

    'job_uuid'=>$jobUuid,

    'status'=>'pending'

]);