<?php

require_once __DIR__ . '/../../core/bootstrap.php';

// -------------------------------------------------
// AUTHENTICATION
// -------------------------------------------------

$token = get_bearer_token();

if (!$token) {
    json_response(['error' => 'Unauthorized'], 401);
}

$decoded = jwt_decode($token);

if (!$decoded) {
    json_response(['error' => 'Invalid token'], 401);
}

$conn = db();

// -------------------------------------------------
// PAGINATION
// -------------------------------------------------

$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = max(1, min(100, (int)($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;

// -------------------------------------------------
// FILTERS
// -------------------------------------------------

$org_id = trim($_GET['org_id'] ?? '');
$search = trim($_GET['search'] ?? '');

$where = [];

if ($org_id !== '') {

    $org_id = mysqli_real_escape_string($conn, $org_id);

    $where[] = "op.org_id='$org_id'";

}

if ($search !== '') {

    $search = mysqli_real_escape_string($conn, $search);

    $where[] = "op.pro_name LIKE '%$search%'";

}

$whereSql = '';

if ($where) {

    $whereSql = 'WHERE ' . implode(' AND ', $where);

}

// -------------------------------------------------
// CACHE
// -------------------------------------------------

$cacheKey = sprintf(
    'admin:projects:%d:%d:%s:%s',
    $page,
    $limit,
    $org_id,
    md5($search)
);

$data = cacheRemember($cacheKey, function () use (
    $conn,
    $whereSql,
    $limit,
    $offset,
    $page
) {

    // ---------------------------------------------
    // TOTAL
    // ---------------------------------------------

    $countSql = "

    SELECT COUNT(*) AS total

    FROM organisations_project op

    $whereSql

    ";

    $total = (int)mysqli_fetch_assoc(
        mysqli_query($conn, $countSql)
    )['total'];

    // ---------------------------------------------
    // DATA
    // ---------------------------------------------

    $sql = "

    SELECT

        op.pro_id,
        op.pro_name,
        op.org_id,
        o.org_name,
        op.date_created

    FROM organisations_project op

    LEFT JOIN organisations o

        ON o.org_id = op.org_id

    $whereSql

    ORDER BY op.pro_name ASC

    LIMIT $limit OFFSET $offset

    ";

    $result = mysqli_query($conn, $sql);

    if (!$result) {

        throw new Exception(mysqli_error($conn));

    }

    $projects = [];

    while ($row = mysqli_fetch_assoc($result)) {

        $projects[] = [

            'pro_id' => (int)$row['pro_id'],

            'project_name' => $row['pro_name'],

            'org_id' => (int)$row['org_id'],

            'organisation' => $row['org_name'],

            'created' => $row['date_created']

        ];

    }

    return [

        'page' => $page,

        'per_page' => $limit,

        'total' => $total,

        'total_pages' => (int)ceil($total / $limit),

        'projects' => $projects

    ];

}, 60);

// -------------------------------------------------
// RESPONSE
// -------------------------------------------------

json_response($data);