<?php

declare(strict_types=1);

require_once __DIR__ . '/core/bootstrap.php';


/*
|--------------------------------------------------------------------------
| AUTHENTICATION
|--------------------------------------------------------------------------
*/

$token = get_bearer_token();

if (!$token) {

    json_response([
        'success'=>false,
        'message'=>'Unauthorized'
    ],401);

}


$decoded = jwt_decode($token);


if (!$decoded) {

    json_response([
        'success'=>false,
        'message'=>'Invalid token'
    ],401);

}


$conn = db();



/*
|--------------------------------------------------------------------------
| JWT DATA
|--------------------------------------------------------------------------
*/

$userId = $decoded->id ?? null;

$role = $decoded->role ?? null;


if (!$userId || !$role) {

    json_response([
        'success'=>false,
        'message'=>'Invalid user payload'
    ],401);

}



/*
|--------------------------------------------------------------------------
| PROJECT PAGINATION
|--------------------------------------------------------------------------
*/

$projectCursor = max(
    0,
    (int)($_GET['project_cursor'] ?? 0)
);


$projectLimit = (int)(
    $_GET['project_limit'] ?? 20
);


if ($projectLimit < 1) {

    $projectLimit = 20;

}


if ($projectLimit > 100) {

    $projectLimit = 100;

}



/*
|--------------------------------------------------------------------------
| CACHE KEY
|--------------------------------------------------------------------------
*/

$cacheKey = 'user_details:' 
    .$role
    .':'
    .$userId
    .':'
    .$projectCursor
    .':'
    .$projectLimit;



$cached = cacheGet($cacheKey);


if (!empty($cached)) {

    $cached['cached'] = true;

    json_response($cached);

}



/*
|--------------------------------------------------------------------------
| RESPONSE
|--------------------------------------------------------------------------
*/

$response = [

    'success'=>true,

    'cached'=>false,

    'user'=>null

];



/*
|--------------------------------------------------------------------------
| ORGANISATION
|--------------------------------------------------------------------------
*/

