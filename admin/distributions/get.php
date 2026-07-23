<?php

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
|
| Cursor Pagination
|
| Example
|
| cursor=1000
| limit=50
|
*/

$cursor = isset($_GET['cursor'])
    ? max(0, (int) $_GET['cursor'])
    : 0;

$limit = isset($_GET['limit'])
    ? (int) $_GET['limit']
    : 50;

if ($limit < 1) {
    $limit = 50;
}

if ($limit > 200) {
    $limit = 200;
}

/*
|--------------------------------------------------------------------------
| SEARCH PARAMETERS
|--------------------------------------------------------------------------
*/

$household_id = trim($_GET['household_id'] ?? '');

$beneficiary_name = trim($_GET['beneficiary_name'] ?? '');

$collector_name = trim($_GET['collector_name'] ?? '');

$collector_role = trim($_GET['collector_role'] ?? '');

$agent_id = trim($_GET['agent_id'] ?? '');

$org_id = trim($_GET['org_id'] ?? '');

$pro_id = trim($_GET['pro_id'] ?? '');

$state = trim($_GET['state'] ?? '');

$lga = trim($_GET['lga'] ?? '');

$ward = trim($_GET['ward'] ?? '');

$community = trim($_GET['community'] ?? '');

$tech_pay = trim($_GET['tech_pay'] ?? '');

$date_from = trim($_GET['date_from'] ?? '');

$date_to = trim($_GET['date_to'] ?? '');

/*
|--------------------------------------------------------------------------
| CACHE KEY
|--------------------------------------------------------------------------
*/

$cacheKey =

    'collections:' .

    md5(

        json_encode([

            'cursor' => $cursor,

            'limit' => $limit,

            'household_id' => $household_id,

            'beneficiary_name' => $beneficiary_name,

            'collector_name' => $collector_name,

            'collector_role' => $collector_role,

            'agent_id' => $agent_id,

            'org_id' => $org_id,

            'pro_id' => $pro_id,

            'state' => $state,

            'lga' => $lga,

            'ward' => $ward,

            'community' => $community,

            'tech_pay' => $tech_pay,

            'date_from' => $date_from,

            'date_to' => $date_to

        ])

    );

/*
|--------------------------------------------------------------------------
| CACHE
|--------------------------------------------------------------------------
*/

$cached = cacheGet($cacheKey);

if (!empty($cached)) {

    $cached['cached'] = true;

    json_response($cached);

}

/*
|--------------------------------------------------------------------------
| SQL BUILDER
|--------------------------------------------------------------------------
*/

$sql = "

SELECT

    tc.id,

    tc.household_id,

    tc.collector_name,

    tc.collector_role,

    tc.collector_img,

    tc.agent_id,

    tc.tech_pay,

    tc.state,

    tc.lga,

    tc.ward,

    tc.community,

    tc.lat,

    tc.log,

    tc.org_id,

    tc.pro_id,

    tc.date_created,

    cu.caregiver_name,

    cu.alternative_name,

    a.agent_name

FROM tbl_collection tc

LEFT JOIN community_user cu

ON

    cu.household_id = tc.household_id

AND

    cu.org_id = tc.org_id

AND

    cu.pro_id = tc.pro_id

LEFT JOIN agent a

ON

    a.agent_id = tc.agent_id

";

/*
|--------------------------------------------------------------------------
| WHERE CLAUSES
|--------------------------------------------------------------------------
*/

$where = [];

$params = [];

$types = "";

/*
|--------------------------------------------------------------------------
| CURSOR PAGINATION
|--------------------------------------------------------------------------
*/

$where[] = "tc.id > ?";

$params[] = $cursor;

$types .= "i";

/*
|--------------------------------------------------------------------------
| SEARCH
|--------------------------------------------------------------------------
*/

if ($household_id !== '') {

    $where[] = "tc.household_id = ?";

    $params[] = $household_id;

    $types .= "s";

}

if ($beneficiary_name !== '') {

    $where[] = "

    (

        cu.caregiver_name LIKE ?

        OR

        cu.alternative_name LIKE ?

    )

    ";

    $keyword = "%{$beneficiary_name}%";

    $params[] = $keyword;

    $params[] = $keyword;

    $types .= "ss";

}

if ($collector_name !== '') {

    $where[] = "tc.collector_name LIKE ?";

    $params[] = "%{$collector_name}%";

    $types .= "s";

}

if ($collector_role !== '') {

    $where[] = "tc.collector_role = ?";

    $params[] = $collector_role;

    $types .= "s";

}

