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

/*
|--------------------------------------------------------------------------
| AUTHORISATION
|--------------------------------------------------------------------------
*/

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
| PAGINATION
|--------------------------------------------------------------------------
*/

$page = max(1, (int)($_GET['page'] ?? 1));

$limit = max(1, min(100, (int)($_GET['limit'] ?? 20)));

$offset = ($page - 1) * $limit;

/*
|--------------------------------------------------------------------------
| SEARCH
|--------------------------------------------------------------------------
*/

$search = trim($_GET['search'] ?? '');

/*
|--------------------------------------------------------------------------
| CACHE
|--------------------------------------------------------------------------
*/

$cacheKey = sprintf(
    'organisation:%s:supervisors:%d:%d:%s',
    $orgId,
    $page,
    $limit,
    md5($search)
);

/*
|--------------------------------------------------------------------------
| DATA
|--------------------------------------------------------------------------
*/

$data = cacheRemember($cacheKey, function () use (
    $conn,
    $orgId,
    $page,
    $limit,
    $offset,
    $search
) {

    /*
    |--------------------------------------------------------------------------
    | SUMMARY - TOTAL SUPERVISORS
    |--------------------------------------------------------------------------
    */

    $stmt = mysqli_prepare(
        $conn,
        "
        SELECT COUNT(*) AS total
        FROM supervisor
        WHERE org_id=?
        "
    );

    mysqli_stmt_bind_param($stmt, "s", $orgId);

    mysqli_stmt_execute($stmt);

    $totalSupervisors = (int) mysqli_fetch_assoc(
        mysqli_stmt_get_result($stmt)
    )['total'];

    /*
    |--------------------------------------------------------------------------
    | SUMMARY - ACTIVE SUPERVISORS
    |--------------------------------------------------------------------------
    */

    $stmt = mysqli_prepare(
        $conn,
        "
        SELECT COUNT(DISTINCT supervisor_id) AS total
        FROM supervisor_pro_assignment
        WHERE org_id=?
        AND status='active'
        AND unassigned_at IS NULL
        "
    );

    mysqli_stmt_bind_param($stmt, "s", $orgId);

    mysqli_stmt_execute($stmt);

    $activeSupervisors = (int) mysqli_fetch_assoc(
        mysqli_stmt_get_result($stmt)
    )['total'];

    /*
    |--------------------------------------------------------------------------
    | SUMMARY - COMMUNITIES COVERED
    |--------------------------------------------------------------------------
    */

    $stmt = mysqli_prepare(
        $conn,
        "
        SELECT COUNT(DISTINCT cu.community) AS total

        FROM community_user_assignment cua

        INNER JOIN community_user cu

            ON cu.id = cua.community_user_id

        WHERE cua.org_id=?
        AND cua.assignee_type='supervisor'
        AND cua.status='active'
        AND cua.unassigned_at IS NULL
        "
    );

    mysqli_stmt_bind_param($stmt, "s", $orgId);

    mysqli_stmt_execute($stmt);

    $communitiesCovered = (int) mysqli_fetch_assoc(
        mysqli_stmt_get_result($stmt)
    )['total'];

    /*
    |--------------------------------------------------------------------------
    | SUMMARY - LGAS COVERED
    |--------------------------------------------------------------------------
    */

    $stmt = mysqli_prepare(
        $conn,
        "
        SELECT COUNT(DISTINCT cu.lga) AS total

        FROM community_user_assignment cua

        INNER JOIN community_user cu

            ON cu.id = cua.community_user_id

        WHERE cua.org_id=?
        AND cua.assignee_type='supervisor'
        AND cua.status='active'
        AND cua.unassigned_at IS NULL
        "
    );

    mysqli_stmt_bind_param($stmt, "s", $orgId);

    mysqli_stmt_execute($stmt);

    $lgasCovered = (int) mysqli_fetch_assoc(
        mysqli_stmt_get_result($stmt)
    )['total'];

    /*
    |--------------------------------------------------------------------------
    | FILTERED TOTAL
    |--------------------------------------------------------------------------
    | Count UNIQUE supervisors only
    |--------------------------------------------------------------------------
    */

    if ($search !== '') {

        $like = "%{$search}%";

        $sql = "

        SELECT COUNT(DISTINCT s.id) AS total

        FROM supervisor s

        LEFT JOIN supervisor_pro_assignment spa

            ON spa.supervisor_id=s.id
            AND spa.status='active'
            AND spa.unassigned_at IS NULL

        LEFT JOIN organisations_project op

            ON op.pro_id=spa.pro_id
            AND op.org_id=spa.org_id

        WHERE

            s.org_id=?

        AND

        (

            CONCAT(s.firstName,' ',s.lastName) LIKE ?

            OR s.username LIKE ?

            OR op.pro_name LIKE ?

        )

        ";

        $stmt = mysqli_prepare($conn, $sql);

        mysqli_stmt_bind_param(
            $stmt,
            "ssss",
            $orgId,
            $like,
            $like,
            $like
        );

    } else {

        $sql = "

        SELECT COUNT(*) AS total

        FROM supervisor

        WHERE org_id=?

        ";

        $stmt = mysqli_prepare($conn, $sql);

        mysqli_stmt_bind_param(
            $stmt,
            "s",
            $orgId
        );

    }

    mysqli_stmt_execute($stmt);

    $filteredTotal = (int) mysqli_fetch_assoc(
        mysqli_stmt_get_result($stmt)
    )['total'];

    /*
    |--------------------------------------------------------------------------
    | Batch 2 starts here...
    |--------------------------------------------------------------------------
    */

        /*
    |--------------------------------------------------------------------------
    | SUPERVISOR LIST
    |--------------------------------------------------------------------------
    | One supervisor per row.
    | All active projects returned as an array.
    |--------------------------------------------------------------------------
    */

    if ($search !== '') {

        $sql = "

        SELECT

            s.id,
            s.firstName,
            s.lastName,
            s.username,
            s.profile_photo,
            s.date_created,

            CASE
                WHEN COUNT(spa.id) > 0
                THEN 'Active'
                ELSE 'Inactive'
            END AS status,

            COALESCE(

                JSON_ARRAYAGG(

                    DISTINCT

                    CASE

                        WHEN op.pro_id IS NOT NULL THEN

                            JSON_OBJECT(

                                'pro_id', op.pro_id,

                                'project_name', op.pro_name

                            )

                    END

                ),

                JSON_ARRAY()

            ) AS projects

        FROM supervisor s

        LEFT JOIN supervisor_pro_assignment spa

            ON spa.supervisor_id=s.id
            AND spa.status='active'
            AND spa.unassigned_at IS NULL

        LEFT JOIN organisations_project op

            ON op.pro_id=spa.pro_id
            AND op.org_id=spa.org_id

        WHERE

            s.org_id=?

        AND

        (

            CONCAT(s.firstName,' ',s.lastName) LIKE ?

            OR s.username LIKE ?

            OR op.pro_name LIKE ?

        )

        GROUP BY

            s.id,
            s.firstName,
            s.lastName,
            s.username,
            s.profile_photo,
            s.date_created

        ORDER BY

            s.firstName ASC,
            s.lastName ASC

        LIMIT ? OFFSET ?

        ";

        $stmt = mysqli_prepare($conn, $sql);

        mysqli_stmt_bind_param(
            $stmt,
            "ssssii",
            $orgId,
            $like,
            $like,
            $like,
            $limit,
            $offset
        );

    } else {

        $sql = "

        SELECT

            s.id,
            s.firstName,
            s.lastName,
            s.username,
            s.profile_photo,
            s.date_created,

            CASE
                WHEN COUNT(spa.id) > 0
                THEN 'Active'
                ELSE 'Inactive'
            END AS status,

            COALESCE(

                JSON_ARRAYAGG(

                    DISTINCT

                    CASE

                        WHEN op.pro_id IS NOT NULL THEN

                            JSON_OBJECT(

                                'pro_id', op.pro_id,

                                'project_name', op.pro_name

                            )

                    END

                ),

                JSON_ARRAY()

            ) AS projects

        FROM supervisor s

        LEFT JOIN supervisor_pro_assignment spa

            ON spa.supervisor_id=s.id
            AND spa.status='active'
            AND spa.unassigned_at IS NULL

        LEFT JOIN organisations_project op

            ON op.pro_id=spa.pro_id
            AND op.org_id=spa.org_id

        WHERE s.org_id=?

        GROUP BY

            s.id,
            s.firstName,
            s.lastName,
            s.username,
            s.profile_photo,
            s.date_created

        ORDER BY

            s.firstName ASC,
            s.lastName ASC

        LIMIT ? OFFSET ?

        ";

        $stmt = mysqli_prepare($conn, $sql);

        mysqli_stmt_bind_param(
            $stmt,
            "sii",
            $orgId,
            $limit,
            $offset
        );

    }

    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    $supervisors = [];

    while ($row = mysqli_fetch_assoc($result)) {

        $projects = json_decode(
            $row['projects'],
            true
        );

        if (!is_array($projects)) {

            $projects = [];

        }

        // Remove NULL entries from LEFT JOIN
        $projects = array_values(
            array_filter($projects)
        );

        $initials = strtoupper(

            substr($row['firstName'], 0, 1) .

            substr($row['lastName'], 0, 1)

        );

        $supervisors[] = [

            'id' => (int)$row['id'],

            'initials' => $initials,

            'name' => trim(

                $row['firstName'] .

                ' ' .

                $row['lastName']

            ),

            'username' => $row['username'],

            'projects' => $projects,

            'project_count' => count($projects),

            'status' => $row['status'],

            'profile_photo' => $row['profile_photo'],

            'joined' => $row['date_created']

        ];

    }

    /*
    |--------------------------------------------------------------------------
    | RESPONSE
    |--------------------------------------------------------------------------
    */

    return [

        'summary' => [

            'total_supervisors'   => $totalSupervisors,

            'active_supervisors'  => $activeSupervisors,

            'communities_covered' => $communitiesCovered,

            'lgas_covered'        => $lgasCovered

        ],

        'page' => $page,

        'per_page' => $limit,

        'total' => $filteredTotal,

        'total_pages' => (int) ceil($filteredTotal / $limit),

        'supervisors' => $supervisors

    ];

}, 1800);

/*
|--------------------------------------------------------------------------
| RESPONSE
|--------------------------------------------------------------------------
*/

json_response([

    'status' => true,

    'message' => 'Supervisors retrieved successfully.',

    'data' => $data

]);