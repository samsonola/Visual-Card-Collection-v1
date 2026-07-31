<?php

require __DIR__ . '/../../core/bootstrap.php';

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
// CACHE KEY
// -------------------------------------------------
$cacheKey = 'dashboard:overview';

// -------------------------------------------------
// DASHBOARD OVERVIEW
// -------------------------------------------------
$data = cacheRemember($cacheKey, function () use ($conn) {

    // Organisations
    $org_total = (int) mysqli_fetch_assoc(
        mysqli_query($conn, "SELECT COUNT(*) AS total FROM organisations")
    )['total'];

    $org_this_month = (int) mysqli_fetch_assoc(
        mysqli_query($conn, "
            SELECT COUNT(*) AS total
            FROM organisations
            WHERE date_created >= DATE_FORMAT(NOW(), '%Y-%m-01')
              AND date_created < DATE_FORMAT(DATE_ADD(NOW(), INTERVAL 1 MONTH), '%Y-%m-01')
        ")
    )['total'];

    // Beneficiaries
    $ben_total = (int) mysqli_fetch_assoc(
        mysqli_query($conn, "SELECT COUNT(*) AS total FROM community_user")
    )['total'];

    $ben_this_week = (int) mysqli_fetch_assoc(
        mysqli_query($conn, "
            SELECT COUNT(*) AS total
            FROM community_user
            WHERE date_created >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ")
    )['total'];

    // Distributed
    $distributed_total = (int) mysqli_fetch_assoc(
        mysqli_query($conn, "SELECT COUNT(*) AS total FROM tbl_collection")
    )['total'];

    $coverage = $ben_total > 0
        ? round(($distributed_total / $ben_total) * 100, 2)
        : 0;

    // Active Supervisors
    $active_supervisors = (int) mysqli_fetch_assoc(
        mysqli_query($conn, "
            SELECT COUNT(DISTINCT supervisor_id) AS total
            FROM supervisor_pro_assignment
            WHERE status = 'active'
        ")
    )['total'];

    return [
        'organisations' => [
            'total'      => $org_total,
            'this_month' => $org_this_month
        ],
        'beneficiaries' => [
            'total'      => $ben_total,
            'this_week'  => $ben_this_week
        ],
        'distributed' => [
            'total'      => $distributed_total,
            'coverage'   => $coverage . '%'
        ],
        'active_supervisors' => $active_supervisors
    ];

}, $_ENV['DASHBOARD_CACHE_TTL']);

// -------------------------------------------------
// RESPONSE
// -------------------------------------------------
json_response($data);