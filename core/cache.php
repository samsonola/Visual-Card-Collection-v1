<?php

/**
 * Determine whether caching is enabled.
 */

function cacheEnabled(): bool
{
    global $redis;

    return
        filter_var($_ENV['CACHE_ENABLED'] ?? false, FILTER_VALIDATE_BOOLEAN)
        && extension_loaded('redis')
        && $redis instanceof Redis;
}

/**
 * Default TTL
 */
function cacheDefaultTTL(): int
{
    return isset($_ENV['CACHE_DEFAULT_TTL'])
        ? (int) $_ENV['CACHE_DEFAULT_TTL']
        : 60;
}

/**
 * Validate key
 */
function validateCacheKey(string $key): void
{
    if (trim($key) === '') {
        throw new InvalidArgumentException('Cache key cannot be empty.');
    }
}

/**
 * Normalize any value to ARRAY (THIS IS THE FIX)
 */
function cacheNormalize($value)
{
    // If Redis gives stdClass or object → force array
    if (is_object($value)) {
        $value = json_decode(json_encode($value), true);
    }

    return $value;
}

/**
 * GET
 */
function cacheGet(string $key)
{
    global $redis;

    if (!cacheEnabled()) {
        return null;
    }

    validateCacheKey($key);

    try {

        $value = $redis->get($key);

        if ($value === false || $value === null || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        // If it wasn't JSON, return the raw value
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $value;
        }

        return cacheNormalize($decoded);

    } catch (Throwable $e) {
        return null;
    }
}

/**
 * PUT
 */
function cachePut(string $key, $value, ?int $ttl = null): bool
{
    global $redis;

    if (!cacheEnabled()) {
        return false;
    }

    validateCacheKey($key);

    $ttl ??= cacheDefaultTTL();

    try {

        if (is_object($value)) {
            $value = json_decode(json_encode($value), true);
        }

        $payload = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($payload === false) {
            return false;
        }

        return $redis->setex($key, $ttl, $payload);

    } catch (Throwable $e) {
        return false;
    }
}

/**
 * FORGET
 */
function cacheForget(string $key): bool
{
    global $redis;

    if (!cacheEnabled()) {
        return false;
    }

    validateCacheKey($key);

    try {
        return (bool) $redis->del($key);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * REMEMBER
 */
function cacheRemember(string $key, callable $callback, ?int $ttl = null)
{
    // If cache isn't available, don't even try.
    if (!cacheEnabled()) {
        return $callback();
    }

    try {
        $cached = cacheGet($key);

        if ($cached !== null) {
            return $cached;
        }

        $value = $callback();

        if (is_object($value)) {
            $value = json_decode(json_encode($value), true);
        }

        cachePut($key, $value, $ttl);

        return $value;

    } catch (Throwable $e) {
        // Never let cache failures affect the API.
        return $callback();
    }
}
/**
 * FLUSH
 */
function cacheFlush(): bool
{
    global $redis;

    if (!cacheEnabled()) {
        return false;
    }

    try {
        return $redis->flushDB();
    } catch (Throwable $e) {
        return false;
    }
}