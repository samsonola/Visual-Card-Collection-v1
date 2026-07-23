<?php

/*
|--------------------------------------------------------------------------
| IMPORT / EXPORT HELPERS
|--------------------------------------------------------------------------
*/

if (!defined('IMPORT_EXPORT_HELPERS')) {

    define('IMPORT_EXPORT_HELPERS', true);

}

/*
|--------------------------------------------------------------------------
| Generate UUID v4
|--------------------------------------------------------------------------
*/

function generate_job_uuid()
{
    $data = random_bytes(16);

    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);

    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

    return vsprintf(

        '%s%s-%s-%s-%s-%s%s%s',

        str_split(bin2hex($data), 4)

    );
}

/*
|--------------------------------------------------------------------------
| Logger
|--------------------------------------------------------------------------
*/

function import_export_log($message)
{
    $logDir = dirname(__DIR__) .

        DIRECTORY_SEPARATOR .

        'storage' .

        DIRECTORY_SEPARATOR .

        'logs' .

        DIRECTORY_SEPARATOR .

        'imports_exports';

    if (!is_dir($logDir)) {

        mkdir(

            $logDir,

            0777,

            true

        );

    }

    $file = $logDir .

        DIRECTORY_SEPARATOR .

        date('Y-m-d') .

        '.log';

    file_put_contents(

        $file,

        '[' .

        date('Y-m-d H:i:s') .

        '] ' .

        $message .

        PHP_EOL,

        FILE_APPEND

    );
}

/*
|--------------------------------------------------------------------------
| Ensure Directory Exists
|--------------------------------------------------------------------------
*/

function ensure_directory($path)
{
    if (!is_dir($path)) {

        mkdir(

            $path,

            0777,

            true

        );

    }

    return $path;
}

/*
|--------------------------------------------------------------------------
| Job Path
|--------------------------------------------------------------------------
*/

function job_path($folder)
{
    $path = dirname(__DIR__) .

        DIRECTORY_SEPARATOR .

        'storage' .

        DIRECTORY_SEPARATOR .

        trim(

            $folder,

            '/\\'

        );

    ensure_directory($path);

    return $path;
}

/*
|--------------------------------------------------------------------------
| Detect File Extension
|--------------------------------------------------------------------------
*/

function detect_file_type($filename)
{
    return strtolower(

        pathinfo(

            $filename,

            PATHINFO_EXTENSION

        )

    );
}

/*
|--------------------------------------------------------------------------
| Sanitize Filename
|--------------------------------------------------------------------------
*/

function sanitize_filename($filename)
{
    $filename = preg_replace(

        '/[^A-Za-z0-9_\-.]/',

        '_',

        $filename

    );

    return trim($filename);
}

/*
|--------------------------------------------------------------------------
| Build Export Filename
|--------------------------------------------------------------------------
*/

function build_export_filename(

    $table,

    $extension

)
{
    return

        $table .

        '_' .

        date('Ymd_His') .

        '_' .

        substr(

            md5(

                uniqid()

            ),

            0,

            8

        ) .

        '.' .

        $extension;
}

/*
|--------------------------------------------------------------------------
| Human File Size
|--------------------------------------------------------------------------
*/

function format_bytes($bytes)
{
    $units = [

        'B',

        'KB',

        'MB',

        'GB',

        'TB'

    ];

    $i = 0;

    while (

        $bytes >= 1024 &&

        $i < count($units) - 1

    ) {

        $bytes /= 1024;

        $i++;

    }

    return round(

        $bytes,

        2

    ) .

    ' ' .

    $units[$i];
}

/*
|--------------------------------------------------------------------------
| Update Job Progress
|--------------------------------------------------------------------------
*/

