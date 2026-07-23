<?php

declare(strict_types=1);

require __DIR__ . '/../core/bootstrap.php';

/*
|--------------------------------------------------------------------------
| AUTH
|--------------------------------------------------------------------------
*/

$token = get_bearer_token();

if (!$token) {
    json_response([
        'success' => false,
        'message' => 'Unauthorized'
    ], 401);
}

$decoded = jwt_decode($token);

if (!$decoded) {
    json_response([
        'success' => false,
        'message' => 'Invalid token'
    ], 401);
}

/*
|--------------------------------------------------------------------------
| CONFIG
|--------------------------------------------------------------------------
*/

$storagePath = realpath(__DIR__ . '/../storage');

if (!$storagePath) {
    json_response([
        'success' => false,
        'message' => 'Storage directory not found.'
    ], 500);
}

/*
|--------------------------------------------------------------------------
| PAGINATION
|--------------------------------------------------------------------------
*/

$cursor = isset($_GET['cursor'])
    ? max(0, (int)$_GET['cursor'])
    : 0;

$limit = isset($_GET['limit'])
    ? (int)$_GET['limit']
    : 50;

if ($limit < 1) {
    $limit = 50;
}

if ($limit > 200) {
    $limit = 200;
}

/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/

$search = trim($_GET['search'] ?? '');
$action = strtoupper(trim($_GET['action'] ?? ''));

/*
|--------------------------------------------------------------------------
| CACHE
|--------------------------------------------------------------------------
*/

$useCache = ($cursor === 0);

$cacheKey = 'audit_log:' . md5(json_encode([
    'search' => $search,
    'action' => $action,
    'limit'  => $limit
]));

if ($useCache) {

    $cached = cacheGet($cacheKey);

    if (!empty($cached)) {

        $cached['cached'] = true;

        json_response($cached);

    }
}

/*
|--------------------------------------------------------------------------
| LOCATE LOG FILES
|--------------------------------------------------------------------------
*/

$files = [];

/*
|--------------------------------------------------------------------------
| Root auth logs
|--------------------------------------------------------------------------
*/

foreach (glob($storagePath . DIRECTORY_SEPARATOR . 'logsauth-*.log') as $file) {

    if (is_file($file)) {
        $files[] = $file;
    }

}

/*
|--------------------------------------------------------------------------
| storage/logs/*
|--------------------------------------------------------------------------
*/

$logDir = $storagePath . DIRECTORY_SEPARATOR . 'logs';

if (is_dir($logDir)) {

    foreach (glob($logDir . DIRECTORY_SEPARATOR . '*.log') as $file) {

        if (is_file($file)) {
            $files[] = $file;
        }

    }

}

/*
|--------------------------------------------------------------------------
| Sort newest first
|--------------------------------------------------------------------------
*/

usort($files, function ($a, $b) {

    return filemtime($b) <=> filemtime($a);

});

/*
|--------------------------------------------------------------------------
| Parse
|--------------------------------------------------------------------------
*/

$rows = [];

foreach ($files as $file) {

    $handle = fopen($file, 'r');

    if (!$handle) {
        continue;
    }

    while (($line = fgets($handle)) !== false) {

        $line = trim($line);

        if ($line === '') {
            continue;
        }

        /*
        |--------------------------------------------------------------------------
        | Laravel / Monolog Format
        |
        | [2026-07-03 18:14:42] auth.INFO: Message...
        |--------------------------------------------------------------------------
        */

        if (!preg_match(
            '/^\[(.*?)\]\s+([^.]+)\.(INFO|ERROR|WARNING|DEBUG|NOTICE|CRITICAL):\s+(.*)$/i',
            $line,
            $matches
        )) {
            continue;
        }

        $timestamp = trim($matches[1]);
        $channel   = trim($matches[2]);
        $level     = strtoupper(trim($matches[3]));
        $message   = trim($matches[4]);

        /*
        |--------------------------------------------------------------------------
        | Action Detection
        |--------------------------------------------------------------------------
        */

        $detectedAction = $level;

        if (stripos($message, 'login') !== false) {
            $detectedAction = 'LOGIN';
        } elseif (stripos($message, 'logout') !== false) {
            $detectedAction = 'LOGOUT';
        } elseif (stripos($message, 'refresh') !== false) {
            $detectedAction = 'REFRESH';
        } elseif (stripos($message, 'upload') !== false) {
            $detectedAction = 'UPLOAD';
        } elseif (stripos($message, 'delete') !== false) {
            $detectedAction = 'DELETE';
        } elseif (stripos($message, 'create') !== false) {
            $detectedAction = 'CREATE';
        } elseif (stripos($message, 'update') !== false) {
            $detectedAction = 'UPDATE';
        }

        /*
        |--------------------------------------------------------------------------
        | Filter by action
        |--------------------------------------------------------------------------
        */

        if ($action !== '' && $detectedAction !== $action) {
            continue;
        }

        /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        */

        if ($search !== '') {

            $haystack = strtolower(
                $message . ' ' .
                $channel . ' ' .
                $detectedAction
            );

            if (strpos($haystack, strtolower($search)) === false) {
                continue;
            }

        }

        $rows[] = [

            'timestamp'    => $timestamp,
            'actor'        => 'System',
            'role'         => '-',
            'action'       => $detectedAction,
            'entity'       => ucfirst($channel),
            'organisation' => '-',
            'details'      => $message

        ];

    }

    fclose($handle);

}

/*
|--------------------------------------------------------------------------
| Sort newest first
|--------------------------------------------------------------------------
*/

usort($rows, function ($a, $b) {

    return strtotime($b['timestamp']) <=> strtotime($a['timestamp']);

});

/*
|--------------------------------------------------------------------------
| Cursor Pagination
|--------------------------------------------------------------------------
|
| Cursor here is simply the array offset after sorting.
|
*/

$total = count($rows);

$page = array_slice($rows, $cursor, $limit + 1);

$hasMore = count($page) > $limit;

if ($hasMore) {
    array_pop($page);
}

$nextCursor = null;

if ($hasMore) {
    $nextCursor = $cursor + count($page);
}

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

$response = [

    'success' => true,

    'cached' => false,

    'data' => $page,

    'pagination' => [

        'limit'       => $limit,

        'next_cursor' => $nextCursor,

        'has_more'    => $hasMore,

        'returned'    => count($page),

        'total'       => $total

    ]

];

/*
|--------------------------------------------------------------------------
| Cache first page only
|--------------------------------------------------------------------------
*/

if ($useCache) {

    cachePut(
        $cacheKey,
        $response,
        60
    );

}

/*
|--------------------------------------------------------------------------
| Output
|--------------------------------------------------------------------------
*/

json_response($response);