if ($agent_id !== '') {

    $where[] = "tc.agent_id = ?";

    $params[] = $agent_id;

    $types .= "s";

}

if ($org_id !== '') {

    $where[] = "tc.org_id = ?";

    $params[] = $org_id;

    $types .= "s";

}

if ($pro_id !== '') {

    $where[] = "tc.pro_id = ?";

    $params[] = $pro_id;

    $types .= "s";

}

/*
|--------------------------------------------------------------------------
| REMAINING FILTERS
|--------------------------------------------------------------------------
*/

if ($state !== '') {

    $where[] = "tc.state = ?";

    $params[] = $state;

    $types .= "s";

}

if ($lga !== '') {

    $where[] = "tc.lga = ?";

    $params[] = $lga;

    $types .= "s";

}

if ($ward !== '') {

    $where[] = "tc.ward = ?";

    $params[] = $ward;

    $types .= "s";

}

if ($community !== '') {

    $where[] = "tc.community = ?";

    $params[] = $community;

    $types .= "s";

}

if ($tech_pay !== '') {

    $where[] = "tc.tech_pay = ?";

    $params[] = $tech_pay;

    $types .= "s";

}

/*
|--------------------------------------------------------------------------
| DATE RANGE
|--------------------------------------------------------------------------
|
| Avoid DATE(date_created)
| so MySQL can still use indexes.
|
*/

if ($date_from !== '') {

    $where[] = "tc.date_created >= ?";

    $params[] = $date_from . " 00:00:00";

    $types .= "s";

}

if ($date_to !== '') {

    $where[] = "tc.date_created <= ?";

    $params[] = $date_to . " 23:59:59";

    $types .= "s";

}

/*
|--------------------------------------------------------------------------
| BUILD WHERE
|--------------------------------------------------------------------------
*/

if (!empty($where)) {

    $sql .=

        "

        WHERE

        "

        .

        implode(

            "

            AND

            ",

            $where

        );

}

/*
|--------------------------------------------------------------------------
| COUNT CACHE
|--------------------------------------------------------------------------
*/

$countCacheKey =

    'collections:count:' .

    md5(

        json_encode([

            $household_id,

            $beneficiary_name,

            $collector_name,

            $collector_role,

            $agent_id,

            $org_id,

            $pro_id,

            $state,

            $lga,

            $ward,

            $community,

            $tech_pay,

            $date_from,

            $date_to

        ])

    );

$total = cacheGet($countCacheKey);

if ($total === null) {

    $countSql = "

    SELECT

        COUNT(*) total

    FROM tbl_collection tc

    LEFT JOIN community_user cu

        ON cu.household_id = tc.household_id

       AND cu.org_id = tc.org_id

       AND cu.pro_id = tc.pro_id

    ";

    if (!empty($where)) {

        $countWhere = $where;

        /*
        |--------------------------------------------------------------------------
        | Remove Cursor Condition
        |--------------------------------------------------------------------------
        */

        array_shift($countWhere);

        if (!empty($countWhere)) {

            $countSql .=

                "

                WHERE

                "

                .

                implode(

                    "

                    AND

                    ",

                    $countWhere

                );

        }

    }

    $stmt = mysqli_prepare($conn, $countSql);

    if (!$stmt) {

        json_response([

            'success' => false,

            'message' => mysqli_error($conn)

        ], 500);

    }

    /*
    |--------------------------------------------------------------------------
    | Bind Count Parameters
    |--------------------------------------------------------------------------
    */

    if (count($params) > 1) {

        $countTypes = substr($types, 1);

        $countParams = array_slice($params, 1);

        mysqli_stmt_bind_param(

            $stmt,

            $countTypes,

            ...$countParams

        );

    }

    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    $total = (int) mysqli_fetch_assoc($result)['total'];

    mysqli_stmt_close($stmt);

    /*
    |--------------------------------------------------------------------------
    | Cache Count
    |--------------------------------------------------------------------------
    */

    cachePut(

        $countCacheKey,

        $total,

        3600

    );

}

/*
|--------------------------------------------------------------------------
| FINAL QUERY
|--------------------------------------------------------------------------
*/

$sql .=

    "

    ORDER BY

        tc.id ASC

    LIMIT ?

    ";

/*
|--------------------------------------------------------------------------
| LIMIT + 1
|--------------------------------------------------------------------------
*/

$params[] =

    $limit + 1;

$types .=

    "i";

/*
|--------------------------------------------------------------------------
| PREPARE STATEMENT
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

mysqli_stmt_bind_param(

    $stmt,

    $types,

    ...$params

);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);


/*
|--------------------------------------------------------------------------
| FETCH RESULTS
|--------------------------------------------------------------------------
*/

