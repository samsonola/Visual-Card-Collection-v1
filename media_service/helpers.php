<?php

/**
 * ---------------------------------------------------------
 * Media Service Helpers
 * ---------------------------------------------------------
 * Generic helper functions used throughout the media service.
 * ---------------------------------------------------------
 */

if (!defined('MEDIA_SERVICE_LOADED')) {
    define('MEDIA_SERVICE_LOADED', true);
}

/**
 * Generate a UUID v4.
 *
 * @return string
 */
function media_uuid()
{
    $data = random_bytes(16);

    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);

    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

    return vsprintf(
        '%s%s-%s-%s-%s-%s%s%s',
        str_split(bin2hex($data), 4)
    );
}

/**
 * Get file extension.
 *
 * @param string $filename
 * @return string
 */
function media_extension($filename)
{
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

/**
 * Build S3 object key.
 *
 * Example:
 * supervisors/2026/07/04/uuid.jpg
 *
 * @param string $folder
 * @param string $extension
 * @return string
 */
function media_s3_key($folder, $extension)
{
    return sprintf(

        '%s/%s/%s/%s/%s.%s',

        trim($folder, '/'),

        date('Y'),

        date('m'),

        date('d'),

        media_uuid(),

        strtolower($extension)

    );
}

/**
 * Ensure directory exists.
 *
 * @param string $directory
 * @return void
 */
function media_make_directory($directory)
{
    if (!is_dir($directory)) {

        mkdir($directory, 0777, true);

    }
}

/**
 * Move uploaded file into temp storage.
 *
 * @param array $file
 * @return array
 */
function media_store_temp(array $file)
{
    $tempRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR .
            trim($_ENV['MEDIA_TEMP_PATH'], '/\\');

    media_make_directory($tempRoot);

    $extension = media_extension($file['name']);

    $generated = media_uuid() . '.' . $extension;

    $destination = $tempRoot . DIRECTORY_SEPARATOR . $generated;

    if (!move_uploaded_file(
        $file['tmp_name'],
        $destination
    )) {

        throw new Exception(
            'Unable to store uploaded file.'
        );

    }

    return [

        'original_name' => $file['name'],

        'generated_name' => $generated,

        'extension' => $extension,

        'mime_type' => mime_content_type($destination),

        'file_size' => filesize($destination),

        'local_path' => $destination

    ];
}

/**
 * Delete local temp file.
 *
 * @param string $path
 * @return void
 */
function media_delete_temp($path)
{
    if (

        $path &&
        file_exists($path)

    ) {

        unlink($path);

    }
}

/**
 * Write media log.
 *
 * @param string $message
 * @return void
 */
function media_log($message)
{
    $dir = rtrim(
        $_ENV['MEDIA_LOG_PATH'],
        '/\\'
    );

    media_make_directory($dir);

    $file = $dir .

        DIRECTORY_SEPARATOR .

        'media-' .

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