<?php

require_once __DIR__ . '/../../core/bootstrap.php';

// -------------------------------------------------
// AUTHENTICATION
// -------------------------------------------------

$token = get_bearer_token();

if (!$token) {
    json_response(['error' => 'Unauthorized'], 401);
}

$decoded = jwt_decode($token);

if (!$decoded) {
    json_response(['error' => 'Invalid token'], 401);
}

$conn = db();

// -------------------------------------------------
// CACHE
// -------------------------------------------------

$cacheKey = 'admin:organisations:list';

$data = cacheRemember($cacheKey, function () use ($conn) {

    $sql = "

    SELECT

        o.org_id,
        o.org_name,
        o.date_created,

        IFNULL(s.total_supervisors,0) AS supervisors,

        IFNULL(c.total_beneficiaries,0) AS beneficiaries

    FROM organisations o

    LEFT JOIN

    (

        SELECT

            org_id,

            COUNT(DISTINCT supervisor_id) AS total_supervisors

        FROM supervisor_pro_assignment

        WHERE status = 'active'

        GROUP BY org_id

    ) s

        ON s.org_id = o.org_id

    LEFT JOIN

    (

        SELECT

            org_id,

            COUNT(*) AS total_beneficiaries

        FROM community_user

        GROUP BY org_id

    ) c

        ON c.org_id = o.org_id

    ORDER BY o.org_name ASC

    ";

    $result = mysqli_query($conn, $sql);

    if (!$result) {
        throw new Exception(mysqli_error($conn));
    }

    $organisations = [];

    while ($row = mysqli_fetch_assoc($result)) {

        $organisations[] = [

            'org_id' => (int)$row['org_id'],

            'organisation' => $row['org_name'],

            'supervisors' => (int)$row['supervisors'],

            'beneficiaries' => (int)$row['beneficiaries'],

            'created' => $row['date_created']

        ];

    }

    return [

        'total' => count($organisations),

        'organisations' => $organisations

    ];

}, 300);

// -------------------------------------------------
// RESPONSE
// -------------------------------------------------

json_response($data);