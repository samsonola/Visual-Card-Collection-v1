<?php

/**
 * ---------------------------------------------------------
 * MEDIA WORKER
 * ---------------------------------------------------------
 *
 * Listens to Redis forever.
 *
 * Flow
 *
 * Redis Queue
 *      ↓
 * Load media row
 *      ↓
 * Mark Processing
 *      ↓
 * Upload to S3
 *      ↓
 * Update media row
 *      ↓
 * Update destination table
 *      ↓
 * Delete local temp file
 *      ↓
 * Done
 *
 */

require_once __DIR__ . '/../core/bootstrap.php';

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/queue.php';
require_once __DIR__ . '/s3.php';

set_time_limit(0);

$conn = db();

$queue = $_ENV['MEDIA_QUEUE'];

media_log('==============================');
media_log('Media Worker Started');
media_log('Listening on queue : ' . $queue);
media_log('==============================');

while (true) {

    $mediaId = 0;

    

    try {

        /*
        |--------------------------------------------------------------------------
        | Wait forever for next job
        |--------------------------------------------------------------------------
        */

        $job = queue_pop($queue);

        if (!$job) {

            continue;

        }

        if (!is_array($job)) {

            media_log("Invalid queue payload.");

            continue;

        }

$payload = $job;

        if (!$payload) {

            media_log('Invalid queue payload.');

            continue;

        }

        if (!isset($payload['media_id'])) {

            media_log('media_id missing.');

            continue;

        }

        $mediaId = (int)$payload['media_id'];

        media_log(
            "Processing Media #{$mediaId}"
        );

        /*
        |--------------------------------------------------------------------------
        | Load media record
        |--------------------------------------------------------------------------
        */

        $sql = "

            SELECT *

            FROM media

            WHERE id={$mediaId}

            LIMIT 1

        ";

        $result = mysqli_query($conn, $sql);

        if (!$result) {

            throw new Exception(
                mysqli_error($conn)
            );

        }

        if (!mysqli_num_rows($result)) {

            media_log(
                "Media {$mediaId} not found."
            );

            continue;

        }

        $media = mysqli_fetch_assoc($result);

        /*
        |--------------------------------------------------------------------------
        | Ignore already uploaded
        |--------------------------------------------------------------------------
        */

        if ($media['status'] === 'uploaded') {

            media_log(
                "Media {$mediaId} already uploaded."
            );

            continue;

        }

        /*
        |--------------------------------------------------------------------------
        | Mark Processing
        |--------------------------------------------------------------------------
        */

        mysqli_query(

            $conn,

            "

            UPDATE media

            SET status='processing'

            WHERE id={$mediaId}

            "

        );

        media_log(
            "Uploading Media #{$mediaId}"
        );

        /*
        |--------------------------------------------------------------------------
        | Upload To S3
        |--------------------------------------------------------------------------
        */

        $upload = s3_upload(

            $media['local_path'],

            $media['folder']

        );

        /*
        |--------------------------------------------------------------------------
        | Upload Failed
        |--------------------------------------------------------------------------
        */

        if (!$upload['success']) {

            throw new Exception(

                $upload['error']

            );

        }

        $s3Url = mysqli_real_escape_string(

            $conn,

            $upload['url']

        );

        $s3Key = mysqli_real_escape_string(

            $conn,

            $upload['key']

        );

        media_log(

            "Uploaded successfully : {$s3Key}"

        );

        /*
        |--------------------------------------------------------------------------
        | Begin Transaction
        |--------------------------------------------------------------------------
        */

        mysqli_begin_transaction($conn);

        try {

            /*
            --------------------------------------------------
            Update media record
            --------------------------------------------------
            */

            $updateMedia = "

            UPDATE media

            SET

                status='uploaded',

                s3_url='{$s3Url}',

                s3_key='{$s3Key}',

                last_error=NULL

            WHERE id={$mediaId}

            ";

            if (!mysqli_query($conn, $updateMedia)) {

                throw new Exception(

                    mysqli_error($conn)

                );

            }

            /*
            --------------------------------------------------
            Destination table update starts here
            (continues in Part 2)
            --------------------------------------------------
            */
            
            $table = mysqli_real_escape_string(

                $conn,

                $media['entity_table']

            );

            $pkColumn = mysqli_real_escape_string(

                $conn,

                $media['entity_pk_column']

            );

            $updateColumn = mysqli_real_escape_string(

                $conn,

                $media['update_column']

            );

            $entityPk = mysqli_real_escape_string(

                $conn,

                $media['entity_pk']

            );

            /*
            --------------------------------------------------
            Generic destination update
            --------------------------------------------------
            */

            $updateEntity = "

                UPDATE {$table}

                SET

                    {$updateColumn}='{$s3Url}'

                WHERE

                    {$pkColumn}='{$entityPk}'

                LIMIT 1

            ";

            if (!mysqli_query($conn, $updateEntity)) {

                throw new Exception(

                    mysqli_error($conn)

                );

            }

            /*
            --------------------------------------------------
            Commit transaction
            --------------------------------------------------
            */

            mysqli_commit($conn);

            /*
            --------------------------------------------------
            Delete temporary image
            --------------------------------------------------
            */

            media_delete_temp(

                $media['local_path']

            );

            media_log(

                "Media {$mediaId} completed."

            );

        }

        catch (Exception $e) {

            mysqli_rollback($conn);

            throw $e;

        }

    }

    catch (Exception $e) {

        /*
        |--------------------------------------------------------------------------
        | Failure
        |--------------------------------------------------------------------------
        */

        media_log(

            "Media {$mediaId} failed : " .

            $e->getMessage()

        );

        /*
        --------------------------------------------------
        Reload retry information
        --------------------------------------------------
        */

        $retrySql = "

            SELECT

                retry_count,

                max_retries

            FROM media

            WHERE id={$mediaId}

            LIMIT 1

        ";

        $retryResult = mysqli_query(

            $conn,

            $retrySql

        );

        if (!$retryResult ||

            !mysqli_num_rows($retryResult)

        ) {

            continue;

        }

        $retry = mysqli_fetch_assoc(

            $retryResult

        );

        $retryCount =

            (int)$retry['retry_count'] + 1;

        $maxRetries =

            (int)$retry['max_retries'];

        /*
        --------------------------------------------------
        Update retry counter
        --------------------------------------------------
        */

        mysqli_query(

            $conn,

            "

            UPDATE media

            SET

                retry_count={$retryCount},

                last_error='" .

                mysqli_real_escape_string(

                    $conn,

                    $e->getMessage()

                ) .

                "',

                status='failed'

            WHERE id={$mediaId}

            "

        );

        /*
        --------------------------------------------------
        Retry?
        --------------------------------------------------
        */

        if (

            $retryCount <= $maxRetries

        ) {

            /*
            Exponential backoff

            10

            20

            40

            80

            160

            */

            $delay =

                pow(

                    2,

                    $retryCount

                ) * 10;

            media_log(

                "Retry {$retryCount}/{$maxRetries}"

            );

            media_log(

                "Waiting {$delay} seconds."

            );

            queue_delay(

                $_ENV['MEDIA_QUEUE'],

                [

                    'media_id' => $mediaId

                ],

                $delay

            );

            continue;

        }

        /*
        --------------------------------------------------
        Permanently failed
        --------------------------------------------------
        */

        media_log(

            "Media {$mediaId} permanently failed."

        );
        /*
        --------------------------------------------------
        Push to failed queue
        --------------------------------------------------
        */

        queue_push(

            $_ENV['MEDIA_QUEUE'] . ':failed',

            [

                'media_id' => $mediaId,

                'error' => $e->getMessage(),

                'failed_at' => date('Y-m-d H:i:s')

            ]

        );

        /*
        --------------------------------------------------
        Ensure media status remains failed
        --------------------------------------------------
        */

        mysqli_query(

            $conn,

            "

            UPDATE media

            SET

                status='failed',

                last_error='" .

                mysqli_real_escape_string(

                    $conn,

                    $e->getMessage()

                ) .

                "'

            WHERE id={$mediaId}

            "

        );

    }

}

/*
|--------------------------------------------------------------------------
| Worker should never reach here
|--------------------------------------------------------------------------
*/

media_log(

    'Media worker stopped.'

);

exit;