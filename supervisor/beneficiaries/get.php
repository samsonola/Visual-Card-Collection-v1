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

/*
|--------------------------------------------------------------------------
| AUTHORIZE SUPERVISOR
|--------------------------------------------------------------------------
*/

if (
    !isset($decoded->role) ||
    $decoded->role !== 'supervisor'
) {

    json_response([
        'success' => false,
        'message' => 'Access denied'
    ], 403);

}

$supervisorId = (int)($decoded->id ?? 0);

$orgId = $decoded->org_id ?? '';

$projects = $decoded->projects ?? [];

if (
    $supervisorId <= 0 ||
    $orgId === ''
) {

    json_response([
        'success' => false,
        'message' => 'Invalid supervisor token'
    ], 403);

}

if (
    !is_array($projects) ||
    empty($projects)
) {

    json_response([
        'success' => true,
        'cached' => false,
        'data' => [],
        'pagination' => [
            'limit' => 0,
            'returned' => 0,
            'has_more' => false,
            'next_cursor' => null
        ],
        'filters' => []
    ]);

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
| FILTERS
|--------------------------------------------------------------------------
*/

$search = trim($_GET['search'] ?? '');

$status = strtolower(
    trim($_GET['status'] ?? '')
);

$state = trim($_GET['state'] ?? '');

$lga = trim($_GET['lga'] ?? '');

$ward = trim($_GET['ward'] ?? '');

$community = trim($_GET['community'] ?? '');

/*
|--------------------------------------------------------------------------
| VALIDATE STATUS
|--------------------------------------------------------------------------
*/

if (
    $status !== '' &&
    !in_array(
        $status,
        [
            'paid',
            'not_paid'
        ],
        true
    )
) {

    json_response([
        'success' => false,
        'message' => 'Invalid status filter.'
    ], 422);

}

/*
|--------------------------------------------------------------------------
| CACHE
|--------------------------------------------------------------------------
*/

$cacheKey = 'supervisor_beneficiaries:' . md5(
    json_encode([
        $supervisorId,
        $orgId,
        $projects,
        $cursor,
        $limit,
        $search,
        $status,
        $state,
        $lga,
        $ward,
        $community
    ])
);

if ($cached = cacheGet($cacheKey)) {

    $cached['cached'] = true;

    json_response($cached);

}

/*
|--------------------------------------------------------------------------
| PROJECT PLACEHOLDERS
|--------------------------------------------------------------------------
*/

$projectPlaceholders = implode(
    ',',
    array_fill(
        0,
        count($projects),
        '?'
    )
);

/*
|--------------------------------------------------------------------------
| BASE SQL
|--------------------------------------------------------------------------
*/

$sql = "

SELECT

    cu.id,

    cu.caregiver_name,

    cu.household_id,

    cu.community,

    cu.state,

    cu.lga,

    cu.ward,

    cu.alternative_id,

    cu.alternative_name,

    cu.pro_id,

    cu.date_created

FROM community_user_assignment cua

INNER JOIN community_user cu

    ON cu.id = cua.community_user_id

";

/*
|--------------------------------------------------------------------------
| WHERE
|--------------------------------------------------------------------------
*/

$where = [

    "cua.assignee_id = ?",

    "cua.assignee_type='supervisor'",

    "cua.status='active'",

    "cua.unassigned_at IS NULL",

    "cua.org_id = ?",

    "cu.id > ?",

    "cua.pro_id IN ($projectPlaceholders)"

];

$types = "isi";

$params = [

    $supervisorId,

    $orgId,

    $cursor

];

/*
|--------------------------------------------------------------------------
| PROJECTS
|--------------------------------------------------------------------------
*/

foreach ($projects as $projectId) {

    $types .= "s";

    $params[] = (string)$projectId;

}

/*
|--------------------------------------------------------------------------
| SEARCH
|--------------------------------------------------------------------------
*/

if ($search !== '') {

    $where[] = "

    (

        cu.caregiver_name LIKE ?

        OR cu.alternative_name LIKE ?

        OR cu.household_id LIKE ?

        OR cu.alternative_id LIKE ?

    )

    ";

    $keyword = "%{$search}%";

    $types .= "ssss";

    $params[] = $keyword;
    $params[] = $keyword;
    $params[] = $keyword;
    $params[] = $keyword;

}

/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/

if ($state !== '') {

    $where[] = "cu.state=?";

    $types .= "s";

    $params[] = $state;

}

if ($lga !== '') {

    $where[] = "cu.lga=?";

    $types .= "s";

    $params[] = $lga;

}

if ($ward !== '') {

    $where[] = "cu.ward=?";

    $types .= "s";

    $params[] = $ward;

}

if ($community !== '') {

    $where[] = "cu.community=?";

    $types .= "s";

    $params[] = $community;

}


/*
|--------------------------------------------------------------------------
| PAYMENT STATUS
|--------------------------------------------------------------------------
*/

if ($status === 'paid') {

    $where[] = "

    EXISTS (

        SELECT 1

        FROM tbl_collection tc

        WHERE

            tc.household_id = cu.household_id

        AND tc.org_id = cu.org_id

        AND tc.pro_id = cu.pro_id

    )

    ";

}

elseif ($status === 'not_paid') {

    $where[] = "

    NOT EXISTS (

        SELECT 1

        FROM tbl_collection tc

        WHERE

            tc.household_id = cu.household_id

        AND tc.org_id = cu.org_id

        AND tc.pro_id = cu.pro_id

    )

    ";

}

/*
|--------------------------------------------------------------------------
| BUILD QUERY
|--------------------------------------------------------------------------
*/

$sql .= "

WHERE

" . implode(

    " AND ",

    $where

);

