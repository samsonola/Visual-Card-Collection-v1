<?php

declare(strict_types=1);

require_once __DIR__ . '/../../core/bootstrap.php';

/*
|--------------------------------------------------------------------------
| AUTHENTICATION
|--------------------------------------------------------------------------
*/

$token = get_bearer_token();

if (!$token) {

    json_response([
        'status' => false,
        'message' => 'Unauthorized'
    ], 401);

}

$decoded = jwt_decode($token);

if (!$decoded) {

    json_response([
        'status' => false,
        'message' => 'Invalid token'
    ], 401);

}

/*
|--------------------------------------------------------------------------
| AUTHORISATION
|--------------------------------------------------------------------------
*/

if (($decoded->role ?? '') !== 'supervisor') {

    json_response([
        'status' => false,
        'message' => 'Access denied.'
    ], 403);

}

$supervisorId = (int) ($decoded->id ?? 0);

$orgId = $decoded->org_id ?? null;

$projects = $decoded->projects ?? [];

if (!$orgId) {

    json_response([
        'status' => false,
        'message' => 'Organisation not found.'
    ], 401);

}

if (empty($projects)) {

    json_response([
        'status' => true,
        'message' => 'No assigned projects.',
        'data' => [
            'summary' => [
                'total_agents' => 0,
                'communities_covered' => 0,
                'active_agents' => 0
            ],
            'page' => 1,
            'per_page' => 20,
            'total' => 0,
            'total_pages' => 0,
            'agents' => []
        ]
    ]);

}

$conn = db();

/*
|--------------------------------------------------------------------------
| PAGINATION
|--------------------------------------------------------------------------
*/

$page = max(
    1,
    (int) ($_GET['page'] ?? 1)
);

$limit = max(
    1,
    min(
        100,
        (int) ($_GET['limit'] ?? 20)
    )
);

$offset = ($page - 1) * $limit;

/*
|--------------------------------------------------------------------------
| SEARCH
|--------------------------------------------------------------------------
*/

$search = trim(
    $_GET['search'] ?? ''
);

/*
|--------------------------------------------------------------------------
| PROJECT PLACEHOLDERS
|--------------------------------------------------------------------------
*/

$projectPlaceholders = implode(
    ',',
    array_fill(
        0,
        count($projects),
        '?'
    )
);

$projectTypes = str_repeat(
    's',
    count($projects)
);

/*
|--------------------------------------------------------------------------
| CACHE
|--------------------------------------------------------------------------
*/

$cacheKey = sprintf(

    'supervisor:%d:agents:%d:%d:%s',

    $supervisorId,

    $page,

    $limit,

    md5($search)

);

/*
|--------------------------------------------------------------------------
| CACHE
|--------------------------------------------------------------------------
*/