function update_job_progress(

    mysqli $conn,

    $table,

    $jobId,

    array $data

)
{
    $updates = [];

    foreach (

        $data as

        $column => $value

    ) {

        if (

            is_numeric($value)

        ) {

            $updates[] =

                "{$column}={$value}";

        } else {

            $value = mysqli_real_escape_string(

                $conn,

                $value

            );

            $updates[] =

                "{$column}='{$value}'";

        }

    }

    $sql = "

        UPDATE {$table}

        SET

        " .

        implode(

            ',',

            $updates

        ) .

        "

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
| Mark Job Failed
|--------------------------------------------------------------------------
*/

function mark_job_failed(

    mysqli $conn,

    $table,

    $jobId,

    $error

)
{
    update_job_progress(

        $conn,

        $table,

        $jobId,

        [

            'status' => 'failed',

            'last_error' => $error,

            'completed_at' => date(

                'Y-m-d H:i:s'

            )

        ]

    );
}

/*
|--------------------------------------------------------------------------
| Mark Job Completed
|--------------------------------------------------------------------------
*/

function mark_job_completed(

    mysqli $conn,

    $table,

    $jobId

)
{
    update_job_progress(

        $conn,

        $table,

        $jobId,

        [

            'status' => 'completed',

            'completed_at' => date(

                'Y-m-d H:i:s'

            )

        ]

    );
}

/*
|--------------------------------------------------------------------------
| Estimate Remaining Time
|--------------------------------------------------------------------------
*/

function estimate_remaining_time(

    $processed,

    $total,

    $elapsedSeconds

)
{
    if (

        $processed <= 0 ||

        $elapsedSeconds <= 0

    ) {

        return null;

    }

    $rate =

        $processed /

        $elapsedSeconds;

    if ($rate <= 0) {

        return null;

    }

    return ceil(

        ($total - $processed)

        /

        $rate

    );
}

/*
|--------------------------------------------------------------------------
| Normalize Header
|--------------------------------------------------------------------------
*/

function normalize_header(

    array $header

)
{
    return array_map(

        function ($column) {

            return strtolower(

                trim($column)

            );

        },

        $header

    );
}

/*
|--------------------------------------------------------------------------
| Validate Unknown Columns
|--------------------------------------------------------------------------
|
| Prevent users from importing unexpected columns.
|
*/

/*
|--------------------------------------------------------------------------
| Validate Required Columns
|--------------------------------------------------------------------------
|
| Ensures all required columns defined by the adapter
| exist in the uploaded CSV/XLSX header.
|
*/

function validate_required_columns(
    array $header,
    array $required
)
{
    /*
    --------------------------------------------------
    Normalize
    --------------------------------------------------
    */

    $header = array_map(
        function ($column) {
            return strtolower(trim($column));
        },
        $header
    );

    $required = array_map(
        function ($column) {
            return strtolower(trim($column));
        },
        $required
    );

    /*
    --------------------------------------------------
    Find Missing Columns
    --------------------------------------------------
    */

    $missing = [];

    foreach ($required as $column) {

        if (!in_array($column, $header, true)) {

            $missing[] = $column;

        }

    }

    /*
    --------------------------------------------------
    Throw Exception
    --------------------------------------------------
    */

    if (!empty($missing)) {

        throw new Exception(
            'Missing required columns: ' .
            implode(', ', $missing)
        );

    }

    return true;
}

function progress_percentage(
    $processed,
    $total
)
{
    if ($total <= 0) {

        return 0;

    }

    return round(

        ($processed / $total) * 100,

        2

    );
}


function mark_failed(
    mysqli $conn,
    $table,
    $jobId,
    $error
)
{
    mark_job_failed(

        $conn,

        $table,

        $jobId,

        $error

    );
}

function mark_completed(
    mysqli $conn,
    $table,
    $jobId
)
{
    mark_job_completed(

        $conn,

        $table,

        $jobId

    );
}

function log_message($message, $level = 'INFO')
{
    import_export_log(
        "[{$level}] {$message}"
    );
}

function cache_set($key, array $value, $ttl = 86400)
{
    $redis = redis();

    $redis->set(
        $key,
        json_encode($value)
    );

    if ($ttl > 0) {

        $redis->expire(
            $key,
            $ttl
        );

    }

    return true;
}