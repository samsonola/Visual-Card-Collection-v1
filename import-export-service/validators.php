<?php

/*
|--------------------------------------------------------------------------
| IMPORT / EXPORT VALIDATOR
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/helpers.php';

/*
|--------------------------------------------------------------------------
| Allowed Extensions
|--------------------------------------------------------------------------
*/

function allowed_import_extensions()
{
    return [

        'csv',

        'xlsx'

    ];
}

/*
|--------------------------------------------------------------------------
| Maximum Upload Size (MB)
|--------------------------------------------------------------------------
*/

function max_import_size_mb()
{
    return 100;
}

/*
|--------------------------------------------------------------------------
| Validate Uploaded File
|--------------------------------------------------------------------------
*/

function validate_upload_file(array $file)
{
    if (!isset($file['error'])) {

        throw new Exception(

            'Invalid upload.'

        );

    }

    if (

        $file['error'] !==

        UPLOAD_ERR_OK

    ) {

        throw new Exception(

            'Upload failed.'

        );

    }

    if (

        !is_uploaded_file(

            $file['tmp_name']

        )

    ) {

        throw new Exception(

            'Invalid upload source.'

        );

    }

    $extension = detect_file_type(

        $file['name']

    );

    if (

        !in_array(

            $extension,

            allowed_import_extensions()

        )

    ) {

        throw new Exception(

            'Only CSV and XLSX files are supported.'

        );

    }

    $maxBytes =

        max_import_size_mb()

        *

        1024

        *

        1024;

    if (

        $file['size'] >

        $maxBytes

    ) {

        throw new Exception(

            'File exceeds maximum size.'

        );

    }

    return true;
}

/*
|--------------------------------------------------------------------------
| Validate CSV Headers
|--------------------------------------------------------------------------
*/

function validate_headers(

    array $headers,

    array $required

)
{
    $headers = array_map(

        'trim',

        $headers

    );

    foreach (

        $required as

        $column

    ) {

        if (

            !in_array(

                $column,

                $headers

            )

        ) {

            throw new Exception(

                "Missing required column: {$column}"

            );

        }

    }

    return true;
}

/*
|--------------------------------------------------------------------------
| Check Duplicate Headers
|--------------------------------------------------------------------------
*/

function validate_duplicate_headers(

    array $headers

)
{
    $duplicates = array_diff_assoc(

        $headers,

        array_unique($headers)

    );

    if (

        !empty($duplicates)

    ) {

        throw new Exception(

            'Duplicate column names detected.'

        );

    }

    return true;
}

/*
|--------------------------------------------------------------------------
| Remove Empty Rows
|--------------------------------------------------------------------------
*/

function row_is_empty(array $row)
{
    foreach (

        $row as

        $value

    ) {

        if (

            trim(

                (string)$value

            ) !== ''

        ) {

            return false;

        }

    }

    return true;
}

/*
|--------------------------------------------------------------------------
| UTF-8 Validation
|--------------------------------------------------------------------------
*/

function validate_utf8(array $row)
{
    foreach (

        $row as

        $value

    ) {

        if (

            !mb_check_encoding(

                (string)$value,

                'UTF-8'

            )

        ) {

            throw new Exception(

                'Invalid UTF-8 encoding.'

            );

        }

    }

    return true;
}

/*
|--------------------------------------------------------------------------
| Validate Required Fields
|--------------------------------------------------------------------------
*/

function validate_required_fields(

    array $row,

    array $required

)
{
    foreach (

        $required as

        $column

    ) {

        if (

            !isset(

                $row[$column]

            )

        ) {

            throw new Exception(

                "{$column} missing."

            );

        }

        if (

            trim(

                (string)

                $row[$column]

            ) === ''

        ) {

            throw new Exception(

                "{$column} cannot be empty."

            );

        }

    }

    return true;
}

/*
|--------------------------------------------------------------------------
| Validate Row Count
|--------------------------------------------------------------------------
*/

function validate_row_limit(

    $rows,

    $limit = 1000000

)
{
    if (

        $rows >

        $limit

    ) {

        throw new Exception(

            'Maximum row limit exceeded.'

        );

    }

    return true;
}