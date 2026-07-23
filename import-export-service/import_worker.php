<?php

/*
|--------------------------------------------------------------------------
| IMPORT WORKER
|--------------------------------------------------------------------------
|
| Listens on Redis for import jobs.
| Loads import job.
| Loads adapter.
| Opens import file.
| Processing begins in Part 2.
|
*/

require_once __DIR__ . '/../core/bootstrap.php';

require_once __DIR__ . '/helpers.php';

require_once __DIR__ . '/queue.php';

require_once __DIR__ . '/retry.php';

require_once __DIR__ . '/csv_reader.php';

require_once __DIR__ . '/validator.php';



$conn = db();



$queue = $_ENV['IMPORT_QUEUE'];



log_message("======================================");

log_message("Import Worker Started");

log_message("Queue : {$queue}");

log_message("======================================");



while (true) {

    try {

        /*
        |--------------------------------------------------------------------------
        | Wait For Next Job
        |--------------------------------------------------------------------------
        */

        $job = queue_pop(

            $queue

        );

        if (!$job) {

            continue;

        }

        if (!is_array($job)) {

            log_message(

                "Invalid queue payload."

            );

            continue;

        }

        if (

            !isset($job['job_id'])

        ) {

            log_message(

                "job_id missing."

            );

            continue;

        }

        $jobId = (int)

            $job['job_id'];



        log_message(

            "Processing Import Job #{$jobId}"

        );



        /*
        |--------------------------------------------------------------------------
        | Load Job
        |--------------------------------------------------------------------------
        */

        $sql = "

            SELECT *

            FROM import_jobs

            WHERE id={$jobId}

            LIMIT 1

        ";

        $result = mysqli_query(

            $conn,

            $sql

        );

        if (!$result) {

            throw new Exception(

                mysqli_error($conn)

            );

        }

        if (

            !mysqli_num_rows(

                $result

            )

        ) {

            log_message(

                "Job not found."

            );

            continue;

        }

        $importJob =

            mysqli_fetch_assoc(

                $result

            );



        /*
        |--------------------------------------------------------------------------
        | Ignore Completed Jobs
        |--------------------------------------------------------------------------
        */

        if (

            $importJob['status']

            ==

            'completed'

        ) {

            log_message(

                "Already completed."

            );

            continue;

        }



        /*
        |--------------------------------------------------------------------------
        | Mark Processing
        |--------------------------------------------------------------------------
        */

        mark_processing(

            $conn,

            'import_jobs',

            $jobId

        );



        /*
        |--------------------------------------------------------------------------
        | Adapter
        |--------------------------------------------------------------------------
        */

        $adapter =

            $importJob['adapter'];



        $adapterFile =

            __DIR__

            .

            '/adapters/'

            .

            $adapter

            .

            '.php';



        if (

            !file_exists(

                $adapterFile

            )

        ) {

            throw new Exception(

                "Adapter {$adapter} not found."

            );

        }



        $config =

            require

            $adapterFile;



        if (

            !is_array(

                $config

            )

        ) {

            throw new Exception(

                "Invalid adapter."

            );

        }



        /*
        |--------------------------------------------------------------------------
        | Import File
        |--------------------------------------------------------------------------
        */

        $file =

            $importJob['file_path'];



        if (

            !file_exists(

                $file

            )

        ) {

            throw new Exception(

                "Import file not found."

            );

        }



        /*
        |--------------------------------------------------------------------------
        | Reader
        |--------------------------------------------------------------------------
        */

        $reader = new CSVReader(

            $file

        );



        $totalRows =

            $reader

            ->totalRows();



        /*
        |--------------------------------------------------------------------------
        | Update Job
        |--------------------------------------------------------------------------
        */

        mysqli_query(

            $conn,

            "

            UPDATE import_jobs

            SET

                total_rows={$totalRows},

                processed_rows=0,

                inserted_rows=0,

                updated_rows=0,

                skipped_rows=0,

                failed_rows=0,

                progress=0

            WHERE id={$jobId}

            LIMIT 1

            "

        );



        /*
        |--------------------------------------------------------------------------
        | Redis Live Progress
        |--------------------------------------------------------------------------
        */

        $cacheKey =

            "import:job:{$jobId}";



        cache_set(

            $cacheKey,

            [

                'status' => 'processing',

                'progress' => 0,

                'total' => $totalRows,

                'processed' => 0,

                'inserted' => 0,

                'updated' => 0,

                'skipped' => 0,

                'failed' => 0,

                'speed' => 0,

                'eta' => 0,

                'started_at' => time()

            ]

        );



        /*
        |--------------------------------------------------------------------------
        | Runtime Variables
        |--------------------------------------------------------------------------
        */

        $batchSize =

            (int)

            ($_ENV['IMPORT_BATCH_SIZE']

            ??

            1000);



        $processed = 0;

        $inserted = 0;

        $updated = 0;

        $failed = 0;

        $skipped = 0;



        $started = microtime(true);



        log_message(

            "Rows : {$totalRows}"

        );



        /*
        |--------------------------------------------------------------------------
        | Processing Continues
        |--------------------------------------------------------------------------
        */
        /*
        |--------------------------------------------------------------------------
        | Read CSV In Chunks
        |--------------------------------------------------------------------------
        */

        while (true) {

            $rows = $reader->chunk(

                $batchSize

            );

            if (

                empty($rows)

            ) {

                break;

            }

            /*
            |--------------------------------------------------------------------------
            | Header Validation (First Batch)
            |--------------------------------------------------------------------------
            */

            if (

                $processed == 0

            ) {

                if (

                    method_exists(

                        $reader,

                        'header'

                    )

                ) {

                    $header =

                        $reader->header();

                }
                else {

                    $header =

                        array_keys(

                            reset($rows)

                        );

                }

                if (

                    isset(

                        $config['required_columns']

                    )

                ) {

                    validate_required_columns(

                        $header,

                        $config['required_columns']

                    );

                }

            }

            /*
            |--------------------------------------------------------------------------
            | Prepare Batch
            |--------------------------------------------------------------------------
            */

            $batchInsert = [];

            $batchUpdate = [];

            $batchFailed = [];

            $batchSkipped = [];

            mysqli_begin_transaction(

                $conn

            );

            try {

                foreach (

                    $rows as

                    $row

                ) {

                    $processed++;

                    /*
                    ----------------------------------------------------------
                    Empty Row
                    ----------------------------------------------------------
                    */

                    if (

                        empty(

                            array_filter(

                                $row

                            )

                        )

                    ) {

                        $skipped++;

                        continue;

                    }

                    /*
                    ----------------------------------------------------------
                    Row Validation
                    ----------------------------------------------------------
                    */

                    if (

                        isset(

                            $config['validator']

                        )

                        &&

                        is_callable(

                            $config['validator']

                        )

                    ) {

                        $validation =

                            call_user_func(

                                $config['validator'],

                                $row

                            );

                        if (

                            $validation !== true

                        ) {

                            $failed++;

                            $batchFailed[] = [

                                'row' => $processed,

                                'reason' => $validation

                            ];

                            continue;

                        }

                    }

                    /*
                    ----------------------------------------------------------
                    Transform Row
                    ----------------------------------------------------------
                    */

                    if (

                        isset(

                            $config['transform']

                        )

                        &&

                        is_callable(

                            $config['transform']

                        )

                    ) {

                        $row =

                            call_user_func(

                                $config['transform'],

                                $row

                            );

                    }

                    /*
                    ----------------------------------------------------------
                    Insert Or Update?
                    ----------------------------------------------------------
                    */

                    if (

                        isset(

                            $config['upsert']

                        )

                        &&

                        $config['upsert']

                    ) {

                        $batchUpdate[] =

                            $row;

                    }
                    else {

                        $batchInsert[] =

                            $row;

                    }

                }

                /*
                |--------------------------------------------------------------------------
                | Nothing To Save?
                |--------------------------------------------------------------------------
                */

                if (

                    empty(

                        $batchInsert

                    )

                    &&

                    empty(

                        $batchUpdate

                    )

                ) {

                    mysqli_commit(

                        $conn

                    );

                    continue;

                }

                /*
                |--------------------------------------------------------------------------
                | Adapter Bulk Insert
                |--------------------------------------------------------------------------
                */

                if (

                    !empty(

                        $batchInsert

                    )

                ) {

                    if (

                        !isset(

                            $config['bulk_insert']

                        )

                    ) {

                        throw new Exception(

                            "bulk_insert callback missing."

                        );

                    }

                    $affected =

                        call_user_func(

                            $config['bulk_insert'],

                            $conn,

                            $batchInsert

                        );

                    $inserted +=

                        $affected;

                }

                /*
                |--------------------------------------------------------------------------
                | Adapter Bulk Update
                |--------------------------------------------------------------------------
                */

                if (

                    !empty(

                        $batchUpdate

                    )

                ) {

                    if (

                        !isset(

                            $config['bulk_update']

                        )

                    ) {

                        throw new Exception(

                            "bulk_update callback missing."

                        );

                    }

                    $affected =

                        call_user_func(

                            $config['bulk_update'],

                            $conn,

                            $batchUpdate

                        );

                    $updated +=

                        $affected;

                }

                mysqli_commit(

                    $conn

                );

            }
            catch (Exception $e) {

                mysqli_rollback(

                    $conn

                );

                throw $e;

            }

            /*
            |--------------------------------------------------------------------------
            | Continue To Progress Update
            |--------------------------------------------------------------------------
            */

                        /*
            |--------------------------------------------------------------------------
            | Progress
            |--------------------------------------------------------------------------
            */

            $elapsed =

                microtime(true)

                -

                $started;

            if (

                $elapsed <= 0

            ) {

                $elapsed = 1;

            }

            $speed =

                round(

                    $processed

                    /

                    $elapsed,

                    2

                );

            $progress =

                $totalRows > 0

                ?

                round(

                    (

                        $processed

                        /

                        $totalRows

                    ) * 100,

                    2

                )

                :

                100;

            $remaining =

                max(

                    0,

                    $totalRows - $processed

                );

            $eta =

                $speed > 0

                ?

                ceil(

                    $remaining

                    /

                    $speed

                )

                :

                0;



            /*
            |--------------------------------------------------------------------------
            | Update Database
            |--------------------------------------------------------------------------
            */

            $progressSql = "

                UPDATE import_jobs

                SET

                    processed_rows={$processed},

                    inserted_rows={$inserted},

                    updated_rows={$updated},

                    skipped_rows={$skipped},

                    failed_rows={$failed},

                    progress={$progress},

                    updated_at=NOW()

                WHERE id={$jobId}

                LIMIT 1

            ";

            if (

                !mysqli_query(

                    $conn,

                    $progressSql

                )

            ) {

                throw new Exception(

                    mysqli_error(

                        $conn

                    )

                );

            }



            /*
            |--------------------------------------------------------------------------
            | Redis Live Progress
            |--------------------------------------------------------------------------
            */

            cache_set(

                $cacheKey,

                [

                    'status' => 'processing',

                    'progress' => $progress,

                    'processed' => $processed,

                    'inserted' => $inserted,

                    'updated' => $updated,

                    'failed' => $failed,

                    'skipped' => $skipped,

                    'total' => $totalRows,

                    'speed' => $speed,

                    'eta' => $eta,

                    'memory' => memory_get_usage(true),

                    'peak_memory' => memory_get_peak_usage(true),

                    'updated_at' => time()

                ]

            );



            /*
            |--------------------------------------------------------------------------
            | Logging
            |--------------------------------------------------------------------------
            */

            log_message(

                sprintf(

                    "[Job:%d] %.2f%% | %d/%d | Inserted:%d | Updated:%d | Failed:%d | Speed:%s rows/sec",

                    $jobId,

                    $progress,

                    $processed,

                    $totalRows,

                    $inserted,

                    $updated,

                    $failed,

                    number_format(

                        $speed,

                        2

                    )

                )

            );



            /*
            |--------------------------------------------------------------------------
            | Memory Cleanup
            |--------------------------------------------------------------------------
            */

            unset(

                $rows,

                $batchInsert,

                $batchUpdate,

                $batchFailed,

                $batchSkipped

            );



            if (

                function_exists(

                    'gc_collect_cycles'

                )

            ) {

                gc_collect_cycles();

            }



            /*
            |--------------------------------------------------------------------------
            | Prevent Memory Growth
            |--------------------------------------------------------------------------
            */

            if (

                memory_get_usage(true)

                >

                256 * 1024 * 1024

            ) {

                log_message(

                    "High memory usage detected: "

                    .

                    format_bytes(

                        memory_get_usage(true)

                    )

                );

            }

        }

        /*
        |--------------------------------------------------------------------------
        | Import Finished
        |--------------------------------------------------------------------------
        */

        log_message(

            "Import processing completed for Job #{$jobId}"

        );

                /*
        |--------------------------------------------------------------------------
        | Job Completed Successfully
        |--------------------------------------------------------------------------
        */

        mark_completed(

            $conn,

            'import_jobs',

            $jobId

        );

        /*
        |--------------------------------------------------------------------------
        | Final Database Statistics
        |--------------------------------------------------------------------------
        */

        mysqli_query(

            $conn,

            "

            UPDATE import_jobs

            SET

                processed_rows={$processed},

                inserted_rows={$inserted},

                updated_rows={$updated},

                skipped_rows={$skipped},

                failed_rows={$failed},

                progress=100,

                status='completed',

                completed_at=NOW(),

                updated_at=NOW()

            WHERE id={$jobId}

            LIMIT 1

            "

        );

        /*
        |--------------------------------------------------------------------------
        | Final Redis Progress
        |--------------------------------------------------------------------------
        */

        cache_set(

            $cacheKey,

            [

                'status' => 'completed',

                'progress' => 100,

                'processed' => $processed,

                'inserted' => $inserted,

                'updated' => $updated,

                'failed' => $failed,

                'skipped' => $skipped,

                'total' => $totalRows,

                'speed' => $speed,

                'eta' => 0,

                'memory' => memory_get_peak_usage(true),

                'completed_at' => time()

            ]

        );

        /*
        |--------------------------------------------------------------------------
        | Optional Cleanup
        |--------------------------------------------------------------------------
        |
        | Delete uploaded CSV after successful import.
        | Disable this if you prefer keeping import files.
        |
        */

        if (

            file_exists($file)

        ) {

            @unlink(

                $file

            );

        }

        log_message(

            "======================================"

        );

        log_message(

            "Import Job {$jobId} Completed"

        );

        log_message(

            "Processed : {$processed}"

        );

        log_message(

            "Inserted : {$inserted}"

        );

        log_message(

            "Updated : {$updated}"

        );

        log_message(

            "Skipped : {$skipped}"

        );

        log_message(

            "Failed : {$failed}"

        );

        log_message(

            "======================================"

        );

    }

    /*
    |--------------------------------------------------------------------------
    | Worker Failure
    |--------------------------------------------------------------------------
    */

    catch (Exception $e) {

        log_message(

            "Import Job Failed : "

            .

            $e->getMessage()

        );

        if (

            isset($jobId)

        ) {

            retry_job(

                $conn,

                'import_jobs',

                $jobId,

                $e->getMessage()

            );

            if (

                can_retry(

                    $conn,

                    'import_jobs',

                    $jobId

                )

            ) {

                reset_job(

                    $conn,

                    'import_jobs',

                    $jobId

                );

                queue_import_job([

                    'job_id' => $jobId

                ]);

                cache_set(

                    "import:job:{$jobId}",

                    [

                        'status' => 'retrying',

                        'message' => $e->getMessage(),

                        'retrying' => true,

                        'updated_at' => time()

                    ]

                );

                log_message(

                    "Retry queued for Job {$jobId}"

                );

            }

            else {

                mark_failed(

                    $conn,

                    'import_jobs',

                    $jobId,

                    $e->getMessage()

                );

                cache_set(

                    "import:job:{$jobId}",

                    [

                        'status' => 'failed',

                        'message' => $e->getMessage(),

                        'processed' =>

                            $processed ?? 0,

                        'inserted' =>

                            $inserted ?? 0,

                        'updated' =>

                            $updated ?? 0,

                        'failed' =>

                            $failed ?? 0,

                        'updated_at' => time()

                    ]

                );

                log_message(

                    "Job {$jobId} permanently failed."

                );

            }

        }

    }

    /*
    |--------------------------------------------------------------------------
    | Prevent CPU Spinning
    |--------------------------------------------------------------------------
    */

    usleep(

        100000

    );

}