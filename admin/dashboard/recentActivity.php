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
// CACHE KEY
// -------------------------------------------------
$cacheKey = 'dashboard:recent_activity';

// -------------------------------------------------
// GET RECENT ACTIVITY (CACHE FIRST)
// -------------------------------------------------
$activity = cacheRemember($cacheKey, function () use ($conn) {

    $sql = "
        SELECT
            tc.household_id,
            tc.collector_name,
            tc.collector_role,
            tc.state,
            tc.lga,
            tc.ward,
            tc.community,
            tc.org_id,
            tc.pro_id,
            tc.date_created
        FROM tbl_collection tc
        ORDER BY tc.date_created DESC
        LIMIT 10
    ";

    $result = mysqli_query($conn, $sql);

    if (!$result) {
        return [];
    }

    $rows = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }

    return $rows;

}, $_ENV['DASHBOARD_CACHE_TTL']); // Cache for 60 seconds

// -------------------------------------------------
// RESPONSE
// -------------------------------------------------
json_response([
    'recent_activity' => $activity
]);