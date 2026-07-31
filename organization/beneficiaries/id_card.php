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
| ORGANISATION FROM JWT
|--------------------------------------------------------------------------
*/


$org_id = $decoded->org_id ?? null;



if (!$org_id) {

    json_response([

        'success'=>false,

        'message'=>'Organisation not found in token'

    ],403);

}



$conn = db();




/*
|--------------------------------------------------------------------------
| PAGINATION
|--------------------------------------------------------------------------
*/


$cursor = max(

    0,

    (int)($_GET['cursor'] ?? 0)

);



$limit = (int)($_GET['limit'] ?? 50);



if($limit < 1){

    $limit = 50;

}



if($limit > 200){

    $limit = 200;

}




/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/


$name = trim($_GET['name'] ?? '');


$household_id = trim($_GET['household_id'] ?? '');


$pro_id = trim($_GET['pro_id'] ?? '');





/*
|--------------------------------------------------------------------------
| CACHE
|--------------------------------------------------------------------------
*/


$useCache = ($cursor === 0);



$cacheKey =

'beneficiary:id-card:' .

md5(json_encode([


    'limit'=>$limit,


    'name'=>$name,


    'household_id'=>$household_id,


    'org_id'=>$org_id,


    'pro_id'=>$pro_id


]));





if($useCache){


    $cached = cacheGet($cacheKey);



    if(!empty($cached)){


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

        WHEN tc.household_id IS NULL

        THEN 'Not Paid'

        ELSE 'Paid'

    END AS payment_status



FROM community_user cu



LEFT JOIN tbl_collection tc


ON tc.household_id = cu.household_id


AND tc.org_id = cu.org_id


AND tc.pro_id = cu.pro_id



";





$where = [];


$params = [];


$types = "";





/*
|--------------------------------------------------------------------------
| Cursor
|--------------------------------------------------------------------------
*/


$where[] = "cu.id > ?";


$params[] = $cursor;


$types .= "i";





/*
|--------------------------------------------------------------------------
| ORGANISATION FILTER
|--------------------------------------------------------------------------
*/


$where[] = "cu.org_id = ?";


$params[] = $org_id;


$types .= "s";





/*
|--------------------------------------------------------------------------
| SEARCH NAME
|--------------------------------------------------------------------------
*/


if($name !== ''){


    $where[] = "

    cu.caregiver_name LIKE ?

    ";


    $params[] = "%{$name}%";


    $types .= "s";


}





/*
|--------------------------------------------------------------------------
| HOUSEHOLD SEARCH
|--------------------------------------------------------------------------
*/


if($household_id !== ''){


    $where[] = "cu.household_id = ?";


    $params[] = $household_id;


    $types .= "s";


}





/*
|--------------------------------------------------------------------------
| PROJECT FILTER
|--------------------------------------------------------------------------
*/


if($pro_id !== ''){


    $where[] = "cu.pro_id = ?";


    $params[] = $pro_id;


    $types .= "s";


}

/*
|--------------------------------------------------------------------------
| BUILD WHERE
|--------------------------------------------------------------------------
*/


if(!empty($where)){


    $sql .= "

    WHERE

    " . implode(

        "

        AND

        ",

        $where

    );


}



/*
|--------------------------------------------------------------------------
| ORDER + LIMIT
|--------------------------------------------------------------------------
*/


$sql .= "

ORDER BY

    cu.id ASC


LIMIT ?

";



$params[] = $limit + 1;


$types .= "i";





/*
|--------------------------------------------------------------------------
| PREPARE
|--------------------------------------------------------------------------
*/


$stmt = mysqli_prepare(

    $conn,

    $sql

);



if(!$stmt){


    json_response([

        'success'=>false,

        'message'=>'Unable to prepare query',

        'error'=>mysqli_error($conn)

    ],500);


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


if(!mysqli_stmt_execute($stmt)){


    json_response([

        'success'=>false,

        'message'=>'Unable to execute query',

        'error'=>mysqli_stmt_error($stmt)

    ],500);


}





$result = mysqli_stmt_get_result($stmt);





/*
|--------------------------------------------------------------------------
| FORMAT DATA
|--------------------------------------------------------------------------
*/


$rows = [];




while($row = mysqli_fetch_assoc($result)){



    if(count($rows) >= $limit){

        break;

    }




    /*
    |--------------------------------------------------------------------------
    | Initials
    |--------------------------------------------------------------------------
    */


    $initials = '';



    if(!empty($row['caregiver_name'])){


        $parts = preg_split(

            '/\s+/',

            trim($row['caregiver_name'])

        );



        foreach($parts as $part){


            $initials .= strtoupper(

                substr($part,0,1)

            );



            if(strlen($initials) >= 2){

                break;

            }


        }


    }





    $rows[] = [



        'id'=>

            (int)$row['id'],



        'initials'=>

            $initials,



        'caregiver_name'=>

            $row['caregiver_name'],



        'household_id'=>

            $row['household_id'],



        'community'=>

            $row['community'],



        'status'=>

            $row['payment_status'],



        'org_id'=>

            $row['org_id'],



        'pro_id'=>

            $row['pro_id']



    ];



}





mysqli_stmt_close($stmt);






/*
|--------------------------------------------------------------------------
| CURSOR PAGINATION
|--------------------------------------------------------------------------
*/


$hasMore = count($rows) > $limit;



if($hasMore){


    array_pop($rows);


}



$nextCursor = null;



if($hasMore && !empty($rows)){


    $last = end($rows);


    $nextCursor = (int)$last['id'];


}






/*
|--------------------------------------------------------------------------
| RESPONSE
|--------------------------------------------------------------------------
*/


$response = [


    'success'=>true,


    'cached'=>false,



    'data'=>$rows,



    'pagination'=>[


        'limit'=>$limit,


        'returned'=>count($rows),


        'has_more'=>$hasMore,


        'next_cursor'=>$nextCursor


    ],



    'filters'=>[


        'name'=>$name,


        'household_id'=>$household_id,


        'org_id'=>$org_id,


        'pro_id'=>$pro_id


    ],



    'generated_at'=>date(

        'Y-m-d H:i:s'

    )


];






/*
|--------------------------------------------------------------------------
| CACHE FIRST PAGE
|--------------------------------------------------------------------------
*/


if($useCache){


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