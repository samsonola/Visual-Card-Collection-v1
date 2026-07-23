<?php

/**
 * ---------------------------------------------------------
 * Redis Queue Helper
 * ---------------------------------------------------------
 */

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/helpers.php';

/**
 * Get queue name from .env
 */
function queue_name($queue = null)
{
    if ($queue) {
        return $queue;
    }

    return $_ENV['MEDIA_QUEUE'];
}

/**
 * Push a job immediately.
 *
 * @param string $queue
 * @param array $payload
 * @return bool
 */
function queue_push($queue, array $payload)
{
    global $redis;

    if (!$redis instanceof Redis) {
        throw new Exception('Redis connection unavailable.');
    }

    $payload['queued_at'] = date('Y-m-d H:i:s');

    return $redis->lPush(
        queue_name($queue),
        json_encode($payload)
    );
}

/**
 * Wait forever until a job arrives.
 *
 * @param string $queue
 * @return array|null
 */
function queue_pop($queue)
{
    global $redis;

    if (!$redis instanceof Redis) {
        throw new Exception('Redis connection unavailable.');
    }

    $job = $redis->brPop(
        queue_name($queue),
        0
    );

    if (!$job) {
        return null;
    }

    return json_decode($job[1], true);
}

/**
 * Push job back immediately.
 *
 * @param string $queue
 * @param array $payload
 * @return bool
 */
function queue_release($queue, array $payload)
{
    global $redis;

    $payload['released_at'] = date('Y-m-d H:i:s');

    return $redis->rPush(
        queue_name($queue),
        json_encode($payload)
    );
}

/**
 * Push a delayed job.
 *
 * The worker does NOT read this queue directly.
 * retry.php will later move jobs from this queue
 * back into the live queue.
 *
 * @param string $queue
 * @param array $payload
 * @param int $delaySeconds
 * @return bool
 */
function queue_delay(
    $queue,
    array $payload,
    $delaySeconds
)
{
    global $redis;

    $payload['available_at'] =
        time() + $delaySeconds;

    return $redis->zAdd(
        queue_name($queue) . ':delayed',
        $payload['available_at'],
        json_encode($payload)
    );
}

/**
 * Get delayed jobs that are ready.
 *
 * Used by retry.php.
 */
function queue_due_jobs($queue)
{
    global $redis;

    return $redis->zRangeByScore(

        queue_name($queue) . ':delayed',

        0,

        time()

    );
}

/**
 * Remove delayed job.
 */
function queue_remove_delayed(
    $queue,
    $job
)
{
    global $redis;

    return $redis->zRem(
        queue_name($queue) . ':delayed',
        $job
    );
}

/**
 * Queue size.
 */
function queue_size($queue)
{
    global $redis;

    return $redis->lLen(
        queue_name($queue)
    );
}

/**
 * Delayed queue size.
 */
function queue_delayed_size($queue)
{
    global $redis;

    return $redis->zCard(
        queue_name($queue) . ':delayed'
    );
}

/**
 * Failed queue.
 *
 * We keep failed jobs forever
 * until someone reviews them.
 */
function queue_failed(array $payload)
{
    global $redis;

    $payload['failed_at'] = date(
        'Y-m-d H:i:s'
    );

    return $redis->rPush(
        'failed_jobs',
        json_encode($payload)
    );
}

/**
 * Clear queue.
 *
 * Mostly for development.
 */
function queue_clear($queue)
{
    global $redis;

    return $redis->del(
        queue_name($queue)
    );
}

/**
 * Peek queue.
 *
 * Useful for debugging.
 */
function queue_peek(
    $queue,
    $count = 20
)
{
    global $redis;

    return $redis->lRange(
        queue_name($queue),
        0,
        $count - 1
    );
}