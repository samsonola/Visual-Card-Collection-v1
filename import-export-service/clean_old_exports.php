<?php

/*
|--------------------------------------------------------------------------
| CLEANUP OLD EXPORTS
|--------------------------------------------------------------------------
|
| Deletes expired export files and optionally removes old
| completed/failed/cancelled export jobs.
|
| Intended to be executed by Cron.
|
| Example:
|
| php cleanup_old_exports.php
|
*/

require_once __DIR__ . '/../core/bootstrap.php';

require_once __DIR__ . '/helpers.php';

$conn = db();

/*
|--------------------------------------------------------------------------
| Configuration
|--------------------------------------------------------------------------
*/

$retentionDays =

    (int)

    (

        $_ENV['EXPORT_RETENTION_DAYS']

        ??

        30

    );

$deleteDatabaseRecords =

    filter_var(

        $_ENV['EXPORT_DELETE_JOB_RECORDS']

        ??

        true,

        FILTER_VALIDATE_BOOLEAN

    );

$cutoffDate =

    date(

        'Y-m-d H:i:s',

        strtotime(

            "-{$retentionDays} days"

        )

    );

log_message(

    "======================================"

);

log_message(

    "Export Cleanup Started"

);

log_message(

    "Retention : {$retentionDays} days"

);

log_message(

    "======================================"

);

/*
|--------------------------------------------------------------------------
| Load Old Jobs
|--------------------------------------------------------------------------
*/

$sql = "

SELECT

    *

FROM export_jobs

WHERE

    status IN (

        'completed',

        'failed',

        'cancelled'

    )

AND

    updated_at < '{$cutoffDate}'

ORDER BY id ASC

";

$result = mysqli_query(

    $conn,

    $sql

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

$totalJobs = 0;

$deletedFiles = 0;

$deletedRecords = 0;

$failedDeletes = 0;

/*
|--------------------------------------------------------------------------
| Process Jobs
|--------------------------------------------------------------------------
*/

while (

    $job =

    mysqli_fetch_assoc(

        $result

    )

) {

    $totalJobs++;

    $jobId =

        (int)

        $job['id'];

    $file =

        $job['file_path'];

    /*
    |--------------------------------------------------------------------------
    | Delete Export File
    |--------------------------------------------------------------------------
    */

    if (

        !empty(

            $file

        )

        &&

        file_exists(

            $file

        )

    ) {

        if (

            @unlink(

                $file

            )

        ) {

            $deletedFiles++;

            log_message(

                "Deleted : {$file}"

            );

        }

        else {

            $failedDeletes++;

            log_message(

                "Unable to delete : {$file}"

            );

        }

    }

    /*
    |--------------------------------------------------------------------------
    | Delete Redis Cache
    |--------------------------------------------------------------------------
    */

    cache_delete(

        "export:job:{$jobId}"

    );

    /*
    |--------------------------------------------------------------------------
    | Delete Database Record
    |--------------------------------------------------------------------------
    */

    if (

        $deleteDatabaseRecords

    ) {

        mysqli_query(

            $conn,

            "

            DELETE

            FROM export_jobs

            WHERE id={$jobId}

            LIMIT 1

            "

        );

        if (

            mysqli_affected_rows(

                $conn

            )

        ) {

            $deletedRecords++;

        }

    }

}

/*
|--------------------------------------------------------------------------
| Cleanup Empty Directories
|--------------------------------------------------------------------------
*/

$exportDirectory =

    $_ENV['EXPORT_PATH']

    ??

    null;

if (

    $exportDirectory

    &&

    is_dir(

        $exportDirectory

    )

) {

    $iterator =

        new RecursiveIteratorIterator(

            new RecursiveDirectoryIterator(

                $exportDirectory,

                RecursiveDirectoryIterator::SKIP_DOTS

            ),

            RecursiveIteratorIterator::CHILD_FIRST

        );

    foreach (

        $iterator as

        $item

    ) {

        if (

            $item->isDir()

        ) {

            $files =

                scandir(

                    $item->getPathname()

                );

            if (

                count(

                    $files

                )

                ==

                2

            ) {

                @rmdir(

                    $item->getPathname()

                );

            }

        }

    }

}

/*
|--------------------------------------------------------------------------
| Summary
|--------------------------------------------------------------------------
*/

log_message(

    "======================================"

);

log_message(

    "Cleanup Completed"

);

log_message(

    "Jobs Scanned : {$totalJobs}"

);

log_message(

    "Files Deleted : {$deletedFiles}"

);

log_message(

    "Records Deleted : {$deletedRecords}"

);

log_message(

    "Failed Deletes : {$failedDeletes}"

);

log_message(

    "======================================"

);