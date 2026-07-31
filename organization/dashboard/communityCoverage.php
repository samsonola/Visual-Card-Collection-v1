<?php

declare(strict_types=1);

require_once __DIR__ . '/../../core/bootstrap.php';

/*
|--------------------------------------------------------------------------
| AUTHENTICATION
|--------------------------------------------------------------------------
*/

$token = get_bearer_token();

if (!$token) {

    json_response([
        'status' => false,
        'message' => 'Unauthorized'
    ], 401);

}

$decoded = jwt_decode($token);

if (!$decoded) {

    json_response([
        'status' => false,
        'message' => 'Invalid token'
    ], 401);

}

if (($decoded->role ?? '') !== 'organisation') {

    json_response([
        'status' => false,
        'message' => 'Access denied.'
    ], 403);

}

$orgId = $decoded->org_id ?? null;

if (!$orgId) {

    json_response([
        'status' => false,
        'message' => 'Organisation not found.'
    ], 401);

}

$conn = db();

/*
|--------------------------------------------------------------------------
| CACHE
|--------------------------------------------------------------------------
*/

$cacheKey = "organisation:{$orgId}:dashboard:communityCoverage";

/*
|--------------------------------------------------------------------------
| DATA
|--------------------------------------------------------------------------
*/

$data = cacheRemember($cacheKey, function () use ($conn, $orgId) {

    /*
    |--------------------------------------------------------------------------
    | Total Communities
    |--------------------------------------------------------------------------
    */

    $stmt = mysqli_prepare(
        $conn,
        "
        SELECT COUNT(DISTINCT community) total
        FROM community_user
        WHERE org_id=?
        "
    );

    mysqli_stmt_bind_param($stmt, "s", $orgId);

    mysqli_stmt_execute($stmt);

    $totalCommunities = (int) mysqli_fetch_assoc(
        mysqli_stmt_get_result($stmt)
    )['total'];

    /*
    |--------------------------------------------------------------------------
    | Covered Communities
    |--------------------------------------------------------------------------
    */

    $stmt = mysqli_prepare(
        $conn,
        "
        SELECT COUNT(DISTINCT cu.community) total
        FROM community_user cu
        INNER JOIN tbl_collection tc

            ON tc.household_id = cu.household_id
            AND tc.org_id = cu.org_id

        WHERE cu.org_id = ?
        "
    );

    mysqli_stmt_bind_param($stmt, "s", $orgId);

    mysqli_stmt_execute($stmt);

    $coveredCommunities = (int) mysqli_fetch_assoc(
        mysqli_stmt_get_result($stmt)
    )['total'];

    /*
    |--------------------------------------------------------------------------
    | Coverage Rate
    |--------------------------------------------------------------------------
    */

    $coverageRate = $totalCommunities > 0
        ? round(($coveredCommunities / $totalCommunities) * 100)
        : 0;

    return [

        'communities_covered' => [

            'total' => $coveredCommunities,

            'description' => 'Across all assigned areas'

        ],

        'distribution_rate' => $coverageRate . '%',

        'total_communities' => $totalCommunities

    ];

}, $_ENV['DASHBOARD_CACHE_TTL']);

/*
|--------------------------------------------------------------------------
| RESPONSE
|--------------------------------------------------------------------------
*/

json_response([

    'status' => true,

    'cached' => false,

    'data' => $data

]);