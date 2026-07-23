<?php

/**
 * ---------------------------------------------------------
 * AWS S3 Upload Service
 * ---------------------------------------------------------
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/helpers.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

/**
 * Singleton S3 Client
 *
 * @return S3Client
 */
function s3_client()
{
    static $client = null;

    if ($client instanceof S3Client) {
        return $client;
    }

    $client = new S3Client([

        'version' => 'latest',

        'region' => $_ENV['AWS_REGION'],

        'credentials' => [

            'key' => $_ENV['AWS_KEY'],

            'secret' => $_ENV['AWS_SECRET']

        ]

    ]);

    return $client;
}

/**
 * Upload a file to S3
 *
 * @param string $localPath
 * @param string $folder
 *
 * @return array
 *
 * [
 *    success => true,
 *    url => '',
 *    key => ''
 * ]
 */
function s3_upload($localPath, $folder)
{
    try {

        if (!file_exists($localPath)) {

            throw new Exception(
                'Local file does not exist.'
            );

        }

        $extension = pathinfo(
            $localPath,
            PATHINFO_EXTENSION
        );

        $bucketFolder = trim(
            $_ENV['AWS_FOLDER'],
            '/'
        );

        $key = '';

        if (!empty($bucketFolder)) {

            $key .= $bucketFolder . '/';

        }

        $key .= media_s3_key(
            $folder,
            $extension
        );

        $result = s3_client()->putObject([

            'Bucket' => $_ENV['AWS_BUCKET'],

            'Key' => $key,

            'SourceFile' => $localPath,


            'ContentType' => mime_content_type(
                $localPath
            )

        ]);

        media_log(

            "Uploaded to S3 : {$key}"

        );

        return [

            'success' => true,

            'url' => $result['ObjectURL'],

            'key' => $key

        ];

    }

    catch (AwsException $e) {

        media_log(

            'AWS ERROR : ' .

            $e->getAwsErrorMessage()

        );

        return [

            'success' => false,

            'error' => $e->getAwsErrorMessage()

        ];

    }

    catch (Exception $e) {

        media_log(

            'UPLOAD ERROR : ' .

            $e->getMessage()

        );

        return [

            'success' => false,

            'error' => $e->getMessage()

        ];

    }

}

/**
 * Delete file from S3
 *
 * @param string $key
 *
 * @return bool
 */
function s3_delete($key)
{
    try {

        s3_client()->deleteObject([

            'Bucket' => $_ENV['AWS_BUCKET'],

            'Key' => $key

        ]);

        media_log(

            "Deleted S3 object : {$key}"

        );

        return true;

    }

    catch (Exception $e) {

        media_log(

            'DELETE ERROR : ' .

            $e->getMessage()

        );

        return false;

    }

}

/**
 * Check if object exists.
 *
 * @param string $key
 *
 * @return bool
 */
function s3_exists($key)
{
    try {

        return s3_client()->doesObjectExist(

            $_ENV['AWS_BUCKET'],

            $key

        );

    }

    catch (Exception $e) {

        return false;

    }

}