<?php

/*
|--------------------------------------------------------------------------
| EXPORT PROGRESS
|--------------------------------------------------------------------------
|
| Returns the live export progress from Redis.
| Falls back to MySQL if Redis cache is unavailable.
|
*/

require_once __DIR__ . '/../core/bootstrap.php';

require_once __DIR__ . '/helpers.php';

header(

    'Content-Type: application/json'

);

$conn = db();

/*
|--------------------------------------------------------------------------
| Job ID
|--------------------------------------------------------------------------
*/

$jobId =

    isset(

        $_GET['job_id']

    )

    ?

    (int)

    $_GET['job_id']

    :

    0;

if (

    $jobId <= 0

) {

    http_response_code(

        400

    );

    echo json_encode(

        [

            'success' => false,

            'message' => 'Invalid Job ID.'

        ]

    );

    exit;

}

/*
|--------------------------------------------------------------------------
| Redis
|--------------------------------------------------------------------------
*/

$cacheKey =

    "export:job:{$jobId}";

$progress =

    cache_get(

        $cacheKey

    );

/*
|--------------------------------------------------------------------------
| Redis Hit
|--------------------------------------------------------------------------
*/

if (

    !empty(

        $progress

    )

) {

    echo json_encode(

        [

            'success' => true,

            'source' => 'redis',

            'data' => $progress

        ]

    );

    exit;

}

/*
|--------------------------------------------------------------------------
| Database Fallback
|--------------------------------------------------------------------------
*/

$sql = "

    SELECT

        id,

        status,

        progress,

        processed_rows,

        total_rows,

        file_path,

        file_size,

        created_at,

        updated_at,

        completed_at

    FROM export_jobs

    WHERE id={$jobId}

    LIMIT 1

";

$result = mysqli_query(

    $conn,

    $sql

);

if (

    !$result

) {

    http_response_code(

        500

    );

    echo json_encode(

        [

            'success' => false,

            'message' => mysqli_error(

                $conn

            )

        ]

    );

    exit;

}

if (

    !mysqli_num_rows(

        $result

    )

) {

    http_response_code(

        404

    );

    echo json_encode(

        [

            'success' => false,

            'message' => 'Export job not found.'

        ]

    );

    exit;

}

$job =

    mysqli_fetch_assoc(

        $result

    );

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

$response =

    [

        'status' =>

            $job['status'],

        'progress' =>

            (float)

            $job['progress'],

        'processed' =>

            (int)

            $job['processed_rows'],

        'total' =>

            (int)

            $job['total_rows'],

        'speed' => 0,

        'eta' => 0,

        'memory' => 0,

        'peak_memory' => 0,

        'file' =>

            $job['file_path'],

        'file_size' =>

            (int)

            $job['file_size'],

        'completed_at' =>

            $job['completed_at'],

        'updated_at' =>

            $job['updated_at']

    ];

/*
|--------------------------------------------------------------------------
| Cache Database Result
|--------------------------------------------------------------------------
*/

cache_set(

    $cacheKey,

    $response,

    30

);

/*
|--------------------------------------------------------------------------
| JSON Response
|--------------------------------------------------------------------------
*/

echo json_encode(

    [

        'success' => true,

        'source' => 'database',

        'data' => $response

    ],

    JSON_PRETTY_PRINT

);