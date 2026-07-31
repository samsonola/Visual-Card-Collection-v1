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
    'organisation:%s:projects:%d:%d:%s',
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
    $search,
    $limit,
    $offset,
    $page
) {

    /*
    |--------------------------------------------------------------------------
    | SUMMARY - TOTAL PROJECTS
    |--------------------------------------------------------------------------
    */

    $stmt = mysqli_prepare(
        $conn,
        '
        SELECT COUNT(*) AS total
        FROM organisations_project
        WHERE org_id = ?
        '
    );

    mysqli_stmt_bind_param($stmt, 's', $orgId);

    mysqli_stmt_execute($stmt);

    $totalProjects = (int) mysqli_fetch_assoc(
        mysqli_stmt_get_result($stmt)
    )['total'];

    /*
    |--------------------------------------------------------------------------
    | SUMMARY - TOTAL SUPERVISORS
    |--------------------------------------------------------------------------
    | Count DISTINCT active supervisors for this organisation
    |--------------------------------------------------------------------------
    */

    $stmt = mysqli_prepare(
        $conn,
        '
        SELECT COUNT(DISTINCT supervisor_id) AS total
        FROM supervisor_pro_assignment
        WHERE org_id = ?
        AND status = \'active\'
        '
    );

    mysqli_stmt_bind_param($stmt, 's', $orgId);

    mysqli_stmt_execute($stmt);

    $totalSupervisors = (int) mysqli_fetch_assoc(
        mysqli_stmt_get_result($stmt)
    )['total'];

    /*
    |--------------------------------------------------------------------------
    | FILTERED TOTAL (FOR PAGINATION)
    |--------------------------------------------------------------------------
    */

    if ($search !== '') {

        $countSql = '
            SELECT COUNT(*) AS total
            FROM organisations_project
            WHERE org_id = ?
            AND pro_name LIKE ?
        ';

        $stmt = mysqli_prepare($conn, $countSql);

        $like = '%' . $search . '%';

        mysqli_stmt_bind_param(
            $stmt,
            'ss',
            $orgId,
            $like
        );

    } else {

        $countSql = '
            SELECT COUNT(*) AS total
            FROM organisations_project
            WHERE org_id = ?
        ';

        $stmt = mysqli_prepare($conn, $countSql);

        mysqli_stmt_bind_param(
            $stmt,
            's',
            $orgId
        );

    }

    mysqli_stmt_execute($stmt);

    $filteredTotal = (int) mysqli_fetch_assoc(
        mysqli_stmt_get_result($stmt)
    )['total'];

    /*
    |--------------------------------------------------------------------------
    | PROJECT LIST + SUPERVISOR COUNT
    |--------------------------------------------------------------------------
    | Single query. No N+1 problem.
    |--------------------------------------------------------------------------
    */

    if ($search !== '') {

        $sql = '
            SELECT
                op.pro_id,
                op.pro_name,
                op.date_created,
                COUNT(DISTINCT spa.supervisor_id) AS supervisors

            FROM organisations_project op

            LEFT JOIN supervisor_pro_assignment spa
                ON spa.pro_id = op.pro_id
               AND spa.org_id = op.org_id
               AND spa.status = \'active\'

            WHERE op.org_id = ?
            AND op.pro_name LIKE ?

            GROUP BY
                op.id,
                op.pro_id,
                op.pro_name,
                op.date_created

            ORDER BY op.pro_name ASC

            LIMIT ? OFFSET ?
        ';

        $stmt = mysqli_prepare($conn, $sql);

        mysqli_stmt_bind_param(
            $stmt,
            'ssii',
            $orgId,
            $like,
            $limit,
            $offset
        );

    } else {

        $sql = '
            SELECT
                op.pro_id,
                op.pro_name,
                op.date_created,
                COUNT(DISTINCT spa.supervisor_id) AS supervisors

            FROM organisations_project op

            LEFT JOIN supervisor_pro_assignment spa
                ON spa.pro_id = op.pro_id
               AND spa.org_id = op.org_id
               AND spa.status = \'active\'

            WHERE op.org_id = ?

            GROUP BY
                op.id,
                op.pro_id,
                op.pro_name,
                op.date_created

            ORDER BY op.pro_name ASC

            LIMIT ? OFFSET ?
        ';

        $stmt = mysqli_prepare($conn, $sql);

        mysqli_stmt_bind_param(
            $stmt,
            'sii',
            $orgId,
            $limit,
            $offset
        );

    }

    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    $projects = [];

    while ($row = mysqli_fetch_assoc($result)) {

        $projects[] = [

            'pro_id' => $row['pro_id'],

            'project_name' => $row['pro_name'],

            'supervisors' => (int) $row['supervisors'],

            'created' => $row['date_created']

        ];

    }

    // Batch 2 continues from here...

 

    return [

    /*
    |--------------------------------------------------------------------------
    | SUMMARY CARDS
    |--------------------------------------------------------------------------
    */

    'summary' => [

        'total_projects' => $totalProjects,

        'total_supervisors' => $totalSupervisors

    ],

    /*
    |--------------------------------------------------------------------------
    | PAGINATION
    |--------------------------------------------------------------------------
    */

    'page' => $page,

    'per_page' => $limit,

    'total' => $filteredTotal,

    'total_pages' => $filteredTotal > 0
        ? (int) ceil($filteredTotal / $limit)
        : 1,

    /*
    |--------------------------------------------------------------------------
    | TABLE DATA
    |--------------------------------------------------------------------------
    */

    'projects' => $projects

];

});

/*
|--------------------------------------------------------------------------
| RESPONSE
|--------------------------------------------------------------------------
*/

json_response([

    'status' => true,

    'message' => 'Projects retrieved successfully.',

    'data' => $data

]);