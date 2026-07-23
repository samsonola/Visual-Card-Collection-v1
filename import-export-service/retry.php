<?php

/*
|--------------------------------------------------------------------------
| IMPORT / EXPORT RETRY
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/helpers.php';

/*
|--------------------------------------------------------------------------
| Increment Retry
|--------------------------------------------------------------------------
*/

function retry_job(

    mysqli $conn,

    string $table,

    int $jobId,

    string $error

)
{

    $jobId = (int)$jobId;

    $error = mysqli_real_escape_string(

        $conn,

        $error

    );

    $sql = "

        UPDATE {$table}

        SET

            retry_count = retry_count + 1,

            last_error = '{$error}',

            updated_at = NOW()

        WHERE id = {$jobId}

        LIMIT 1

    ";

    mysqli_query(

        $conn,

        $sql

    );

}

/*
|--------------------------------------------------------------------------
| Get Retry Information
|--------------------------------------------------------------------------
*/

function retry_information(

    mysqli $conn,

    string $table,

    int $jobId

)
{

    $sql = "

        SELECT

            retry_count,

            max_retries

        FROM {$table}

        WHERE id={$jobId}

        LIMIT 1

    ";

    $result = mysqli_query(

        $conn,

        $sql

    );

    if (

        !$result ||

        !mysqli_num_rows($result)

    ) {

        return null;

    }

    return mysqli_fetch_assoc(

        $result

    );

}

/*
|--------------------------------------------------------------------------
| Retry Allowed
|--------------------------------------------------------------------------
*/

function can_retry(

    mysqli $conn,

    string $table,

    int $jobId

)
{

    $retry = retry_information(

        $conn,

        $table,

        $jobId

    );

    if (!$retry) {

        return false;

    }

    return

        $retry['retry_count']

        <

        $retry['max_retries'];

}

/*
|--------------------------------------------------------------------------
| Mark Processing
|--------------------------------------------------------------------------
*/

function mark_processing(

    mysqli $conn,

    string $table,

    int $jobId

)
{

    $sql = "

        UPDATE {$table}

        SET

            status='processing',

            started_at = NOW(),

            updated_at = NOW()

        WHERE id={$jobId}

        LIMIT 1

    ";

    mysqli_query(

        $conn,

        $sql

    );

}

/*
|--------------------------------------------------------------------------
| Mark Completed
|--------------------------------------------------------------------------
*/

function mark_completed(

    mysqli $conn,

    string $table,

    int $jobId

)
{

    $sql = "

        UPDATE {$table}

        SET

            status='completed',

            completed_at = NOW(),

            updated_at = NOW()

        WHERE id={$jobId}

        LIMIT 1

    ";

    mysqli_query(

        $conn,

        $sql

    );

}

/*
|--------------------------------------------------------------------------
| Mark Failed
|--------------------------------------------------------------------------
*/

function mark_failed(

    mysqli $conn,

    string $table,

    int $jobId,

    string $error

)
{

    $error = mysqli_real_escape_string(

        $conn,

        $error

    );

    $sql = "

        UPDATE {$table}

        SET

            status='failed',

            last_error='{$error}',

            completed_at=NOW(),

            updated_at=NOW()

        WHERE id={$jobId}

        LIMIT 1

    ";

    mysqli_query(

        $conn,

        $sql

    );

}

/*
|--------------------------------------------------------------------------
| Reset Job
|--------------------------------------------------------------------------
*/

function reset_job(

    mysqli $conn,

    string $table,

    int $jobId

)
{

    $sql = "

        UPDATE {$table}

        SET

            status='pending',

            updated_at=NOW()

        WHERE id={$jobId}

        LIMIT 1

    ";

    mysqli_query(

        $conn,

        $sql

    );

}