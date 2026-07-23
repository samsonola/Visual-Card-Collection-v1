<?php

// --------------------------------------------
// CORS
// --------------------------------------------

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type, Accept");
header("Access-Control-Max-Age: 86400");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../core/bootstrap.php';

require_once __DIR__ . '/../../media_service/media.php';



/*
|--------------------------------------------------------------------------
| AUTH
|--------------------------------------------------------------------------
*/

$token = get_bearer_token();

if (!$token) {

    json_response([
        'error' => 'Unauthorized'
    ],401);

}

$decoded = jwt_decode($token);

if (!$decoded){

    json_response([
        'error'=>'Invalid token'
    ],401);

}



$conn = db();



/*
|--------------------------------------------------------------------------
| VALIDATION
|--------------------------------------------------------------------------
*/

$firstName = trim($_POST['firstName'] ?? '');

$lastName = trim($_POST['lastName'] ?? '');

$username = trim($_POST['username'] ?? '');

$email = trim($_POST['email'] ?? '');

$password = trim($_POST['password'] ?? '');

$org_id = (int)($_POST['org_id'] ?? 0);



if(

    $firstName=='' ||

    $lastName=='' ||

    $username=='' ||

    $email=='' ||

    $password=='' ||

    $org_id<=0

){

    json_response([

        'error'=>'Missing required fields'

    ],422);

}



/*
|--------------------------------------------------------------------------
| INSERT SUPERVISOR
|--------------------------------------------------------------------------
*/

mysqli_begin_transaction($conn);

try{

    $firstName=mysqli_real_escape_string($conn,$firstName);

    $lastName=mysqli_real_escape_string($conn,$lastName);

    $username=mysqli_real_escape_string($conn,$username);

    $email=mysqli_real_escape_string($conn,$email);

    $password=password_hash(

        $password,

        PASSWORD_BCRYPT

    );



    $sql="

    INSERT INTO supervisor(

        firstName,

        lastName,

        username,

        email,

        password,

        profile_photo,

        org_id

    )

    VALUES(

        '$firstName',

        '$lastName',

        '$username',

        '$email',

        '$password',

        NULL,

        $org_id

    )

    ";



    if(!mysqli_query($conn,$sql)){

        throw new Exception(

            mysqli_error($conn)

        );

    }



    $supervisorId = mysqli_insert_id($conn);



    mysqli_commit($conn);

}
catch(Exception $e){

    mysqli_rollback($conn);

    json_response([

        'error'=>$e->getMessage()

    ],500);

}



/*
|--------------------------------------------------------------------------
| Queue image
|--------------------------------------------------------------------------
*/

$mediaId = null;

if(

    isset($_FILES['profile_photo']) &&

    $_FILES['profile_photo']['error']==UPLOAD_ERR_OK

){

    try{

        $mediaId = queue_media_upload(

            'supervisor',

            'id',

            $supervisorId,

            'profile_photo',

            'supervisors',

            $_FILES['profile_photo']

        );

    }
    catch(Exception $e){

        media_log(

            $e->getMessage()

        );

    }

}



/*
|--------------------------------------------------------------------------
| RESPONSE
|--------------------------------------------------------------------------
*/

json_response([

    'success'=>true,

    'message'=>'Supervisor created.',

    'supervisor_id'=>$supervisorId,

    'media_id'=>$mediaId,

    'image_status'=>

        $mediaId

        ?

        'queued'

        :

        'no_image'

]);