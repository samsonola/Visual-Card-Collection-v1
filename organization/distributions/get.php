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
    ],401);

}


$decoded = jwt_decode($token);


if (!$decoded) {

    json_response([
        'success'=>false,
        'message'=>'Invalid token'
    ],401);

}


/*
|--------------------------------------------------------------------------
| ORGANISATION ACCESS
|--------------------------------------------------------------------------
*/

if (

    !isset($decoded->role)

    ||

    $decoded->role !== 'organisation'

) {

    json_response([
        'success'=>false,
        'message'=>'Access denied'
    ],403);

}


if (!isset($decoded->org_id)) {

    json_response([
        'success'=>false,
        'message'=>'Organisation not found'
    ],403);

}


$org_id = $decoded->org_id;


$conn = db();



/*
|--------------------------------------------------------------------------
| PAGINATION
|--------------------------------------------------------------------------
*/


$cursor = isset($_GET['cursor'])

    ? max(0,(int)$_GET['cursor'])

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

$agent_id = trim($_GET['agent_id'] ?? '');

$pro_id = trim($_GET['pro_id'] ?? '');

$lga = trim($_GET['lga'] ?? '');

$ward = trim($_GET['ward'] ?? '');

$community = trim($_GET['community'] ?? '');

$collector_role = trim($_GET['role'] ?? '');

$method = trim($_GET['method'] ?? '');

$date_from = trim($_GET['date_from'] ?? '');

$date_to = trim($_GET['date_to'] ?? '');



/*
|--------------------------------------------------------------------------
| CACHE
|--------------------------------------------------------------------------
*/


$cacheKey =

    'org_distributions:' .

    md5(json_encode([

        $org_id,

        $cursor,

        $limit,

        $search,

        $agent_id,

        $pro_id,

        $lga,

        $ward,

        $community,

        $collector_role,

        $method,

        $date_from,

        $date_to

    ]));



$cached = cacheGet($cacheKey);


if ($cached) {

    $cached['cached'] = true;

    json_response($cached);

}



/*
|--------------------------------------------------------------------------
| QUERY
|--------------------------------------------------------------------------
*/


$sql = "

SELECT


tc.id,

tc.household_id,

tc.collector_name,

tc.collector_role,

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


$where = [

    "tc.org_id = ?",

    "tc.id > ?"

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
| Search:
|
| household
| caregiver
| alternative
| agent
|
|--------------------------------------------------------------------------
*/


if ($search !== '') {


    $where[] = "

    (

        tc.household_id LIKE ?

        OR

        cu.caregiver_name LIKE ?

        OR

        cu.alternative_name LIKE ?

        OR

        tc.collector_name LIKE ?

    )

    ";


    $keyword="%{$search}%";


    $params[]=$keyword;

    $params[]=$keyword;

    $params[]=$keyword;

    $params[]=$keyword;


    $types.="ssss";

}



if ($agent_id !== '') {


    $where[]="tc.agent_id=?";

    $params[]=$agent_id;

    $types.="s";

}



if ($pro_id !== '') {


    $where[]="tc.pro_id=?";

    $params[]=$pro_id;

    $types.="s";

}



if ($lga !== '') {


    $where[]="tc.lga=?";

    $params[]=$lga;

    $types.="s";

}



if ($ward !== '') {


    $where[]="tc.ward=?";

    $params[]=$ward;

    $types.="s";

}



if ($community !== '') {


    $where[]="tc.community=?";

    $params[]=$community;

    $types.="s";

}



if ($collector_role !== '') {


    $where[]="tc.collector_role=?";

    $params[]=$collector_role;

    $types.="s";

}



if ($method !== '') {


    $where[]="tc.tech_pay=?";

    $params[]=$method;

    $types.="s";

}



/*
|--------------------------------------------------------------------------
| DATE FILTER
|--------------------------------------------------------------------------
*/

if ($date_from !== '') {


    $where[]="tc.date_created >= ?";

    $params[]=$date_from." 00:00:00";

    $types.="s";

}


if ($date_to !== '') {


    $where[]="tc.date_created <= ?";

    $params[]=$date_to." 23:59:59";

    $types.="s";

}



/*
|--------------------------------------------------------------------------
| WHERE
|--------------------------------------------------------------------------
*/


$sql .= "

WHERE

"

.implode(

" AND ",

$where

);



/*
|--------------------------------------------------------------------------
| LIMIT +1
|--------------------------------------------------------------------------
*/


$sql .= "

ORDER BY tc.id ASC

LIMIT ?

";


$params[]=$limit+1;

$types.="i";



$stmt=mysqli_prepare(

$conn,

$sql

);



if(!$stmt){

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


$result=mysqli_stmt_get_result($stmt);



/*
|--------------------------------------------------------------------------
| BUILD RESPONSE
|--------------------------------------------------------------------------
*/


$data=[];

$hasMore=false;

$nextCursor=null;



while($row=mysqli_fetch_assoc($result)){


    if(count($data)>=$limit){

        $hasMore=true;

        $nextCursor=(int)$row['id'];

        break;

    }


    $beneficiary =

        $row['caregiver_name']

        ??

        $row['alternative_name'];



    $data[]=[


        'id'=>(int)$row['id'],


        'agent_name'=>

            $row['agent_name'],


        'beneficiary'=>

            $beneficiary,


        'household_id'=>

            $row['household_id'],


        'role'=>

            $row['collector_role'],


        'method'=>

            $row['tech_pay'],


        'location'=>[

            'latitude'=>$row['lat'],

            'longitude'=>$row['log']

        ],


        'status'=>

            'Paid',


        'state'=>

            $row['state'],


        'lga'=>

            $row['lga'],


        'ward'=>

            $row['ward'],


        'community'=>

            $row['community'],


        'date_created'=>

            $row['date_created']

    ];

}


mysqli_stmt_close($stmt);



$response=[


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

        'agent_id'=>$agent_id,

        'pro_id'=>$pro_id,

        'lga'=>$lga,

        'ward'=>$ward,

        'community'=>$community,

        'role'=>$collector_role,

        'method'=>$method,

        'date_from'=>$date_from,

        'date_to'=>$date_to

    ]

];



cachePut(

$cacheKey,

$response,

300

);



json_response($response);