$data = cacheRemember(

    $cacheKey,

    function () use (

        $conn,

        $orgId,

        $projects,

        $projectPlaceholders,

        $projectTypes,

        $page,

        $limit,

        $offset,

        $search

    ) {

        /*
        |--------------------------------------------------------------------------
        | SUMMARY - TOTAL AGENTS
        |--------------------------------------------------------------------------
        */

        $sql = "

        SELECT

            COUNT(DISTINCT a.id) total

        FROM agent a

        INNER JOIN agent_pro_assignment apa

            ON apa.agent_id = a.id

        WHERE

            a.org_id = ?

        AND apa.org_id = ?

        AND apa.status='active'

        AND apa.unassigned_at IS NULL

        AND apa.pro_id IN ($projectPlaceholders)

        ";

        $stmt = mysqli_prepare($conn, $sql);

        $types = "ss" . $projectTypes;

        $params = array_merge(
            [$orgId, $orgId],
            $projects
        );

        mysqli_stmt_bind_param(
            $stmt,
            $types,
            ...$params
        );

        mysqli_stmt_execute($stmt);

        $totalAgents = (int) mysqli_fetch_assoc(
            mysqli_stmt_get_result($stmt)
        )['total'];

        /*
        |--------------------------------------------------------------------------
        | ACTIVE AGENTS
        |--------------------------------------------------------------------------
        */

        $activeAgents = $totalAgents;

        /*
        |--------------------------------------------------------------------------
        | COMMUNITIES COVERED
        |--------------------------------------------------------------------------
        */

        $sql = "

        SELECT

            COUNT(DISTINCT cu.community) total

        FROM community_user_assignment cua

        INNER JOIN community_user cu

            ON cu.id = cua.community_user_id

        WHERE

            cua.org_id = ?

        AND cua.assignee_type='agent'

        AND cua.status='active'

        AND cua.unassigned_at IS NULL

        AND cua.pro_id IN ($projectPlaceholders)

        ";

        $stmt = mysqli_prepare($conn, $sql);

        $types = "s" . $projectTypes;

        $params = array_merge(
            [$orgId],
            $projects
        );

        mysqli_stmt_bind_param(
            $stmt,
            $types,
            ...$params
        );

        mysqli_stmt_execute($stmt);

        $communitiesCovered = (int) mysqli_fetch_assoc(
            mysqli_stmt_get_result($stmt)
        )['total'];

        /*
        |--------------------------------------------------------------------------
        | SEARCH FILTER
        |--------------------------------------------------------------------------
        */

        $where = "

            a.org_id=?

            AND apa.status='active'

            AND apa.unassigned_at IS NULL

            AND apa.pro_id IN ($projectPlaceholders)

        ";

        $types = "s" . $projectTypes;

        $params = array_merge(
            [$orgId],
            $projects
        );

        if ($search !== '') {

            $where .= "

            AND (

                CONCAT(a.firstName,' ',a.lastName) LIKE ?

                OR a.username LIKE ?

                OR a.email LIKE ?

            )

            ";

            $keyword = "%{$search}%";

            $types .= "sss";

            $params[] = $keyword;
            $params[] = $keyword;
            $params[] = $keyword;

        }

                /*
        |--------------------------------------------------------------------------
        | TOTAL FILTERED
        |--------------------------------------------------------------------------
        */

        $sql = "

        SELECT

            COUNT(DISTINCT a.id) total

        FROM agent a

        INNER JOIN agent_pro_assignment apa

            ON apa.agent_id = a.id

        WHERE {$where}

        ";

        $stmt = mysqli_prepare($conn, $sql);

        mysqli_stmt_bind_param(
            $stmt,
            $types,
            ...$params
        );

        mysqli_stmt_execute($stmt);

        $filteredTotal = (int) mysqli_fetch_assoc(
            mysqli_stmt_get_result($stmt)
        )['total'];

        /*
        |--------------------------------------------------------------------------
        | AGENTS
        |--------------------------------------------------------------------------
        */

        $sql = "

        SELECT

            a.id,

            a.firstName,

            a.lastName,

            a.username,

            a.email,

            a.profile_photo,

            a.date_created,

            GROUP_CONCAT(

                DISTINCT JSON_OBJECT(

                    'pro_id', op.pro_id,

                    'pro_name', op.pro_name

                )

            ) projects,

            COUNT(

                DISTINCT cu.community

            ) communities_assigned

        FROM agent a

        INNER JOIN agent_pro_assignment apa

            ON apa.agent_id = a.id

            AND apa.status='active'

            AND apa.unassigned_at IS NULL

        LEFT JOIN organisations_project op

            ON op.pro_id = apa.pro_id

            AND op.org_id = apa.org_id

        LEFT JOIN community_user_assignment cua

            ON cua.assignee_id = a.id

            AND cua.assignee_type='agent'

            AND cua.status='active'

            AND cua.unassigned_at IS NULL

        LEFT JOIN community_user cu

            ON cu.id = cua.community_user_id

        WHERE {$where}

        GROUP BY

            a.id

        ORDER BY

            a.firstName ASC,

            a.lastName ASC

        LIMIT ?

        OFFSET ?

        ";

        $stmt = mysqli_prepare(
            $conn,
            $sql
        );

        $bindTypes = $types . "ii";

        $params[] = $limit;
        $params[] = $offset;

        mysqli_stmt_bind_param(
            $stmt,
            $bindTypes,
            ...$params
        );

        mysqli_stmt_execute($stmt);

        $result = mysqli_stmt_get_result($stmt);

        $agents = [];

        while (

            $row = mysqli_fetch_assoc($result)

        ) {

            $initials = strtoupper(

                substr(
                    $row['firstName'],
                    0,
                    1
                )

                .

                substr(
                    $row['lastName'],
                    0,
                    1
                )

            );

            $projects = [];

            if (

                !empty(
                    $row['projects']
                )

            ) {

                $json = "[" .

                    $row['projects']

                    . "]";

                $projects = json_decode(
                    $json,
                    true
                ) ?? [];

            }

            $agents[] = [

                'id' => (int) $row['id'],

                'initials' => $initials,

                'name' => trim(

                    $row['firstName']

                    . ' '

                    . $row['lastName']

                ),

                'username' => $row['username'],

                'email' => $row['email'],

                'profile_photo' => $row['profile_photo'],

                'projects' => $projects,

                'communities_assigned' => (int)

                    $row['communities_assigned'],

                'status' => 'Active',

                'joined' =>

                    $row['date_created']

            ];

        }

        /*
        |--------------------------------------------------------------------------
        | RESPONSE
        |--------------------------------------------------------------------------
        */

        return [

            'summary' => [

                'total_agents' =>

                    $totalAgents,

                'communities_covered' =>

                    $communitiesCovered,

                'active_agents' =>

                    $activeAgents

            ],

            'page' =>

                $page,

            'per_page' =>

                $limit,

            'total' =>

                $filteredTotal,

            'total_pages' =>

                (int) ceil(

                    $filteredTotal / $limit

                ),

            'agents' =>

                $agents

        ];

    },

    1800

);

/*
|--------------------------------------------------------------------------
| RESPONSE
|--------------------------------------------------------------------------
*/

json_response([

    'status' => true,

    'message' =>

        'Agents retrieved successfully.',

    'data' =>

        $data

]);