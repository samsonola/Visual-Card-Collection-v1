<?php

/**
 * ---------------------------------------------------------
 * Media Service
 * ---------------------------------------------------------
 * Saves uploaded files locally, creates a media record,
 * and pushes a Redis queue job.
 * ---------------------------------------------------------
 */

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/queue.php';



/**
 * Queue an uploaded image.
 *
 * @param string $entityTable
 * @param string $entityPkColumn
 * @param mixed  $entityPk
 * @param string $updateColumn
 * @param string $folder
 * @param array  $file
 *
 * @return int Media ID
 *
 * Example:
 *
 * queue_media_upload(
 *      'supervisors',
 *      'id',
 *      $supervisorId,
 *      'profile_photo',
 *      'supervisors',
 *      $_FILES['profile_photo']
 * );
 *
 */
function queue_media_upload(
    $entityTable,
    $entityPkColumn,
    $entityPk,
    $updateColumn,
    $folder,
    array $file
) {

    global $conn;

    if (!$conn) {
        $conn = db();
    }

    /*
    |--------------------------------------------------------------------------
    | Validate upload
    |--------------------------------------------------------------------------
    */

    if (!isset($file['error'])) {

        throw new Exception('Invalid upload.');

    }

    if ($file['error'] != UPLOAD_ERR_OK) {

        throw new Exception('Upload failed.');

    }

    /*
    |--------------------------------------------------------------------------
    | Validate image
    |--------------------------------------------------------------------------
    */

    $allowed = [

        'image/jpeg',

        'image/png',

        'image/webp',

        'image/gif'

    ];

    $mime = mime_content_type($file['tmp_name']);

    if (!in_array($mime, $allowed)) {

        throw new Exception(
            'Only image uploads are allowed.'
        );

    }

    /*
    |--------------------------------------------------------------------------
    | Store temporarily
    |--------------------------------------------------------------------------
    */

    $stored = media_store_temp($file);

    /*
    |--------------------------------------------------------------------------
    | Database transaction
    |--------------------------------------------------------------------------
    */

    mysqli_begin_transaction($conn);

    try {

        $entityTable = mysqli_real_escape_string(
            $conn,
            $entityTable
        );

        $entityPkColumn = mysqli_real_escape_string(
            $conn,
            $entityPkColumn
        );

        $updateColumn = mysqli_real_escape_string(
            $conn,
            $updateColumn
        );

        $folder = mysqli_real_escape_string(
            $conn,
            $folder
        );

        $entityPk = mysqli_real_escape_string(
            $conn,
            $entityPk
        );

        $originalName = mysqli_real_escape_string(
            $conn,
            $stored['original_name']
        );

        $generatedName = mysqli_real_escape_string(
            $conn,
            $stored['generated_name']
        );

        $mimeType = mysqli_real_escape_string(
            $conn,
            $stored['mime_type']
        );

        $fileSize = (int)$stored['file_size'];

        $localPath = mysqli_real_escape_string(
            $conn,
            $stored['local_path']
        );

        $maxRetries = (int)$_ENV['MEDIA_MAX_RETRIES'];

        $sql = "

            INSERT INTO media (

                entity_table,

                entity_pk,

                entity_pk_column,

                update_column,

                folder,

                original_name,

                file_name,

                mime_type,

                file_size,

                local_path,

                status,

                retry_count,

                max_retries

            )

            VALUES (

                '$entityTable',

                '$entityPk',

                '$entityPkColumn',

                '$updateColumn',

                '$folder',

                '$originalName',

                '$generatedName',

                '$mimeType',

                '$fileSize',

                '$localPath',

                'pending',

                0,

                $maxRetries

            )

        ";

        if (!mysqli_query($conn, $sql)) {

            throw new Exception(
                mysqli_error($conn)
            );

        }

        $mediaId = mysqli_insert_id($conn);

        /*
        |--------------------------------------------------------------------------
        | Queue Job
        |--------------------------------------------------------------------------
        */

        queue_push(

            $_ENV['MEDIA_QUEUE'],

            [

                'media_id' => $mediaId

            ]

        );

        mysqli_commit($conn);

        media_log(

            "Media #{$mediaId} queued."

        );

        return $mediaId;

    }

    catch (Exception $e) {

        mysqli_rollback($conn);

        media_delete_temp(
            $stored['local_path']
        );

        media_log(

            'MEDIA ERROR : ' .

            $e->getMessage()

        );

        throw $e;

    }

}