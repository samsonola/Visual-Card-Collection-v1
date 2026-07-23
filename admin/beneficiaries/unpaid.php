<?php

declare(strict_types=1);

require __DIR__ . '/../../core/bootstrap.php';

/*
|--------------------------------------------------------------------------
| AUTHENTICATION
|--------------------------------------------------------------------------
*/

$token = get_bearer_token();

if (!$token) {
    json_response([
        'success' => false,
        'message' => 'Unauthorized'
    ], 401);
}

$decoded = jwt_decode($token);

if (!$decoded) {
    json_response([
        'success' => false,
        'message' => 'Invalid token'
    ], 401);
}

$conn = db();

/*
|--------------------------------------------------------------------------
| PAGINATION
|--------------------------------------------------------------------------
*/

$cursor = isset($_GET['cursor'])
    ? max(0, (int)$_GET['cursor'])
    : 0;

$limit = isset($_GET['limit'])
    ? (int)$_GET['limit']
    : 50;

if ($limit < 1) {
    $limit = 50;
}

if ($limit > 200) {
    $limit = 200;
}

/*
|--------------------------------------------------------------------------
| SEARCH
|--------------------------------------------------------------------------
*/

$name = trim($_GET['name'] ?? '');

$household_id = trim($_GET['household_id'] ?? '');

$alternative_id = trim($_GET['alternative_id'] ?? '');

$org_id = trim($_GET['org_id'] ?? '');

$pro_id = trim($_GET['pro_id'] ?? '');

$state = trim($_GET['state'] ?? '');

$lga = trim($_GET['lga'] ?? '');

$ward = trim($_GET['ward'] ?? '');

$community = trim($_GET['community'] ?? '');

/*
|--------------------------------------------------------------------------
| CACHE
|--------------------------------------------------------------------------
|
| Cache ONLY the first page.
|
*/

$useCache = ($cursor === 0);

