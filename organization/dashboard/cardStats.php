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
        'status'  => false,
        'message' => 'Unauthorized'
    ], 401);

}

$decoded = jwt_decode($token);

if (!$decoded) {

    json_response([
        'status'  => false,
        'message' => 'Invalid token'
    ], 401);

}

if (($decoded->role ?? '') !== 'organisation') {

    json_response([
        'status'  => false,
        'message' => 'Access denied.'
    ], 403);

}

$orgId = $decoded->org_id ?? null;

if (!$orgId) {

    json_response([
        'status'  => false,
        'message' => 'Organisation not found.'
    ], 401);

}

$conn = db();

/*
|--------------------------------------------------------------------------
| CACHE
|--------------------------------------------------------------------------
*/

$cacheKey = "organisation:{$orgId}:dashboard:cardStats";

/*
|--------------------------------------------------------------------------
| DASHBOARD
|--------------------------------------------------------------------------
*/

$data = cacheRemember($cacheKey, function () use ($conn, $orgId) {

    /*
    |--------------------------------------------------------------------------
    | Beneficiaries
    |--------------------------------------------------------------------------
    */

    $stmt = mysqli_prepare(
        $conn,
        "SELECT COUNT(*) total
         FROM community_user
         WHERE org_id=?"
    );

    mysqli_stmt_bind_param($stmt, "s", $orgId);
    mysqli_stmt_execute($stmt);

    $beneficiaries = (int) mysqli_fetch_assoc(
        mysqli_stmt_get_result($stmt)
    )['total'];

    /*
    |--------------------------------------------------------------------------
    | Beneficiaries This Week
    |--------------------------------------------------------------------------
    */

    $stmt = mysqli_prepare(
        $conn,
        "SELECT COUNT(*) total
         FROM community_user
         WHERE org_id=?
         AND date_created >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
    );

    mysqli_stmt_bind_param($stmt, "s", $orgId);
    mysqli_stmt_execute($stmt);

    $beneficiariesThisWeek = (int) mysqli_fetch_assoc(
        mysqli_stmt_get_result($stmt)
    )['total'];

    /*
    |--------------------------------------------------------------------------
    | Distributed (Source of Truth)
    |--------------------------------------------------------------------------
    */

    $stmt = mysqli_prepare(
        $conn,
        "SELECT COUNT(DISTINCT household_id) total
         FROM tbl_collection
         WHERE org_id=?"
    );

    mysqli_stmt_bind_param($stmt, "s", $orgId);
    mysqli_stmt_execute($stmt);

    $distributed = (int) mysqli_fetch_assoc(
        mysqli_stmt_get_result($stmt)
    )['total'];

    /*
    |--------------------------------------------------------------------------
    | Pending
    |--------------------------------------------------------------------------
    */

    $stmt = mysqli_prepare(
        $conn,
        "
        SELECT COUNT(*) total
        FROM community_user cu
        WHERE cu.org_id=?
        AND NOT EXISTS (
            SELECT 1
            FROM tbl_collection tc
            WHERE tc.org_id = cu.org_id
            AND tc.household_id = cu.household_id
        )
        "
    );

    mysqli_stmt_bind_param($stmt, "s", $orgId);
    mysqli_stmt_execute($stmt);

    $pending = (int) mysqli_fetch_assoc(
        mysqli_stmt_get_result($stmt)
    )['total'];

    /*
    |--------------------------------------------------------------------------
    | Distribution Rate
    |--------------------------------------------------------------------------
    */

    $coverage = $beneficiaries > 0
        ? round(($distributed / $beneficiaries) * 100)
        : 0;

    /*
    |--------------------------------------------------------------------------
    | Supervisors
    |--------------------------------------------------------------------------
    */

    $stmt = mysqli_prepare(
        $conn,
        "
        SELECT COUNT(*) total
        FROM supervisor
        WHERE org_id=?
        "
    );

    mysqli_stmt_bind_param($stmt, "s", $orgId);
    mysqli_stmt_execute($stmt);

    $supervisors = (int) mysqli_fetch_assoc(
        mysqli_stmt_get_result($stmt)
    )['total'];

    /*
    |--------------------------------------------------------------------------
    | Projects
    |--------------------------------------------------------------------------
    */

    $stmt = mysqli_prepare(
        $conn,
        "
        SELECT COUNT(*) total
        FROM organisations_project
        WHERE org_id=?
        "
    );

    mysqli_stmt_bind_param($stmt, "s", $orgId);
    mysqli_stmt_execute($stmt);

    $projects = (int) mysqli_fetch_assoc(
        mysqli_stmt_get_result($stmt)
    )['total'];

    return [

        'projects' => [
            'total' => $projects
        ],

        'beneficiaries' => [

            'total' => $beneficiaries,

            'this_week' => $beneficiariesThisWeek

        ],

        'distributed' => [

            'total' => $distributed,

            'coverage' => $coverage . '%'

        ],

        'pending' => [

            'total' => $pending,

            'message' => 'Needs attention'

        ],

        'supervisors' => [

            'total' => $supervisors

        ]

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