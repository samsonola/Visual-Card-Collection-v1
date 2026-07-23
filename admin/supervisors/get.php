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

$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = max(1, min(100, (int)($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;

// -------------------------------------------------
// FILTERS
// -------------------------------------------------

$org_id = trim($_GET['org_id'] ?? '');
$search = trim($_GET['search'] ?? '');

$where = [];

if ($org_id !== '') {

    $org_id = mysqli_real_escape_string($conn, $org_id);

    $where[] = "s.org_id='$org_id'";
}

if ($search !== '') {

    $search = mysqli_real_escape_string($conn, $search);

    $where[] = "(
        s.firstName LIKE '%$search%' OR
        s.lastName LIKE '%$search%' OR
        s.username LIKE '%$search%' OR
        s.email LIKE '%$search%'
    )";
}

$whereSql = '';

if ($where) {
    $whereSql = 'WHERE ' . implode(' AND ', $where);
}

// -------------------------------------------------
// CACHE
// -------------------------------------------------

$cacheKey = sprintf(
    'admin:supervisor:%d:%d:%s:%s',
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
        FROM supervisor s
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
            s.id,
            s.firstName,
            s.lastName,
            s.username,
            s.email,
            s.profile_photo,
            s.org_id,
            s.date_created,
            o.org_name
        FROM supervisor s
        LEFT JOIN organisations o
            ON o.org_id = s.org_id
        $whereSql
        ORDER BY s.date_created DESC
        LIMIT $limit OFFSET $offset
    ";

    $result = mysqli_query($conn, $sql);

    if (!$result) {
        throw new Exception(mysqli_error($conn));
    }

    $supervisor = [];

    while ($row = mysqli_fetch_assoc($result)) {

        $supervisor[] = [
            'id' => (int)$row['id'],
            'first_name' => $row['firstName'],
            'last_name' => $row['lastName'],
            'username' => $row['username'],
            'email' => $row['email'],
            'profile_photo' => $row['profile_photo'],
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
        'supervisor' => $supervisor
    ];

}, 60);

// -------------------------------------------------
// RESPONSE
// -------------------------------------------------

json_response($data);