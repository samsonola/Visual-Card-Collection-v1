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


/*
|--------------------------------------------------------------------------
| AUTHORIZE ORGANISATION
|--------------------------------------------------------------------------
*/

if (
    !isset($decoded->role)
    ||
    $decoded->role !== 'organisation'
) {

    json_response([
        'success' => false,
        'message' => 'Access denied'
    ], 403);

}


if (
    !isset($decoded->org_id)
) {

    json_response([
        'success' => false,
        'message' => 'Organisation not found'
    ], 403);

}


$org_id = $decoded->org_id;


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

$search = trim(
    $_GET['search'] ?? ''
);


$status = strtolower(
    trim(
        $_GET['status'] ?? ''
    )
);


$lga = trim(
    $_GET['lga'] ?? ''
);


$ward = trim(
    $_GET['ward'] ?? ''
);


$state = trim(
    $_GET['state'] ?? ''
);


$community = trim(
    $_GET['community'] ?? ''
);


$pro_id = trim(
    $_GET['pro_id'] ?? ''
);



/*
|--------------------------------------------------------------------------
| VALIDATE STATUS
|--------------------------------------------------------------------------
*/

if (

    $status !== ''

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
    ],422);

}



/*
|--------------------------------------------------------------------------
| CACHE KEY
|--------------------------------------------------------------------------
*/

$cacheKey =

    'org_beneficiaries:' .

    md5(

        json_encode([

            $org_id,

            $cursor,

            $limit,

            $search,

            $status,

            $lga,

            $ward,

            $state,

            $community,

            $pro_id

        ])

    );



$cached = cacheGet($cacheKey);


if ($cached) {

    $cached['cached'] = true;

    json_response($cached);

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

    cu.state,

    cu.lga,

    cu.ward,

    cu.alternative_id,

    cu.org_id,

    cu.pro_id,

    cu.date_created

FROM community_user cu

";


$where = [

    "cu.org_id = ?",

    "cu.id > ?"

];


$params = [

    $org_id,

    $cursor

];


$types = "si";



/*
|--------------------------------------------------------------------------
| SEARCH
|--------------------------------------------------------------------------
|
| Searches:
|
| caregiver name
| alternative name
| household id
| NIN
|
*/

if ($search !== '') {


    $where[] = "

    (

        cu.caregiver_name LIKE ?

        OR

        cu.alternative_name LIKE ?

        OR

        cu.household_id LIKE ?

        OR

        cu.alternative_id LIKE ?

    )

    ";


    $keyword = "%{$search}%";


    $params[] = $keyword;
    $params[] = $keyword;
    $params[] = $keyword;
    $params[] = $keyword;


    $types .= "ssss";

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



if ($state !== '') {

    $where[] = "cu.state = ?";

    $params[] = $state;

    $types .= "s";

}



if ($community !== '') {

    $where[] = "cu.community = ?";

    $params[] = $community;

    $types .= "s";

}



if ($pro_id !== '') {

    $where[] = "cu.pro_id = ?";

    $params[] = $pro_id;

    $types .= "s";

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

        AND

            tc.org_id = cu.org_id

        AND

            tc.pro_id = cu.pro_id

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

        AND

            tc.org_id = cu.org_id

        AND

            tc.pro_id = cu.pro_id

    )

    ";


}



$sql .= "

WHERE

" . implode(

    " AND ",

    $where

);



/*
|--------------------------------------------------------------------------
| LIMIT + 1 CURSOR PAGINATION
|--------------------------------------------------------------------------
*/

$sql .= "

ORDER BY cu.id ASC

LIMIT ?

";


$params[] = $limit + 1;

$types .= "i";



$stmt = mysqli_prepare(

    $conn,

    $sql

);



if (!$stmt) {

    json_response([

        'success'=>false,

        'message'=>mysqli_error($conn)

    ],500);

}



mysqli_stmt_bind_param(

    $stmt,

    $types,

    ...$params

);



mysqli_stmt_execute($stmt);



$result = mysqli_stmt_get_result($stmt);



$data = [];

$hasMore = false;

$nextCursor = null;



while (

    $row = mysqli_fetch_assoc($result)

) {


    if (

        count($data) >= $limit

    ) {


        $hasMore = true;

        $nextCursor = (int)$row['id'];

        break;

    }



    $data[] = [

        'id'=>(int)$row['id'],

        'caregiver_name'=>$row['caregiver_name'],

        'household_id'=>$row['household_id'],

        'community'=>$row['community'],

        'state'=>$row['state'],

        'lga'=>$row['lga'],

        'ward'=>$row['ward'],

        'alternative_id'=>$row['alternative_id'],

        'status'=>null,

        'date_created'=>$row['date_created']

    ];

}



mysqli_stmt_close($stmt);



/*
|--------------------------------------------------------------------------
| RESPONSE
|--------------------------------------------------------------------------
*/


$response = [

    'success'=>true,

    'cached'=>false,

    'data'=>$data,

    'pagination'=>[

        'limit'=>$limit,

        'returned'=>count($data),

        'has_more'=>$hasMore,

        'next_cursor'=>$nextCursor

    ],

    'filters'=>[

        'search'=>$search,

        'status'=>$status,

        'lga'=>$lga,

        'ward'=>$ward,

        'state'=>$state,

        'community'=>$community,

        'pro_id'=>$pro_id

    ]

];



cachePut(

    $cacheKey,

    $response,

    300

);



json_response($response);