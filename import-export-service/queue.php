<?php

/*
|--------------------------------------------------------------------------
| IMPORT / EXPORT QUEUE
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../core/bootstrap.php';

require_once __DIR__ . '/helpers.php';

/*
|--------------------------------------------------------------------------
| Redis Connection
|--------------------------------------------------------------------------
*/

function import_export_redis()
{
    static $redis = null;

    if ($redis !== null) {

        return $redis;

    }

    $redis = new Redis();

    $redis->connect(

        $_ENV['REDIS_HOST'],

        (int)$_ENV['REDIS_PORT']

    );

    if (!empty($_ENV['REDIS_PASSWORD'])) {

        $redis->auth(

            $_ENV['REDIS_PASSWORD']

        );

    }

    return $redis;
}

/*
|--------------------------------------------------------------------------
| Queue Import Job
|--------------------------------------------------------------------------
*/

function queue_import_job(array $payload)
{
    $redis = import_export_redis();

    return $redis->rPush(

        $_ENV['IMPORT_QUEUE'],

        json_encode($payload)

    );
}

/*
|--------------------------------------------------------------------------
| Queue Export Job
|--------------------------------------------------------------------------
*/

function queue_export_job(array $payload)
{
    $redis = import_export_redis();

    return $redis->rPush(

        $_ENV['EXPORT_QUEUE'],

        json_encode($payload)

    );
}

/*
|--------------------------------------------------------------------------
| Pop Import Job
|--------------------------------------------------------------------------
*/

function pop_import_job()
{
    $redis = import_export_redis();

    $job = $redis->blPop(

        [

            $_ENV['IMPORT_QUEUE']

        ],

        0

    );

    if (!$job) {

        return null;

    }

    return json_decode(

        $job[1],

        true

    );
}

/*
|--------------------------------------------------------------------------
| Pop Export Job
|--------------------------------------------------------------------------
*/

function pop_export_job()
{
    $redis = import_export_redis();

    $job = $redis->blPop(

        [

            $_ENV['EXPORT_QUEUE']

        ],

        0

    );

    if (!$job) {

        return null;

    }

    return json_decode(

        $job[1],

        true

    );
}

/*
|--------------------------------------------------------------------------
| Queue Length
|--------------------------------------------------------------------------
*/

function import_queue_length()
{
    return import_export_redis()->lLen(

        $_ENV['IMPORT_QUEUE']

    );
}

function export_queue_length()
{
    return import_export_redis()->lLen(

        $_ENV['EXPORT_QUEUE']

    );
}

/*
|--------------------------------------------------------------------------
| Clear Import Queue
|--------------------------------------------------------------------------
*/

function clear_import_queue()
{
    return import_export_redis()->del(

        $_ENV['IMPORT_QUEUE']

    );
}

/*
|--------------------------------------------------------------------------
| Clear Export Queue
|--------------------------------------------------------------------------
*/

function clear_export_queue()
{
    return import_export_redis()->del(

        $_ENV['EXPORT_QUEUE']

    );
}



/*
|--------------------------------------------------------------------------
| QUEUE EXPORT JOB
|--------------------------------------------------------------------------
|
| Pushes an export job onto the Redis export queue.
|
| Returns:
|   true  = queued
|   false = failed
|
*/

if (

    !function_exists(

        'queue_export_job'

    )

) {

    function queue_export_job(

        array $payload

    ): bool {

        /*
        |--------------------------------------------------------------------------
        | Validate Payload
        |--------------------------------------------------------------------------
        */

        if (

            empty(

                $payload

            )

        ) {

            log_message(

                "queue_export_job(): Empty payload."

            );

            return false;

        }

        if (

            !isset(

                $payload['job_id']

            )

        ) {

            log_message(

                "queue_export_job(): job_id missing."

            );

            return false;

        }

        /*
        |--------------------------------------------------------------------------
        | Queue Name
        |--------------------------------------------------------------------------
        */

        $queue =

            $_ENV['EXPORT_QUEUE'];

        /*
        |--------------------------------------------------------------------------
        | Queue Push
        |--------------------------------------------------------------------------
        */

        $success =

            queue_push(

                $queue,

                $payload

            );

        if (

            !$success

        ) {

            log_message(

                "Unable to queue Export Job #"

                .

                $payload['job_id']

            );

            return false;

        }

        /*
        |--------------------------------------------------------------------------
        | Redis Status
        |--------------------------------------------------------------------------
        */

        cache_set(

            "export:job:"

            .

            $payload['job_id'],

            [

                'status' => 'queued',

                'progress' => 0,

                'processed' => 0,

                'speed' => 0,

                'eta' => 0,

                'queued_at' => time()

            ]

        );

        /*
        |--------------------------------------------------------------------------
        | Logging
        |--------------------------------------------------------------------------
        */

        log_message(

            "Export Job #"

            .

            $payload['job_id']

            .

            " queued successfully."

        );

        return true;

    }

}