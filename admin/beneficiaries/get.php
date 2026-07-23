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
| Example:
|
| cursor=1500
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

$name = trim(

    $_GET['name'] ?? ''

);

$household_id = trim(

    $_GET['household_id'] ?? ''

);

$alternative_id = trim(

    $_GET['alternative_id'] ?? ''

);

$org_id = trim(

    $_GET['org_id'] ?? ''

);

$pro_id = trim(

    $_GET['pro_id'] ?? ''

);

$state = trim(

    $_GET['state'] ?? ''

);

$lga = trim(

    $_GET['lga'] ?? ''

);

$ward = trim(

    $_GET['ward'] ?? ''

);

$community = trim(

    $_GET['community'] ?? ''

);

$status = strtolower(

    trim(

        $_GET['status'] ?? ''

    )

);

/*
|--------------------------------------------------------------------------
| VALIDATE STATUS
|--------------------------------------------------------------------------
*/

if (

    !empty($status)

    &&

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
| CACHE KEY
|--------------------------------------------------------------------------
*/

$cacheKey =

    'beneficiaries:' .

    md5(

        json_encode([

            'cursor' => $cursor,

            'limit' => $limit,

            'name' => $name,

            'household_id' => $household_id,

            'alternative_id' => $alternative_id,

            'org_id' => $org_id,

            'pro_id' => $pro_id,

            'state' => $state,

            'lga' => $lga,

            'ward' => $ward,

            'community' => $community,

            'status' => $status

        ])

    );

/*
|--------------------------------------------------------------------------
| CACHE
|--------------------------------------------------------------------------
*/

$cached = cacheGet(

    $cacheKey

);

if (

    !empty(

        $cached

    )

) {

    $cached['cached'] = true;

    json_response(

        $cached

    );

}

/*
|--------------------------------------------------------------------------
| SQL BUILDER
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

$where[] =

    "cu.id > ?";

$params[] =

    $cursor;

$types .=

    "i";

/*
|--------------------------------------------------------------------------
| SEARCH
|--------------------------------------------------------------------------
*/

if (

    $name !== ''

) {

    $where[] =

        "(

            cu.caregiver_name LIKE ?

            OR

            cu.alternative_name LIKE ?

        )";

    $keyword =

        "%{$name}%";

    $params[] =

        $keyword;

    $params[] =

        $keyword;

    $types .=

        "ss";

}

if (

    $household_id !== ''

) {

    $where[] =

        "cu.household_id = ?";

    $params[] =

        $household_id;

    $types .=

        "s";

}

if (

    $alternative_id !== ''

) {

    $where[] =

        "cu.alternative_id = ?";

    $params[] =

        $alternative_id;

    $types .=

        "s";

}

if (

    $org_id !== ''

) {

    $where[] =

        "cu.org_id = ?";

    $params[] =

        $org_id;

    $types .=

        "s";

}


if (

    $pro_id !== ''

) {

    $where[] =

        "cu.pro_id = ?";

    $params[] =

        $pro_id;

    $types .=

        "s";

}

if (

    $state !== ''

) {

    $where[] =

        "cu.state = ?";

    $params[] =

        $state;

    $types .=

        "s";

}

if (

    $lga !== ''

) {

    $where[] =

        "cu.lga = ?";

    $params[] =

        $lga;

    $types .=

        "s";

}

if (

    $ward !== ''

) {

    $where[] =

        "cu.ward = ?";

    $params[] =

        $ward;

    $types .=

        "s";

}

if (

    $community !== ''

) {

    $where[] =

        "cu.community = ?";

    $params[] =

        $community;

    $types .=

        "s";

}




/*
|--------------------------------------------------------------------------
| PAID / NOT PAID FILTER
|--------------------------------------------------------------------------
|
| Uses EXISTS instead of JOIN.
| This allows MySQL to use the existing indexes:
|
| tbl_collection
|   UNIQUE(household_id, org_id, pro_id)
|
*/

if (

    $status === 'paid'

) {

    $where[] =

        "

        EXISTS (

            SELECT 1

            FROM tbl_collection tc

            WHERE

                tc.household_id = cu.household_id

            AND

                tc.org_id = cu.org_id

            AND

                tc.pro_id = cu.pro_id

        )

        ";

}

elseif (

    $status === 'not_paid'

) {

    $where[] =

        "

        NOT EXISTS (

            SELECT 1

            FROM tbl_collection tc

            WHERE

                tc.household_id = cu.household_id

            AND

                tc.org_id = cu.org_id

            AND

                tc.pro_id = cu.pro_id

        )

        ";

}

/*
|--------------------------------------------------------------------------
| BUILD WHERE CLAUSE
|--------------------------------------------------------------------------
*/

