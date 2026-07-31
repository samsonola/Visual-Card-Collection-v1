<?php

use PHPUnit\Framework\TestCase;

require __DIR__ . '/../core/bootstrap.php';

class ApiSmokeTest extends TestCase
{
    /**
     * Local development server
     */
    private string $baseUrl;

    /**
     * Folders to scan recursively
     */
    private array $scanFolders = [
        'admin',
        'agent',
        'organization',
        'supervisor'
    ];

    /**
     * Ignore these filenames everywhere
     */
    private array $ignoreFiles = [
        'bootstrap.php',
        'config.php',
        'helper.php',
        'helpers.php',
        'functions.php',
        'index.php'
    ];

    /**
     * Ignore specific endpoints (relative to project root)
     */
    private array $ignoreEndpoints = [
        // 'admin/debug/test.php',
        // 'admin/reports/export.php',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->baseUrl = rtrim($_ENV['TEST_BASE_URL'], '/');
    }

    public function testAllEndpoints(): void
    {
        $root = realpath(dirname(__DIR__));

        $failed = [];
        $tested = [];

        foreach ($this->scanFolders as $folder) {

            $folderPath = $root . DIRECTORY_SEPARATOR . $folder;

            if (!is_dir($folderPath)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $folderPath,
                    RecursiveDirectoryIterator::SKIP_DOTS
                )
            );

            foreach ($iterator as $file) {

                if (!$file->isFile()) {
                    continue;
                }

                if ($file->getExtension() !== 'php') {
                    continue;
                }

                if (in_array($file->getFilename(), $this->ignoreFiles, true)) {
                    continue;
                }

                $relativePath = str_replace(
                    '\\',
                    '/',
                    ltrim(
                        substr($file->getPathname(), strlen($root)),
                        DIRECTORY_SEPARATOR
                    )
                );

                if (in_array($relativePath, $this->ignoreEndpoints, true)) {
                    continue;
                }

                $status = $this->callEndpoint('/' . $relativePath);

                $tested[] = sprintf(
                    "%-60s %s",
                    $relativePath,
                    $status
                );

                // Allow only these responses
                if (!in_array($status, [200, 400, 401], true)) {
                    $failed[] = sprintf(
                        "%s (%d)",
                        $relativePath,
                        $status
                    );
                }
            }
        }

        $message = PHP_EOL .
            "===================================" . PHP_EOL .
            "Endpoints Tested : " . count($tested) . PHP_EOL .
            "Unexpected Errors: " . count($failed) . PHP_EOL .
            "===================================";

        if (!empty($failed)) {
            $message .= PHP_EOL . PHP_EOL .
                "Unexpected status codes:" . PHP_EOL .
                implode(PHP_EOL, $failed);
        }

        $this->assertEmpty($failed, $message);
    }

    private function callEndpoint(string $endpoint): int
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $this->baseUrl . $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CUSTOMREQUEST => 'GET',
        ]);

        curl_exec($ch);

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        return $status;
    }
}



// use PHPUnit\Framework\TestCase;

// require __DIR__ . '/../core/bootstrap.php';
// class ApiSmokeTest extends TestCase
// {
//     /**
//      * Local development server
//      */
//     private string $baseUrl;

//     public function __construct(?string $name = null)
//     {
//         parent::__construct($name);

//         $this->baseUrl = $_ENV['TEST_BASE_URL'];
//     }
//     /**
//      * Folders to scan recursively
//      */
//     private array $scanFolders = [
//         'admin',
//         'agent',
//         'organization',
//         'supervisor'
//     ];

//     /**
//      * Ignore these filenames everywhere
//      */
//     private array $ignoreFiles = [
//         'bootstrap.php',
//         'config.php',
//         'helper.php',
//         'helpers.php',
//         'functions.php',
//         'index.php'
//     ];

//     /**
//      * Ignore specific endpoints (relative to project root)
//      */
//     private array $ignoreEndpoints = [
//         // 'admin/debug/test.php',
//         // 'admin/reports/export.php',
//         // 'workers/cron.php'
//     ];

//     public function testAllEndpoints(): void
//     {
//         $root = realpath(dirname(__DIR__));

//         $failed = [];
//         $tested = 0;

//         foreach ($this->scanFolders as $folder) {

//             $folderPath = $root . DIRECTORY_SEPARATOR . $folder;

//             if (!is_dir($folderPath)) {
//                 continue;
//             }

//             $iterator = new RecursiveIteratorIterator(
//                 new RecursiveDirectoryIterator(
//                     $folderPath,
//                     RecursiveDirectoryIterator::SKIP_DOTS
//                 )
//             );

//             foreach ($iterator as $file) {

//                 if (!$file->isFile()) {
//                     continue;
//                 }

//                 if ($file->getExtension() !== 'php') {
//                     continue;
//                 }

//                 if (in_array($file->getFilename(), $this->ignoreFiles, true)) {
//                     continue;
//                 }

//                 $relativePath = str_replace(
//                     '\\',
//                     '/',
//                     ltrim(substr($file->getPathname(), strlen($root)), DIRECTORY_SEPARATOR)
//                 );

//                 if (in_array($relativePath, $this->ignoreEndpoints, true)) {
//                     continue;
//                 }

//                 $tested++;

//                 $status = $this->callEndpoint('/' . $relativePath);

//                 echo sprintf(
//                     "\n%-60s %s",
//                     $relativePath,
//                     $status
//                 );

//                 if ($status >= 500) {
//                     $failed[] = "{$relativePath} ({$status})";
//                 }
//             }
//         }

//         echo PHP_EOL;
//         echo PHP_EOL . "===================================";
//         echo PHP_EOL . "Endpoints Tested : {$tested}";
//         echo PHP_EOL . "5xx Errors       : " . count($failed);
//         echo PHP_EOL . "===================================";
//         echo PHP_EOL;

//         $this->assertEmpty(
//             $failed,
//             "The following endpoints returned server errors:\n\n" .
//             implode("\n", $failed)
//         );
//     }

//     private function callEndpoint(string $endpoint): int
//     {
//         $ch = curl_init();

//         curl_setopt_array($ch, [
//             CURLOPT_URL => $this->baseUrl . $endpoint,
//             CURLOPT_RETURNTRANSFER => true,
//             CURLOPT_TIMEOUT => 20,
//             CURLOPT_FOLLOWLOCATION => true,
//             CURLOPT_CUSTOMREQUEST => 'GET'
//         ]);

//         curl_exec($ch);

//         $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

//         curl_close($ch);

//         return $status;
//     }
// }