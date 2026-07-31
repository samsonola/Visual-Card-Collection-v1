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

if (($decoded->role ?? '') !== 'supervisor') {

    json_response([
        'status'  => false,
        'message' => 'Access denied.'
    ], 403);

}

$supervisorId = (int)($decoded->id ?? 0);

$orgId = $decoded->org_id ?? null;

if ($supervisorId <= 0 || !$orgId) {

    json_response([
        'status'  => false,
        'message' => 'Supervisor not found.'
    ], 401);

}

$conn = db();

/*
|--------------------------------------------------------------------------
| CACHE
|--------------------------------------------------------------------------
*/

$cacheKey = sprintf(
    'supervisor:%d:dashboard:cardStats',
    $supervisorId
);

/*
|--------------------------------------------------------------------------
| DASHBOARD
|--------------------------------------------------------------------------
*/

$data = cacheRemember($cacheKey, function () use (

    $conn,
    $supervisorId,
    $orgId

) {

    /*
    |--------------------------------------------------------------------------
    | BENEFICIARY SUMMARY
    |--------------------------------------------------------------------------
    */

    $sql = "

    SELECT

        COUNT(DISTINCT cua.community_user_id) AS total,

        COUNT(
            DISTINCT CASE
                WHEN EXISTS(
                    SELECT 1
                    FROM tbl_collection tc
                    WHERE tc.household_id = cu.household_id
                    AND tc.org_id = cu.org_id
                )
                THEN cua.community_user_id
            END
        ) AS distributed,

        COUNT(
            DISTINCT CASE
                WHEN NOT EXISTS(
                    SELECT 1
                    FROM tbl_collection tc
                    WHERE tc.household_id = cu.household_id
                    AND tc.org_id = cu.org_id
                )
                THEN cua.community_user_id
            END
        ) AS pending

    FROM community_user_assignment cua

    INNER JOIN community_user cu

        ON cu.id = cua.community_user_id

    WHERE

        cua.assignee_id = ?
        AND cua.assignee_type = 'supervisor'
        AND cua.org_id = ?
        AND cua.status = 'active'
        AND cua.unassigned_at IS NULL

    ";

    $stmt = mysqli_prepare($conn, $sql);

    mysqli_stmt_bind_param(

        $stmt,
        "is",
        $supervisorId,
        $orgId

    );

    mysqli_stmt_execute($stmt);

    $summary = mysqli_fetch_assoc(
        mysqli_stmt_get_result($stmt)
    );

    $beneficiaries = (int)$summary['total'];

    $distributed = (int)$summary['distributed'];

    $pending = (int)$summary['pending'];

    /*
    |--------------------------------------------------------------------------
    | DISTRIBUTED TODAY
    |--------------------------------------------------------------------------
    */

    $sql = "

    SELECT

        COUNT(DISTINCT tc.household_id) AS total

    FROM tbl_collection tc

    INNER JOIN community_user cu

        ON cu.household_id = tc.household_id
        AND cu.org_id = tc.org_id

    INNER JOIN community_user_assignment cua

        ON cua.community_user_id = cu.id

    WHERE

        cua.assignee_id = ?
        AND cua.assignee_type = 'supervisor'
        AND cua.status = 'active'
        AND cua.unassigned_at IS NULL
        AND DATE(tc.date_created) = CURDATE()

    ";

    $stmt = mysqli_prepare($conn, $sql);

    mysqli_stmt_bind_param(
        $stmt,
        "i",
        $supervisorId
    );

    mysqli_stmt_execute($stmt);

    $distributedToday = (int)mysqli_fetch_assoc(
        mysqli_stmt_get_result($stmt)
    )['total'];

    /*
    |--------------------------------------------------------------------------
    | COVERAGE AREAS
    |--------------------------------------------------------------------------
    */

    $sql = "

    SELECT

        COUNT(DISTINCT cu.lga) AS total

    FROM community_user_assignment cua

    INNER JOIN community_user cu

        ON cu.id = cua.community_user_id

    WHERE

        cua.assignee_id = ?
        AND cua.assignee_type = 'supervisor'
        AND cua.status = 'active'
        AND cua.unassigned_at IS NULL

    ";

    $stmt = mysqli_prepare($conn, $sql);

    mysqli_stmt_bind_param(
        $stmt,
        "i",
        $supervisorId
    );

    mysqli_stmt_execute($stmt);

    $coverageAreas = (int)mysqli_fetch_assoc(
        mysqli_stmt_get_result($stmt)
    )['total'];

    /*
    |--------------------------------------------------------------------------
    | LGA DISTRIBUTION
    |--------------------------------------------------------------------------
    */

    $sql = "

    SELECT

        cu.lga,

        COUNT(
            DISTINCT CASE
                WHEN EXISTS(
                    SELECT 1
                    FROM tbl_collection tc
                    WHERE tc.household_id = cu.household_id
                    AND tc.org_id = cu.org_id
                )
                THEN cu.household_id
            END
        ) AS distributed,

        COUNT(
            DISTINCT CASE
                WHEN NOT EXISTS(
                    SELECT 1
                    FROM tbl_collection tc
                    WHERE tc.household_id = cu.household_id
                    AND tc.org_id = cu.org_id
                )
                THEN cu.household_id
            END
        ) AS pending

    FROM community_user_assignment cua

    INNER JOIN community_user cu

        ON cu.id = cua.community_user_id

    WHERE

        cua.assignee_id = ?
        AND cua.assignee_type = 'supervisor'
        AND cua.status = 'active'
        AND cua.unassigned_at IS NULL

    GROUP BY cu.lga

    ORDER BY cu.lga ASC

    ";

    $stmt = mysqli_prepare($conn, $sql);

    mysqli_stmt_bind_param(
        $stmt,
        "i",
        $supervisorId
    );

    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    $lgaDistribution = [];

    while ($row = mysqli_fetch_assoc($result)) {

        $lgaDistribution[] = [

            'lga' => $row['lga'],

            'distributed' => (int)$row['distributed'],

            'pending' => (int)$row['pending']

        ];

    }

        /*
    |--------------------------------------------------------------------------
    | RECENT DISTRIBUTIONS
    |--------------------------------------------------------------------------
    */

    $sql = "

    SELECT

        cu.caregiver_name,
        cu.household_id,
        tc.date_created

    FROM tbl_collection tc

    INNER JOIN community_user cu

        ON cu.household_id = tc.household_id
        AND cu.org_id = tc.org_id

    INNER JOIN community_user_assignment cua

        ON cua.community_user_id = cu.id

    WHERE

        cua.assignee_id = ?
        AND cua.assignee_type = 'supervisor'
        AND cua.status = 'active'
        AND cua.unassigned_at IS NULL

    ORDER BY tc.date_created DESC

    LIMIT 10

    ";

    $stmt = mysqli_prepare($conn, $sql);

    mysqli_stmt_bind_param(
        $stmt,
        "i",
        $supervisorId
    );

    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    $recentDistributions = [];

    while ($row = mysqli_fetch_assoc($result)) {

        $parts = preg_split(
            '/\s+/',
            trim($row['caregiver_name'])
        );

        $initials = '';

        foreach ($parts as $part) {

            if ($part !== '') {

                $initials .= strtoupper(
                    substr($part, 0, 1)
                );

            }

            if (strlen($initials) >= 2) {

                break;

            }

        }

        $recentDistributions[] = [

            'initials'     => $initials,

            'name'         => $row['caregiver_name'],

            'household_id' => $row['household_id'],

            'date'         => $row['date_created'],

            'status'       => 'Paid'

        ];

    }

    /*
    |--------------------------------------------------------------------------
    | RETURN
    |--------------------------------------------------------------------------
    */

    return [

        'beneficiaries' => [

            'total' => $beneficiaries,

            'subtitle' => 'Assigned to you'

        ],

        'distributed' => [

            'total' => $distributed,

            'today' => $distributedToday

        ],

        'pending' => [

            'total' => $pending,

            'subtitle' => 'Awaiting action'

        ],

        'coverage' => [

            'total' => $coverageAreas,

            'subtitle' => 'Active zones'

        ],

        'lga_distribution' => $lgaDistribution,

        'recent_distributions' => $recentDistributions

    ];

}, (int) $_ENV['DASHBOARD_CACHE_TTL']);

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