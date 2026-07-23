<?php

/**
 * Redis Connection
 *
 * This file establishes a single Redis connection that is reused
 * throughout the request.
 *
 * If Redis is unavailable, $redis will be null and the cache helper
 * will automatically fall back to the database.
 */

$redis = null;

if (!class_exists('Redis')) {
    return;
}

try {

    $redis = new Redis();

    $connected = $redis->pconnect(
        $_ENV['REDIS_HOST'],
        (int) $_ENV['REDIS_PORT'],
        2.0 // Connection timeout (seconds)
    );

    if (!$connected) {
        $redis = null;
        return;
    }

    // Authenticate (if required)
    if (!empty($_ENV['REDIS_PASSWORD'])) {
        $redis->auth($_ENV['REDIS_PASSWORD']);
    }

    // Select database
    if (isset($_ENV['REDIS_DB']) && $_ENV['REDIS_DB'] !== '') {
        $redis->select((int) $_ENV['REDIS_DB']);
    }

    // Prefix every key automatically
    if (!empty($_ENV['CACHE_PREFIX'])) {
        $redis->setOption(
            Redis::OPT_PREFIX,
            rtrim($_ENV['CACHE_PREFIX'], ':') . ':'
        );
    }

    // Read timeout
    $redis->setOption(
        Redis::OPT_READ_TIMEOUT,
        2
    );

} catch (Throwable $e) {

    // Never break the API because Redis is unavailable
    $redis = null;

    // Optional: log the error if you have a logger
     log_error('redis', 'Error thrown', ['error' => $e]);
    /*
    if (function_exists('writeLog')) {
        writeLog('Redis Connection Error: ' . $e->getMessage());
        
    }
    */
}