$collections = [];

$hasMore = false;

$nextCursor = null;

while (

    $row = mysqli_fetch_assoc($result)

) {

    /*
    |--------------------------------------------------------------------------
    | LIMIT + 1
    |--------------------------------------------------------------------------
    */

    if (

        count($collections)

        >=

        $limit

    ) {

        $hasMore = true;

        $nextCursor = (int) $row['id'];

        break;

    }

    /*
    |--------------------------------------------------------------------------
    | Beneficiary Name
    |--------------------------------------------------------------------------
    */

    $beneficiaryName = '';

    if (!empty($row['caregiver_name'])) {

        $beneficiaryName = $row['caregiver_name'];

    } elseif (!empty($row['alternative_name'])) {

        $beneficiaryName = $row['alternative_name'];

    }

    /*
    |--------------------------------------------------------------------------
    | Response Object
    |--------------------------------------------------------------------------
    */

    $collections[] = [

        'id' =>

            (int) $row['id'],

        'household_id' =>

            $row['household_id'],

        'beneficiary_name' =>

            $beneficiaryName,

        'caregiver_name' =>

            $row['caregiver_name'],

        'alternative_name' =>

            $row['alternative_name'],

        'collector_name' =>

            $row['collector_name'],

        'collector_role' =>

            $row['collector_role'],

        'collector_img' =>

            $row['collector_img'],

        'agent_id' =>

            $row['agent_id'],

        'agent_name' =>

            $row['agent_name'],

        'tech_pay' =>

            $row['tech_pay'],

        'state' =>

            $row['state'],

        'lga' =>

            $row['lga'],

        'ward' =>

            $row['ward'],

        'community' =>

            $row['community'],

        'latitude' =>

            $row['lat'],

        'longitude' =>

            $row['log'],

        'org_id' =>

            $row['org_id'],

        'pro_id' =>

            $row['pro_id'],

        'date_created' =>

            $row['date_created']

    ];

}

/*
|--------------------------------------------------------------------------
| CLEANUP
|--------------------------------------------------------------------------
*/

mysqli_stmt_close($stmt);

/*
|--------------------------------------------------------------------------
| OPTIONAL TOTAL COUNT
|--------------------------------------------------------------------------
*/

$includeTotal = filter_var(

    $_GET['include_total'] ?? false,

    FILTER_VALIDATE_BOOLEAN

);

/*
|--------------------------------------------------------------------------
| RESPONSE
|--------------------------------------------------------------------------
*/

$response = [

    'success' => true,

    'cached' => false,

    'data' =>

        $collections,

    'pagination' => [

        'limit' =>

            $limit,

        'returned' =>

            count($collections),

        'has_more' =>

            $hasMore,

        'next_cursor' =>

            $nextCursor

    ],

    'filters' => [

        'household_id' =>

            $household_id,

        'beneficiary_name' =>

            $beneficiary_name,

        'collector_name' =>

            $collector_name,

        'collector_role' =>

            $collector_role,

        'agent_id' =>

            $agent_id,

        'org_id' =>

            $org_id,

        'pro_id' =>

            $pro_id,

        'state' =>

            $state,

        'lga' =>

            $lga,

        'ward' =>

            $ward,

        'community' =>

            $community,

        'tech_pay' =>

            $tech_pay,

        'date_from' =>

            $date_from,

        'date_to' =>

            $date_to

    ],

    'generated_at' =>

        date('Y-m-d H:i:s')

];

/*
|--------------------------------------------------------------------------
| TOTAL COUNT
|--------------------------------------------------------------------------
*/

if ($includeTotal) {

    $response['pagination']['total'] = $total;

}

/*
|--------------------------------------------------------------------------
| PERFORMANCE METRICS
|--------------------------------------------------------------------------
*/

$response['performance'] = [

    'cache' =>

        false,

    'cursor' =>

        $cursor,

    'execution_time_ms' =>

        round(

            (

                microtime(true)

                -

                $_SERVER['REQUEST_TIME_FLOAT']

            )

            * 1000,

            2

        ),

    'memory_mb' =>

        round(

            memory_get_peak_usage(true)

            /

            1048576,

            2

        )

];

/*
|--------------------------------------------------------------------------
| CACHE RESPONSE
|--------------------------------------------------------------------------
*/

cachePut(

    $cacheKey,

    $response,

    300

);

/*
|--------------------------------------------------------------------------
| RETURN JSON
|--------------------------------------------------------------------------
*/

json_response(

    $response

);