$cacheKey = 'beneficiaries:unpaid:' . md5(json_encode([
    'limit'          => $limit,
    'name'           => $name,
    'household_id'   => $household_id,
    'alternative_id' => $alternative_id,
    'org_id'         => $org_id,
    'pro_id'         => $pro_id,
    'state'          => $state,
    'lga'            => $lga,
    'ward'           => $ward,
    'community'      => $community
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
    cu.household_id,
    cu.caregiver_name,
    cu.alternative_name,
    cu.alternative_id,
    cu.community,
    cu.ward,
    cu.lga,
    cu.state,
    cu.org_id,
    cu.pro_id,
    cu.amount,
    cu.date_created

FROM community_user cu

";

/*
|--------------------------------------------------------------------------
| WHERE
|--------------------------------------------------------------------------
*/

$where = [];

$params = [];

$types = '';

/*
|--------------------------------------------------------------------------
| Cursor Pagination
|--------------------------------------------------------------------------
*/

$where[] = "cu.id > ?";

$params[] = $cursor;

$types .= "i";

/*
|--------------------------------------------------------------------------
| Unpaid Beneficiaries
|--------------------------------------------------------------------------
|
| A beneficiary is unpaid if no corresponding payment
| exists in tbl_collection.
|
*/

$where[] = "

NOT EXISTS (

    SELECT 1

    FROM tbl_collection tc

    WHERE tc.household_id = cu.household_id

      AND tc.org_id = cu.org_id

      AND tc.pro_id = cu.pro_id

)

";

/*
|--------------------------------------------------------------------------
| SEARCH
|--------------------------------------------------------------------------
*/

if ($name !== '') {

    $where[] = "(
        cu.caregiver_name LIKE ?
        OR cu.alternative_name LIKE ?
    )";

    $keyword = "%{$name}%";

    $params[] = $keyword;
    $params[] = $keyword;

    $types .= "ss";

}

if ($household_id !== '') {

    $where[] = "cu.household_id = ?";

    $params[] = $household_id;

    $types .= "s";

}

if ($alternative_id !== '') {

    $where[] = "cu.alternative_id = ?";

    $params[] = $alternative_id;

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

if ($state !== '') {

    $where[] = "cu.state = ?";

    $params[] = $state;

    $types .= "s";

}

if ($lga !== '') {

    $where[] = "cu.lga = ?";

    $params[] = $lga;

    $types .= "s";

}

if ($ward !== '') {

    $where[] = "cu.ward = ?";

    $params[] = $ward;

    $types .= "s";

}

if ($community !== '') {

    $where[] = "cu.community = ?";

    $params[] = $community;

    $types .= "s";

}

/*
|--------------------------------------------------------------------------
| Build WHERE
|--------------------------------------------------------------------------
*/

if (!empty($where)) {

    $sql .= " WHERE " . implode(" AND ", $where);

}

/*
|--------------------------------------------------------------------------
| ORDER
|--------------------------------------------------------------------------
|
| Always order by the primary key for keyset pagination.
|
*/

$sql .= "

    ORDER BY
        cu.id ASC

    LIMIT ?

";

/*
|--------------------------------------------------------------------------
| LIMIT + 1
|--------------------------------------------------------------------------
|
| Fetch one extra row so we know if another page exists.
|
*/

$params[] = $limit + 1;

$types .= "i";

/*
|--------------------------------------------------------------------------
| PREPARE
|--------------------------------------------------------------------------
*/

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {

    json_response([
        'success' => false,
        'message' => 'Unable to prepare query.',
        'error' => mysqli_error($conn)
    ], 500);

}

/*
|--------------------------------------------------------------------------
| BIND
|--------------------------------------------------------------------------
*/

mysqli_stmt_bind_param(
    $stmt,
    $types,
    ...$params
);

/*
|--------------------------------------------------------------------------
| EXECUTE
|--------------------------------------------------------------------------
*/

if (!mysqli_stmt_execute($stmt)) {

    json_response([
        'success' => false,
        'message' => 'Unable to execute query.',
        'error' => mysqli_stmt_error($stmt)
    ], 500);

}

/*
|--------------------------------------------------------------------------
| RESULT
|--------------------------------------------------------------------------
*/

$result = mysqli_stmt_get_result($stmt);

$rows = [];

while ($row = mysqli_fetch_assoc($result)) {

    $initials = '';

    if (!empty($row['caregiver_name'])) {

        foreach (preg_split('/\s+/', trim($row['caregiver_name'])) as $part) {

            $initials .= strtoupper(substr($part, 0, 1));

            if (strlen($initials) >= 2) {
                break;
            }
        }
    }

    $rows[] = [

        'id' => (int)$row['id'],
        'initials' => $initials,
        'caregiver_name' => $row['caregiver_name'],
        'alternative_name' => $row['alternative_name'],
        'household_id' => $row['household_id'],
        'alternative_id' => $row['alternative_id'],
        'community' => $row['community'],
        'ward' => $row['ward'],
        'lga' => $row['lga'],
        'state' => $row['state'],
        'org_id' => $row['org_id'],
        'pro_id' => $row['pro_id'],
        'amount' => (float)$row['amount'],
        'status' => 'Not Paid',
        'date_created' => $row['date_created']

    ];
}

mysqli_stmt_close($stmt);


/*
|--------------------------------------------------------------------------
| CURSOR PAGINATION
|--------------------------------------------------------------------------
*/

$hasMore = count($rows) > $limit;

if ($hasMore) {

    array_pop($rows);

}

$nextCursor = null;

if ($hasMore && !empty($rows)) {

    $last = end($rows);

    $nextCursor = (int)$last['id'];

}

/*
|--------------------------------------------------------------------------
| RESPONSE
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
| CACHE
|--------------------------------------------------------------------------
|
| Cache only the first page.
|
*/

if ($useCache) {

    cachePut(
        $cacheKey,
        $response,
        60
    );

}

/*
|--------------------------------------------------------------------------
| OUTPUT
|--------------------------------------------------------------------------
*/




json_response($response);
exit;



