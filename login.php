<?php

require_once __DIR__ . '/core/bootstrap.php';

$data     = json_decode(file_get_contents('php://input'), true);

$email    = $data['email'] ?? null;
$username = $data['username'] ?? null;
$password = $data['password'] ?? null;

if (!$password) {
    json_response(['error' => 'Password is required'], 422);
}

$conn = db();

// -----------------------------------------------
// AGENT LOGIN — username + password (plain text)
// -----------------------------------------------
if ($username && !$email) {

    $stmt = mysqli_prepare($conn, "SELECT * FROM agent WHERE agent_username = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "s", $username);
    mysqli_stmt_execute($stmt);
    $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$user || $user['agent_pass'] !== $password) {
        log_warning('auth', 'Failed agent login', ['username' => $username]);
        json_response(['error' => 'Invalid credentials'], 401);
    }

    // Fetch single active project assignment
    $stmt2 = mysqli_prepare($conn, "SELECT pro_id FROM agent_pro_assignment WHERE agent_id = ? AND status = 'active' LIMIT 1");
    mysqli_stmt_bind_param($stmt2, "i", $user['agent_id']);
    mysqli_stmt_execute($stmt2);
    $result2 = mysqli_stmt_get_result($stmt2);
    $assignment = mysqli_fetch_assoc($result2);

    $payload = [
        'id'     => $user['agent_id'],
        'name'   => $user['agent_name'],
        'org_id' => $user['org_id'],
        'pro_id' => $assignment['pro_id'] ?? null,
        'role'   => 'agent'
    ];

    log_info('auth', 'Agent logged in', ['agent_id' => $user['agent_id']]);

    json_response([
        'token'         => jwt_encode($payload),
        'refresh_token' => jwt_refresh_encode($payload),
        'user'          => $payload
    ]);
}

// -----------------------------------------------
// EMAIL LOGIN — admin / supervisor / organisation (hashed)
// -----------------------------------------------
if ($email && !$username) {

    // Check admin
    $stmt = mysqli_prepare($conn, "SELECT * FROM admin WHERE email = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
    $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if ($user && password_verify($password, $user['password'])) {
        $payload = [
            'id'    => $user['id'],
            'email' => $user['email'],
            'name'  => $user['firstName'] . ' ' . $user['lastName'],
            'role'  => 'admin'
        ];

        log_info('auth', 'Admin logged in', ['email' => $email]);

        json_response([
            'token'         => jwt_encode($payload),
            'refresh_token' => jwt_refresh_encode($payload),
            'user'          => $payload
        ]);
    }

    // Check supervisor
   $stmt = mysqli_prepare($conn, "SELECT * FROM supervisor WHERE email = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "s", $email);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if ($user && password_verify($password, $user['password'])) {

    // Fetch all active project assignments
    $stmt2 = mysqli_prepare($conn, "SELECT pro_id FROM supervisor_pro_assignment WHERE supervisor_id = ? AND status = 'active'");
    mysqli_stmt_bind_param($stmt2, "i", $user['id']);
    mysqli_stmt_execute($stmt2);
    $result2 = mysqli_stmt_get_result($stmt2);

    $projects = [];
    while ($row = mysqli_fetch_assoc($result2)) {
        $projects[] = $row['pro_id'];
    }

    $payload = [
        'id'       => $user['id'],
        'email'    => $user['email'],
        'name'     => $user['firstName'] . ' ' . $user['lastName'],
        'org_id'   => $user['org_id'],
        'projects' => $projects, // array of active pro_ids
        'role'     => 'supervisor'
    ];

    log_info('auth', 'Supervisor logged in', ['email' => $email]);

    json_response([
        'token'         => jwt_encode($payload),
        'refresh_token' => jwt_refresh_encode($payload),
        'user'          => $payload
    ]);
}

    // Check organisation
    $stmt = mysqli_prepare($conn, "SELECT * FROM organisations WHERE email = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
    $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if ($user && password_verify($password, $user['org_pass'])) {
        $payload = [
            'id'     => $user['id'],
            'email'  => $user['email'],
            'name'   => $user['org_name'],
            'org_id' => $user['org_id'],
            'role'   => 'organisation'
        ];

        log_info('auth', 'Organisation logged in', ['email' => $email]);

        json_response([
            'token'         => jwt_encode($payload),
            'refresh_token' => jwt_refresh_encode($payload),
            'user'          => $payload
        ]);
    }

    log_warning('auth', 'Failed email login', ['email' => $email]);
    json_response(['error' => 'Invalid credentials'], 401);
}

json_response(['error' => 'Provide email or username with password'], 422);