if (

    !empty(

        $where

    )

) {

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
|
| 
|
| Cache separately for one hour.
|
*/

$countCacheKey =

    'beneficiaries:count:'

    .

    md5(

        json_encode([

            $name,

            $household_id,

            $alternative_id,

            $org_id,

            $pro_id,

            $state,

            $lga,

            $ward,

            $community,

            $status

        ])

    );

$total =

    cacheGet(

        $countCacheKey

    );

if (

    $total === null

) {

    $countSql =

        "

        SELECT

            COUNT(*) total

        FROM community_user cu

        ";

    if (

        !empty(

            $where

        )

    ) {

        /*
        |--------------------------------------------------------------------------
        | Remove Cursor Condition
        |--------------------------------------------------------------------------
        |
        | Cursor should NEVER affect the total count.
        |
        */

        $countWhere =

            $where;

        array_shift(

            $countWhere

        );

        if (

            !empty(

                $countWhere

            )

        ) {

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

    $stmt =

        mysqli_prepare(

            $conn,

            $countSql

        );

    /*
    |--------------------------------------------------------------------------
    | Bind Count Parameters
    |--------------------------------------------------------------------------
    */

    if (

        count(

            $params

        )

        >

        1

    ) {

        $countTypes =

            substr(

                $types,

                1

            );

        $countParams =

            array_slice(

                $params,

                1

            );

        mysqli_stmt_bind_param(

            $stmt,

            $countTypes,

            ...

            $countParams

        );

    }

    mysqli_stmt_execute(

        $stmt

    );

    $result =

        mysqli_stmt_get_result(

            $stmt

        );

    $total =

        (int)

        mysqli_fetch_assoc(

            $result

        )['total'];

    mysqli_stmt_close(

        $stmt

    );

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
|
| LIMIT + 1
|
| Fetch one extra record so we know whether another
| page exists without performing another query.
|
*/

$sql .=

    "

    ORDER BY

        cu.id ASC

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

$stmt =

    mysqli_prepare(

        $conn,

        $sql

    );

if (

    !$stmt

) {

    json_response([

        'success' => false,

        'message' => mysqli_error(

            $conn

        )

    ],500);

}

mysqli_stmt_bind_param(

    $stmt,

    $types,

    ...

    $params

);

mysqli_stmt_execute(

    $stmt

);

$result =

    mysqli_stmt_get_result(

        $stmt

    );



/*
|--------------------------------------------------------------------------
| FETCH RESULTS
|--------------------------------------------------------------------------
*/

$beneficiaries = [];

$hasMore = false;

$nextCursor = null;

while (

    $row =

    mysqli_fetch_assoc(

        $result

    )

) {

    /*
    |--------------------------------------------------------------------------
    | LIMIT + 1
    |--------------------------------------------------------------------------
    */

    if (

        count(

            $beneficiaries

        )

        >=

        $limit

    ) {

        $hasMore = true;

        $nextCursor =

            (int)

            $row['id'];

        break;

    }

    /*
    |--------------------------------------------------------------------------
    | Response Object
    |--------------------------------------------------------------------------
    */

    $beneficiaries[] = [

        'id' =>

            (int)

            $row['id'],

        'household_id' =>

            $row['household_id'],

        'caregiver_name' =>

            $row['caregiver_name'],

        'alternative_name' =>

            $row['alternative_name'],

        'alternative_id' =>

            $row['alternative_id'],

        'community' =>

            $row['community'],

        'ward' =>

            $row['ward'],

        'lga' =>

            $row['lga'],

        'state' =>

            $row['state'],

        'org_id' =>

            $row['org_id'],

        'pro_id' =>

            $row['pro_id'],

        'amount' =>

            $row['amount'],

        'date_created' =>

            $row['date_created']

    ];

}

/*
|--------------------------------------------------------------------------
| Cleanup
|--------------------------------------------------------------------------
*/

mysqli_stmt_close(

    $stmt

);

/*
|--------------------------------------------------------------------------
| Pagination Metadata
|--------------------------------------------------------------------------
*/

$response = [

    'success' => true,

    'cached' => false,

    'data' =>

        $beneficiaries,

    'pagination' => [

        'limit' =>

            $limit,

        'returned' =>

            count(

                $beneficiaries

            ),

        'total' =>

            $total,

        'has_more' =>

            $hasMore,

        'next_cursor' =>

            $nextCursor

    ],

    'filters' => [

        'name' =>

            $name,

        'household_id' =>

            $household_id,

        'alternative_id' =>

            $alternative_id,

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

        'status' =>

            $status

    ],

    'generated_at' =>

        date(

            'Y-m-d H:i:s'

        )

];

/*
|--------------------------------------------------------------------------
| Cache Result
|--------------------------------------------------------------------------
|
| Beneficiary data changes infrequently.
|
| Cache each cursor/filter combination separately.
|
*/

cachePut(

    $cacheKey,

    $response,

    300

);

/*
|--------------------------------------------------------------------------
| Return JSON
|--------------------------------------------------------------------------
*/

json_response(

    $response

);

/*
|--------------------------------------------------------------------------
| OPTIONAL TOTAL COUNT
|--------------------------------------------------------------------------
|
| Computing COUNT(*) on millions of rows is relatively expensive.
| The frontend should only request it when required.
|
| Example:
|
| /beneficiaries/get.php?include_total=true
|
*/

$includeTotal =

    filter_var(

        $_GET['include_total']

        ??

        false,

        FILTER_VALIDATE_BOOLEAN

    );

if (

    !$includeTotal

) {

    unset(

        $response['pagination']['total']

    );

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
|
| Beneficiaries rarely change.
|
| Five minutes is a good compromise.
|
*/

cachePut(

    $cacheKey,

    $response,

    300

);

/*
|--------------------------------------------------------------------------
| RESPONSE
|--------------------------------------------------------------------------
*/

json_response(

    $response

);