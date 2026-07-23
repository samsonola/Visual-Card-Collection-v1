<?php

/*
|--------------------------------------------------------------------------
| CANCEL EXPORT
|--------------------------------------------------------------------------
|
| Cancels an export job.
| Running jobs will terminate gracefully.
|
*/

require_once __DIR__ . '/../core/bootstrap.php';

require_once __DIR__ . '/helpers.php';

$conn = db();

header(

    'Content-Type: application/json'

);

/*
|--------------------------------------------------------------------------
| Job ID
|--------------------------------------------------------------------------
*/

$jobId =

    isset(

        $_POST['job_id']

    )

    ?

    (int)

    $_POST['job_id']

    :

    (

        isset(

            $_GET['job_id']

        )

        ?

        (int)

        $_GET['job_id']

        :

        0

    );

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
| Load Job
|--------------------------------------------------------------------------
*/

$sql = "

    SELECT *

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
| Already Finished?
|--------------------------------------------------------------------------
*/

if (

    in_array(

        $job['status'],

        [

            'completed',

            'failed',

            'cancelled'

        ]

    )

) {

    echo json_encode(

        [

            'success' => false,

            'message' => 'Job cannot be cancelled.'

        ]

    );

    exit;

}

/*
|--------------------------------------------------------------------------
| Mark Cancelled
|--------------------------------------------------------------------------
*/

$sql = "

    UPDATE export_jobs

    SET

        status='cancelled',

        updated_at=NOW()

    WHERE id={$jobId}

    LIMIT 1

";

if (

    !mysqli_query(

        $conn,

        $sql

    )

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

/*
|--------------------------------------------------------------------------
| Redis Flag
|--------------------------------------------------------------------------
*/

cache_set(

    "export:job:{$jobId}",

    [

        'status' => 'cancelled',

        'progress' =>

            (float)

            $job['progress'],

        'processed' =>

            (int)

            $job['processed_rows'],

        'cancelled' => true,

        'cancelled_at' => time()

    ]

);

/*
|--------------------------------------------------------------------------
| Remove Queue Item (Best Effort)
|--------------------------------------------------------------------------
|
| If your queue helper supports removing queued jobs,
| uncomment the call below.
|
| queue_remove(
|     $_ENV['EXPORT_QUEUE'],
|     ['job_id' => $jobId]
| );
|
*/

/*
|--------------------------------------------------------------------------
| Logging
|--------------------------------------------------------------------------
*/

log_message(

    "Export Job {$jobId} cancelled."

);

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

echo json_encode(

    [

        'success' => true,

        'message' => 'Export cancelled successfully.',

        'job_id' => $jobId

    ],

    JSON_PRETTY_PRINT

);