$sql .= "

ORDER BY cu.id ASC

LIMIT ?

";

$types .= "i";

$params[] = $limit + 1;

/*
|--------------------------------------------------------------------------
| EXECUTE
|--------------------------------------------------------------------------
*/

$stmt = mysqli_prepare(
    $conn,
    $sql
);

if (!$stmt) {

    json_response([
        'success' => false,
        'message' => mysqli_error($conn)
    ], 500);

}

/*
|--------------------------------------------------------------------------
| BIND PARAMS
|--------------------------------------------------------------------------
*/

$bind = [];

$bind[] = &$types;

foreach ($params as $key => $value) {

    $bind[] = &$params[$key];

}

call_user_func_array(
    'mysqli_stmt_bind_param',
    array_merge([$stmt], $bind)
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

/*
|--------------------------------------------------------------------------
| RESPONSE DATA
|--------------------------------------------------------------------------
*/

$data = [];

$hasMore = false;

$nextCursor = null;

while ($row = mysqli_fetch_assoc($result)) {

    if (count($data) >= $limit) {

        $hasMore = true;

        $nextCursor = (int)$row['id'];

        break;

    }

    /*
    |--------------------------------------------------------------------------
    | PAYMENT STATUS
    |--------------------------------------------------------------------------
    */

    $paid = mysqli_prepare(

        $conn,

        "

        SELECT 1

        FROM tbl_collection

        WHERE

            household_id=?

        AND org_id=?

        AND pro_id=?

        LIMIT 1

        "

    );

    mysqli_stmt_bind_param(

        $paid,

        "sss",

        $row['household_id'],

        $orgId,

        $row['pro_id']

    );

    mysqli_stmt_execute($paid);

    $paidResult = mysqli_stmt_get_result($paid);

    $paymentStatus = mysqli_num_rows($paidResult)

        ? "Paid"

        : "Not Paid";

    mysqli_stmt_close($paid);

    /*
    |--------------------------------------------------------------------------
    | INITIALS
    |--------------------------------------------------------------------------
    */

    $words = preg_split(

        '/\s+/',

        trim($row['caregiver_name'])

    );

    $initials = '';

    foreach (

        array_slice($words, 0, 2)

        as $word

    ) {

        $initials .= strtoupper(

            substr($word, 0, 1)

        );

    }

    $data[] = [

        'id' => (int)$row['id'],

        'initials' => $initials,

        'caregiver_name' => $row['caregiver_name'],

        'household_id' => $row['household_id'],

        'community' => $row['community'],

        'state' => $row['state'],

        'lga' => $row['lga'],

        'ward' => $row['ward'],

        'alternative_id' => $row['alternative_id'],

        'status' => $paymentStatus,

        'date_created' => $row['date_created']

    ];

}

mysqli_stmt_close($stmt);

/*
|--------------------------------------------------------------------------
| RESPONSE
|--------------------------------------------------------------------------
*/

$response = [

    'success' => true,

    'cached' => false,

    'data' => $data,

    'pagination' => [

        'limit' => $limit,

        'returned' => count($data),

        'has_more' => $hasMore,

        'next_cursor' => $nextCursor

    ],

    'filters' => [

        'search' => $search,

        'status' => $status,

        'state' => $state,

        'lga' => $lga,

        'ward' => $ward,

        'community' => $community

    ]

];

cachePut(

    $cacheKey,

    $response,

    300

);

json_response($response);