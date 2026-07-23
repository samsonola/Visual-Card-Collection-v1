<?php

/*
|--------------------------------------------------------------------------
| EXPORT WORKER
|--------------------------------------------------------------------------
|
| Listens on Redis for export jobs.
| Loads export job.
| Loads adapter.
| Streams database records.
| Writes CSV/XLSX.
| Export begins in Part 2.
|
*/

require_once __DIR__ . '/../core/bootstrap.php';

require_once __DIR__ . '/helpers.php';

require_once __DIR__ . '/queue.php';

require_once __DIR__ . '/retry.php';

require_once __DIR__ . '/csv_writer.php';

require_once __DIR__ . '/xlsx_writer.php';

$conn = db();

$queue = $_ENV['EXPORT_QUEUE'];

log_message("======================================");
log_message("Export Worker Started");
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

            !isset(

                $job['job_id']

            )

        ) {

            log_message(

                "job_id missing."

            );

            continue;

        }

        $jobId = (int)

            $job['job_id'];

        log_message(

            "Processing Export Job #{$jobId}"

        );

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

        if (!$result) {

            throw new Exception(

                mysqli_error(

                    $conn

                )

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

        $exportJob =

            mysqli_fetch_assoc(

                $result

            );

        /*
        |--------------------------------------------------------------------------
        | Ignore Completed Jobs
        |--------------------------------------------------------------------------
        */

        if (

            $exportJob['status']

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

            'export_jobs',

            $jobId

        );

        /*
        |--------------------------------------------------------------------------
        | Adapter
        |--------------------------------------------------------------------------
        */

        $adapter =

            $exportJob['adapter'];

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
        | Determine Export Format
        |--------------------------------------------------------------------------
        */

        $format = strtolower(

            $exportJob['file_type']

            ??

            'csv'

        );

        if (

            !in_array(

                $format,

                [

                    'csv',

                    'xlsx'

                ]

            )

        ) {

            $format = 'csv';

        }

        /*
        |--------------------------------------------------------------------------
        | Output File
        |--------------------------------------------------------------------------
        */

        $outputFile =

            $exportJob['file_path'];

        $directory =

            dirname(

                $outputFile

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
        | Create Writer
        |--------------------------------------------------------------------------
        */

        if (

            $format

            ===

            'xlsx'

        ) {

            $writer =

                new XLSXWriter(

                    $outputFile

                );

        } else {

            $writer =

                new CSVWriter(

                    $outputFile

                );

        }

        /*
        |--------------------------------------------------------------------------
        | Adapter Query
        |--------------------------------------------------------------------------
        */

        if (

            !isset(

                $config['query']

            )

        ) {

            throw new Exception(

                "Adapter query missing."

            );

        }

        $query =

            $config['query'];

        if (

            is_callable(

                $query

            )

        ) {

            $query =

                call_user_func(

                    $query,

                    $conn,

                    $exportJob

                );

        }

        if (

            empty(

                $query

            )

        ) {

            throw new Exception(

                "Export query is empty."

            );

        }

        /*
        |--------------------------------------------------------------------------
        | Continue In Part 2
        |--------------------------------------------------------------------------
        */

                /*
        |--------------------------------------------------------------------------
        | Total Rows
        |--------------------------------------------------------------------------
        */

        if (

            isset(

                $config['count_query']

            )

        ) {

            $countQuery =

                $config['count_query'];

            if (

                is_callable(

                    $countQuery

                )

            ) {

                $countQuery =

                    call_user_func(

                        $countQuery,

                        $conn,

                        $exportJob

                    );

            }

        } else {

            $countQuery =

                "SELECT COUNT(*) AS total FROM ({$query}) x";

        }

        $countResult = mysqli_query(

            $conn,

            $countQuery

        );

        if (

            !$countResult

        ) {

            throw new Exception(

                mysqli_error(

                    $conn

                )

            );

        }

        $countRow =

            mysqli_fetch_assoc(

                $countResult

            );

        $totalRows =

            (int)

            (

                $countRow['total']

                ??

                0

            );

        /*
        |--------------------------------------------------------------------------
        | Update Job
        |--------------------------------------------------------------------------
        */

        mysqli_query(

            $conn,

            "

            UPDATE export_jobs

            SET

                total_rows={$totalRows},

                processed_rows=0,

                progress=0,

                updated_at=NOW()

            WHERE id={$jobId}

            LIMIT 1

            "

        );

        /*
        |--------------------------------------------------------------------------
        | Redis Progress
        |--------------------------------------------------------------------------
        */

        $cacheKey =

            "export:job:{$jobId}";

        cache_set(

            $cacheKey,

            [

                'status' => 'processing',

                'progress' => 0,

                'processed' => 0,

                'total' => $totalRows,

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

        $processed = 0;

        $started = microtime(

            true

        );

        $headerWritten = false;

        log_message(

            "Rows : {$totalRows}"

        );

        /*
        |--------------------------------------------------------------------------
        | Execute Streaming Query
        |--------------------------------------------------------------------------
        */

        $result = mysqli_query(

            $conn,

            $query,

            MYSQLI_USE_RESULT

        );

        if (

            !$result

        ) {

            throw new Exception(

                mysqli_error(

                    $conn

                )

            );

        }

        /*
        |--------------------------------------------------------------------------
        | Stream Export
        |--------------------------------------------------------------------------
        */

        while (

            $row =

            mysqli_fetch_assoc(

                $result

            )

        ) {

            /*
            ----------------------------------------------------------
            | Header
            ----------------------------------------------------------
            */

            if (

                !$headerWritten

            ) {

                $columns =

                    array_keys(

                        $row

                    );

                $writer->header(

                    $columns

                );

                $headerWritten = true;

            }

            /*
            ----------------------------------------------------------
            | Adapter Transform
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

                        $row,

                        $exportJob

                    );

            }

            /*
            ----------------------------------------------------------
            | Write Row
            ----------------------------------------------------------
            */

            $writer->row(

                array_values(

                    $row

                )

            );

            $processed++;

            /*
            ----------------------------------------------------------
            | Continue Progress Update
            ----------------------------------------------------------
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
            | Update Database Every 100 Rows
            |--------------------------------------------------------------------------
            */

            if (

                $processed % 100 == 0

                ||

                $processed == $totalRows

            ) {

                $progressSql = "

                    UPDATE export_jobs

                    SET

                        processed_rows={$processed},

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

                        'status'        => 'processing',

                        'progress'      => $progress,

                        'processed'     => $processed,

                        'total'         => $totalRows,

                        'speed'         => $speed,

                        'eta'           => $eta,

                        'memory'        => memory_get_usage(true),

                        'peak_memory'   => memory_get_peak_usage(true),

                        'updated_at'    => time()

                    ]

                );

                /*
                |--------------------------------------------------------------------------
                | Logging
                |--------------------------------------------------------------------------
                */

                log_message(

                    sprintf(

                        "[Job:%d] %.2f%% | %d/%d | Speed:%s rows/sec",

                        $jobId,

                        $progress,

                        $processed,

                        $totalRows,

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

                if (

                    function_exists(

                        'gc_collect_cycles'

                    )

                ) {

                    gc_collect_cycles();

                }

                if (

                    memory_get_usage(true)

                    >

                    256 * 1024 * 1024

                ) {

                    log_message(

                        "High memory usage detected : "

                        .

                        format_bytes(

                            memory_get_usage(true)

                        )

                    );

                }

            }

        }

        /*
        |--------------------------------------------------------------------------
        | Free Streaming Result
        |--------------------------------------------------------------------------
        */

        mysqli_free_result(

            $result

        );

        /*
        |--------------------------------------------------------------------------
        | Close Writer
        |--------------------------------------------------------------------------
        */

        $writer->close();

        log_message(

            "Export completed for Job #{$jobId}"

        );

        /*
        |--------------------------------------------------------------------------
        | Continue In Part 4
        |--------------------------------------------------------------------------
        */

                /*
        |--------------------------------------------------------------------------
        | Job Completed Successfully
        |--------------------------------------------------------------------------
        */

        mark_completed(

            $conn,

            'export_jobs',

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

            UPDATE export_jobs

            SET

                processed_rows={$processed},

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

                'status'        => 'completed',

                'progress'      => 100,

                'processed'     => $processed,

                'total'         => $totalRows,

                'speed'         => $speed,

                'eta'           => 0,

                'memory'        => memory_get_peak_usage(true),

                'file'          => $writer->path(),

                'size'          => $writer->size(),

                'human_size'    => $writer->humanSize(),

                'completed_at'  => time()

            ]

        );

        /*
        |--------------------------------------------------------------------------
        | Save File Path
        |--------------------------------------------------------------------------
        */

        mysqli_query(

            $conn,

            "

            UPDATE export_jobs

            SET

                file_path='" . mysqli_real_escape_string(

                    $conn,

                    $writer->path()

                ) . "',

                file_size=" . (int)$writer->size() . ",

                updated_at=NOW()

            WHERE id={$jobId}

            LIMIT 1

            "

        );

        /*
        |--------------------------------------------------------------------------
        | Export Summary
        |--------------------------------------------------------------------------
        */

        log_message(

            "======================================"

        );

        log_message(

            "Export Job {$jobId} Completed"

        );

        log_message(

            "Rows Exported : {$processed}"

        );

        log_message(

            "Output File : "

            .

            $writer->path()

        );

        log_message(

            "File Size : "

            .

            $writer->humanSize()

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

            "Export Job Failed : "

            .

            $e->getMessage()

        );

        if (

            isset(

                $jobId

            )

        ) {

            retry_job(

                $conn,

                'export_jobs',

                $jobId,

                $e->getMessage()

            );

            if (

                can_retry(

                    $conn,

                    'export_jobs',

                    $jobId

                )

            ) {

                reset_job(

                    $conn,

                    'export_jobs',

                    $jobId

                );

                queue_export_job(

                    [

                        'job_id' => $jobId

                    ]

                );

                cache_set(

                    "export:job:{$jobId}",

                    [

                        'status'        => 'retrying',

                        'retrying'      => true,

                        'message'       => $e->getMessage(),

                        'updated_at'    => time()

                    ]

                );

                log_message(

                    "Retry queued for Job {$jobId}"

                );

            }

            else {

                mark_failed(

                    $conn,

                    'export_jobs',

                    $jobId,

                    $e->getMessage()

                );

                cache_set(

                    "export:job:{$jobId}",

                    [

                        'status'        => 'failed',

                        'message'       => $e->getMessage(),

                        'processed'     =>

                            $processed ?? 0,

                        'updated_at'    => time()

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