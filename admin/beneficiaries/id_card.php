<?php

declare(strict_types=1);

require __DIR__ . '/../../core/bootstrap.php';

/*
|--------------------------------------------------------------------------
| AUTH
|--------------------------------------------------------------------------
*/

$token = get_bearer_token();

if (!$token) {
    json_response([
        'success' => false,
        'message' => 'Unauthorized'
    ], 401);
}

if (!jwt_decode($token)) {
    json_response([
        'success' => false,
        'message' => 'Invalid token'
    ], 401);
}

$conn = db();

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

$cursor = max(0, (int)($_GET['cursor'] ?? 0));

$limit = (int)($_GET['limit'] ?? 50);

$limit = max(1, min($limit, 200));

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$name         = trim($_GET['name'] ?? '');
$household_id = trim($_GET['household_id'] ?? '');
$org_id       = trim($_GET['org_id'] ?? '');
$pro_id       = trim($_GET['pro_id'] ?? '');

/*
|--------------------------------------------------------------------------
| Cache
|--------------------------------------------------------------------------
*/

$useCache = ($cursor === 0);

$cacheKey = 'beneficiary_directory:' . md5(json_encode([
    'limit'        => $limit,
    'name'         => $name,
    'household_id' => $household_id,
    'org_id'       => $org_id,
    'pro_id'       => $pro_id
]));

if ($useCache) {

    $cached = cacheGet($cacheKey);

    if (!empty($cached)) {
        $cached['cached'] = true;
        json_response($cached);
    }
}

/*
|--------------------------------------------------------------------------
| SQL
|--------------------------------------------------------------------------
*/

$sql = "

SELECT

    cu.id,
    cu.caregiver_name,
    cu.household_id,
    cu.community,
    cu.org_id,
    cu.pro_id,

    CASE
        WHEN tc.household_id IS NULL THEN 'Not Paid'
        ELSE 'Paid'
    END AS payment_status

FROM community_user cu

LEFT JOIN tbl_collection tc
    ON tc.household_id = cu.household_id
   AND tc.org_id       = cu.org_id
   AND tc.pro_id       = cu.pro_id

";

/*
|--------------------------------------------------------------------------
| WHERE
|--------------------------------------------------------------------------
*/

$where = [];
$params = [];
$types = '';

$where[] = "cu.id > ?";
$params[] = $cursor;
$types .= "i";

if ($name !== '') {

    $where[] = "(cu.caregiver_name LIKE ?)";

    $params[] = "%{$name}%";

    $types .= "s";
}

if ($household_id !== '') {

    $where[] = "cu.household_id = ?";

    $params[] = $household_id;

    $types .= "s";
}

if ($org_id !== '') {

    $where[] = "cu.org_id = ?";

    $params[] = $org_id;

    $types .= "s";
}

if ($pro_id !== '') {

    $where[] = "cu.pro_id = ?";

    $params[] = $pro_id;

    $types .= "s";
}

if (!empty($where)) {

    $sql .= " WHERE " . implode(" AND ", $where);

}

$sql .= "

ORDER BY cu.id ASC

LIMIT ?

";

$params[] = $limit + 1;
$types .= "i";

/*
|--------------------------------------------------------------------------
| Execute
|--------------------------------------------------------------------------
*/

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {

    json_response([
        'success' => false,
        'message' => mysqli_error($conn)
    ], 500);

}

mysqli_stmt_bind_param($stmt, $types, ...$params);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

$rows = [];

while ($row = mysqli_fetch_assoc($result)) {

    $initials = '';

    foreach (preg_split('/\s+/', trim($row['caregiver_name'])) as $part) {

        $initials .= strtoupper(substr($part, 0, 1));

        if (strlen($initials) >= 2) {
            break;
        }
    }

    $rows[] = [

        'id' => (int)$row['id'],

        'initials' => $initials,

        'caregiver_name' => $row['caregiver_name'],

        'household_id' => $row['household_id'],

        'community' => $row['community'],

        'status' => $row['payment_status'],

        'org_id' => $row['org_id'],

        'pro_id' => $row['pro_id']

    ];
}

mysqli_stmt_close($stmt);

/*
|--------------------------------------------------------------------------
| Cursor Pagination
|--------------------------------------------------------------------------
*/

$hasMore = count($rows) > $limit;

if ($hasMore) {
    array_pop($rows);
}

$nextCursor = null;

if ($hasMore && !empty($rows)) {

    $nextCursor = end($rows)['id'];

}

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

$response = [

    'success' => true,

    'cached' => false,

    'data' => $rows,

    'pagination' => [

        'limit' => $limit,

        'next_cursor' => $nextCursor,

        'has_more' => $hasMore,

        'returned' => count($rows)

    ]

];

/*
|--------------------------------------------------------------------------
| Cache first page
|--------------------------------------------------------------------------
*/

if ($useCache) {

    cachePut($cacheKey, $response, 60);

}

json_response($response);