if ($role === 'organisation') {



    /*
    |--------------------------------------------------------------------------
    | Get Organisation
    |--------------------------------------------------------------------------
    */


    $stmt = mysqli_prepare(
        $conn,
        "
        SELECT

            id,
            org_name,
            org_slug,
            org_id,
            callback_url,
            email,
            date_created

        FROM organisations

        WHERE id = ?

        LIMIT 1
        "
    );


    mysqli_stmt_bind_param(
        $stmt,
        "i",
        $userId
    );


    mysqli_stmt_execute($stmt);


    $org = mysqli_fetch_assoc(
        mysqli_stmt_get_result($stmt)
    );


    mysqli_stmt_close($stmt);



    if (!$org){

        json_response([
            'success'=>false,
            'message'=>'Organisation not found'
        ],404);

    }



    /*
    |--------------------------------------------------------------------------
    | Organisation ID FROM DATABASE
    |--------------------------------------------------------------------------
    */

    $orgId = $org['org_id'];



    /*
    |--------------------------------------------------------------------------
    | PROJECTS PAGINATION
    |--------------------------------------------------------------------------
    */


    $projectStmt = mysqli_prepare(
        $conn,
        "
        SELECT

            id,
            pro_id,
            pro_name,
            pro_slug,
            date_created

        FROM organisations_project

        WHERE org_id = ?

        AND id > ?

        ORDER BY id ASC

        LIMIT ?

        "
    );


    if(!$projectStmt){

        json_response([
            'success'=>false,
            'message'=>mysqli_error($conn)
        ],500);

    }



    $projectLimitPlusOne = $projectLimit + 1;



    mysqli_stmt_bind_param(
        $projectStmt,
        "sii",
        $orgId,
        $projectCursor,
        $projectLimitPlusOne
    );


    mysqli_stmt_execute($projectStmt);


    $projectResult = mysqli_stmt_get_result(
        $projectStmt
    );



    $projects = [];

    $hasMoreProjects = false;

    $nextProjectCursor = null;



    while(
        $project = mysqli_fetch_assoc($projectResult)
    ){

        if(count($projects) >= $projectLimit){

            $hasMoreProjects = true;

            $nextProjectCursor = (int)$project['id'];

            break;

        }


        $projects[] = [

            'id'=>(int)$project['id'],

            'pro_id'=>$project['pro_id'],

            'pro_name'=>$project['pro_name'],

            'pro_slug'=>$project['pro_slug'],

            'date_created'=>$project['date_created']

        ];

    }


    mysqli_stmt_close($projectStmt);

        /*
    |--------------------------------------------------------------------------
    | ORGANISATION STATISTICS
    |--------------------------------------------------------------------------
    */


    $stats = [];



    /*
    |--------------------------------------------------------------------------
    | TOTAL BENEFICIARIES
    |--------------------------------------------------------------------------
    */

    $stmt = mysqli_prepare(
        $conn,
        "
        SELECT COUNT(*) total

        FROM community_user

        WHERE org_id = ?

        "
    );


    mysqli_stmt_bind_param(
        $stmt,
        "s",
        $orgId
    );


    mysqli_stmt_execute($stmt);


    $stats['beneficiaries'] = (int)
        mysqli_fetch_assoc(
            mysqli_stmt_get_result($stmt)
        )['total'];


    mysqli_stmt_close($stmt);




    /*
    |--------------------------------------------------------------------------
    | PAID BENEFICIARIES
    |--------------------------------------------------------------------------
    */

    $stmt = mysqli_prepare(
        $conn,
        "
        SELECT COUNT(*) total

        FROM community_user cu

        WHERE cu.org_id = ?

        AND EXISTS (

            SELECT 1

            FROM tbl_collection tc

            WHERE tc.household_id = cu.household_id

            AND tc.org_id = cu.org_id

            AND tc.pro_id = cu.pro_id

        )

        "
    );


    mysqli_stmt_bind_param(
        $stmt,
        "s",
        $orgId
    );


    mysqli_stmt_execute($stmt);


    $stats['paid'] = (int)
        mysqli_fetch_assoc(
            mysqli_stmt_get_result($stmt)
        )['total'];


    mysqli_stmt_close($stmt);




    /*
    |--------------------------------------------------------------------------
    | UNPAID BENEFICIARIES
    |--------------------------------------------------------------------------
    */

    $stats['unpaid'] =

        $stats['beneficiaries']

        -

        $stats['paid'];





    /*
    |--------------------------------------------------------------------------
    | TOTAL AGENTS
    |--------------------------------------------------------------------------
    */

    $stmt = mysqli_prepare(
        $conn,
        "
        SELECT COUNT(*) total

        FROM agent

        WHERE org_id = ?

        "
    );


    mysqli_stmt_bind_param(
        $stmt,
        "s",
        $orgId
    );


    mysqli_stmt_execute($stmt);


    $stats['agents'] = (int)
        mysqli_fetch_assoc(
            mysqli_stmt_get_result($stmt)
        )['total'];


    mysqli_stmt_close($stmt);






    /*
    |--------------------------------------------------------------------------
    | ORGANISATION RESPONSE
    |--------------------------------------------------------------------------
    */


    $response['user'] = [

        'id'=>(int)$org['id'],

        'name'=>$org['org_name'],

        'email'=>$org['email'],

        'org_id'=>$org['org_id'],

        'role'=>'organisation'

    ];




    $response['organisation'] = [

        'slug'=>$org['org_slug'],

        'callback_url'=>$org['callback_url'],

        'created_at'=>$org['date_created']

    ];





    $response['projects'] = [

        'data'=>$projects,


        'pagination'=>[

            'limit'=>$projectLimit,

            'returned'=>count($projects),

            'has_more'=>$hasMoreProjects,

            'next_cursor'=>$nextProjectCursor

        ]

    ];





    $response['stats'] = $stats;



}







/*
|--------------------------------------------------------------------------
| AGENT
|--------------------------------------------------------------------------
*/


elseif ($role === 'agent') {


    $response['user'] = [

        'id'=>(int)$decoded->id,

        'name'=>$decoded->name ?? null,

        'org_id'=>$decoded->org_id ?? null,

        'pro_id'=>$decoded->pro_id ?? null,

        'role'=>'agent'

    ];


}







/*
|--------------------------------------------------------------------------
| SUPERVISOR
|--------------------------------------------------------------------------
*/


elseif ($role === 'supervisor') {


    $response['user'] = [

        'id'=>(int)$decoded->id,

        'name'=>$decoded->name ?? null,

        'email'=>$decoded->email ?? null,

        'org_id'=>$decoded->org_id ?? null,

        'projects'=>$decoded->projects ?? [],

        'role'=>'supervisor'

    ];


}







/*
|--------------------------------------------------------------------------
| ADMIN
|--------------------------------------------------------------------------
*/


elseif ($role === 'admin') {


    $response['user'] = [

        'id'=>(int)$decoded->id,

        'name'=>$decoded->name ?? null,

        'email'=>$decoded->email ?? null,

        'role'=>'admin'

    ];


}






else {


    json_response([

        'success'=>false,

        'message'=>'Unsupported role'

    ],403);


}







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
| OUTPUT
|--------------------------------------------------------------------------
*/


json_response($response);

exit;