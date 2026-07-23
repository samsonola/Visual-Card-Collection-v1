<?php

/*
|--------------------------------------------------------------------------
| DOWNLOAD EXPORT
|--------------------------------------------------------------------------
|
| Securely downloads completed export files.
|
*/

require_once __DIR__ . '/../core/bootstrap.php';

require_once __DIR__ . '/helpers.php';

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

    exit(

        'Invalid Job ID.'

    );

}

/*
|--------------------------------------------------------------------------
| Load Export Job
|--------------------------------------------------------------------------
*/

$sql = "

    SELECT

        *

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

    exit(

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

    http_response_code(

        404

    );

    exit(

        'Export job not found.'

    );

}

$job =

    mysqli_fetch_assoc(

        $result

    );

/*
|--------------------------------------------------------------------------
| Export Status
|--------------------------------------------------------------------------
*/

if (

    $job['status']

    !=

    'completed'

) {

    http_response_code(

        409

    );

    exit(

        'Export not yet completed.'

    );

}

/*
|--------------------------------------------------------------------------
| File Exists?
|--------------------------------------------------------------------------
*/

$file =

    $job['file_path'];

if (

    empty(

        $file

    )

) {

    http_response_code(

        404

    );

    exit(

        'Export file missing.'

    );

}

if (

    !file_exists(

        $file

    )

) {

    http_response_code(

        404

    );

    exit(

        'Export file not found.'

    );

}

/*
|--------------------------------------------------------------------------
| Determine Filename
|--------------------------------------------------------------------------
*/

$filename =

    basename(

        $file

    );

$extension =

    strtolower(

        pathinfo(

            $filename,

            PATHINFO_EXTENSION

        )

    );

/*
|--------------------------------------------------------------------------
| Content Type
|--------------------------------------------------------------------------
*/

$contentType =

    'application/octet-stream';

switch (

    $extension

) {

    case 'csv':

        $contentType =

            'text/csv';

        break;

    case 'xlsx':

        $contentType =

            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        break;

}

/*
|--------------------------------------------------------------------------
| Download Headers
|--------------------------------------------------------------------------
*/

header(

    'Content-Description: File Transfer'

);

header(

    'Content-Type: '

    .

    $contentType

);

header(

    'Content-Disposition: attachment; filename="'

    .

    $filename

    .

    '"'

);

header(

    'Content-Transfer-Encoding: binary'

);

header(

    'Expires: 0'

);

header(

    'Cache-Control: must-revalidate'

);

header(

    'Pragma: public'

);

header(

    'Content-Length: '

    .

    filesize(

        $file

    )

);

while (

    ob_get_level()

) {

    ob_end_clean();

}

/*
|--------------------------------------------------------------------------
| Stream File
|--------------------------------------------------------------------------
*/

$handle = fopen(

    $file,

    'rb'

);

if (

    !$handle

) {

    http_response_code(

        500

    );

    exit(

        'Unable to open export file.'

    );

}

while (

    !feof(

        $handle

    )

) {

    echo fread(

        $handle,

        8192

    );

    flush();

}

fclose(

    $handle

);

exit;