<?php

/**
 * ---------------------------------------------------------
 * Retry Service
 * ---------------------------------------------------------
 * Moves delayed jobs back into the live queue.
 * ---------------------------------------------------------
 */

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/queue.php';
require_once __DIR__ . '/helpers.php';

set_time_limit(0);

media_log('Retry service started.');

while (true) {

    try {

        /*
        |--------------------------------------------------------------------------
        | Get due jobs
        |--------------------------------------------------------------------------
        */

        $jobs = queue_due_jobs(
            $_ENV['MEDIA_QUEUE']
        );

        if (!empty($jobs)) {

            foreach ($jobs as $job) {

                $payload = json_decode(
                    $job,
                    true
                );

                /*
                |--------------------------------------------------------------------------
                | Push back into live queue
                |--------------------------------------------------------------------------
                */

                queue_push(

                    $_ENV['MEDIA_QUEUE'],

                    $payload

                );

                /*
                |--------------------------------------------------------------------------
                | Remove delayed copy
                |--------------------------------------------------------------------------
                */

                queue_remove_delayed(

                    $_ENV['MEDIA_QUEUE'],

                    $job

                );

                media_log(

                    'Retry queued for Media #' .

                    $payload['media_id']

                );

            }

        }

    }

    catch (Exception $e) {

        media_log(

            'Retry Service Error : ' .

            $e->getMessage()

        );

    }

    /*
    |--------------------------------------------------------------------------
    | Check every second
    |--------------------------------------------------------------------------
    */

    sleep(1);

}