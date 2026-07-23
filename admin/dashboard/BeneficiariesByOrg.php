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
mysqli_set_charset($conn, "utf8mb4");

// -------------------------------------------------
// CACHE
// -------------------------------------------------

$cacheKey = 'dashboard:organisation_summary';

$data = cacheRemember($cacheKey, function () use ($conn) {

    $sql = "

    SELECT

        o.org_id,
        o.org_name,

        IFNULL(cu.total_beneficiaries,0) AS total_beneficiaries,

        IFNULL(tc.total_distributed,0) AS total_distributed,

        CASE

            WHEN IFNULL(cu.total_beneficiaries,0) = 0 THEN '0%'

            ELSE CONCAT(
                ROUND(
                    (IFNULL(tc.total_distributed,0) * 100.0)
                    / cu.total_beneficiaries,
                    2
                ),
                '%'
            )

        END AS coverage

    FROM organisations o

    LEFT JOIN

    (

        SELECT

            org_id,

            COUNT(*) AS total_beneficiaries

        FROM community_user

        GROUP BY org_id

    ) cu

        ON cu.org_id = o.org_id

    LEFT JOIN

    (

        SELECT

            org_id,

            COUNT(*) AS total_distributed

        FROM tbl_collection

        GROUP BY org_id

    ) tc

        ON tc.org_id = o.org_id

    ORDER BY o.org_name

    ";

    $result = mysqli_query($conn, $sql);

    if (!$result) {

        throw new Exception(mysqli_error($conn));

    }

    $organisations = [];

    while ($row = mysqli_fetch_assoc($result)) {

        $organisations[] = [

            'org_id' => (int)$row['org_id'],

            'org_name' => $row['org_name'],

            'total_beneficiaries' => (int)$row['total_beneficiaries'],

            'total_distributed' => (int)$row['total_distributed'],

            'coverage' => $row['coverage']

        ];

    }

    return [

        'organisations' => $organisations

    ];

}, 300);

// -------------------------------------------------
// RESPONSE
// -------------------------------------------------

json_response($data);