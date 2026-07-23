<?php

/*
|--------------------------------------------------------------------------
| COMMUNITY USER ADAPTER
|--------------------------------------------------------------------------
|
| Import / Export configuration for community_user table.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Metadata
    |--------------------------------------------------------------------------
    */

    'name' => 'Community Users',

    'table' => 'community_user',

    'primary_key' => 'id',

    'batch_size' => 5000,

    'max_import_rows' => 1000000,

    'max_export_rows' => 500000,

    'default_order' => 'id DESC',

    /*
    |--------------------------------------------------------------------------
    | Import
    |--------------------------------------------------------------------------
    */

    'import_columns' => [

        'org_id',

        'pro_id',

        'agent_id',

        'household_id',

        'caregiver_name',

        'caregiver_id',

        'alternative_name',

        'alternative_id',

        'community',

        'ward',

        'lga',

        'state',

        'zone',

        'wallet_id',

        'qrcode',

        'date_created'

    ],

    'required_columns' => [

        'org_id',

        'pro_id',

        'household_id',

        'caregiver_name'

    ],

    /*
    |--------------------------------------------------------------------------
    | Export
    |--------------------------------------------------------------------------
    */

    'export_columns' => [

        'id',

        'org_id',

        'pro_id',

        'agent_id',

        'household_id',

        'caregiver_name',

        'caregiver_id',

        'alternative_name',

        'alternative_id',

        'community',

        'ward',

        'lga',

        'state',

        'zone',

        'wallet_id',

        'qrcode',

        'date_created'

    ],

    /*
    |--------------------------------------------------------------------------
    | Export Profiles
    |--------------------------------------------------------------------------
    */

    'profiles' => [

        'basic' => [

            'household_id',

            'caregiver_name',

            'community',

            'ward',

            'lga',

            'state',

            'date_created'

        ],

        'full' => [

            '*'

        ]

    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Filters
    |--------------------------------------------------------------------------
    */

    'filters' => [

        'id',

        'org_id',

        'pro_id',

        'agent_id',

        'household_id',

        'caregiver_id',

        'alternative_id',

        'community',

        'ward',

        'lga',

        'state',

        'zone',

        'wallet_id',

        'qrcode',

        'date_created_from',

        'date_created_to'

    ],

    /*
    |--------------------------------------------------------------------------
    | Searchable Columns
    |--------------------------------------------------------------------------
    */

    'searchable' => [

        'caregiver_name',

        'caregiver_id',

        'alternative_name',

        'alternative_id',

        'household_id',

        'wallet_id',

        'qrcode'

    ],

    /*
    |--------------------------------------------------------------------------
    | Sortable Columns
    |--------------------------------------------------------------------------
    */

    'sortable' => [

        'id',

        'caregiver_name',

        'community',

        'household_id',

        'date_created'

    ],

    /*
    |--------------------------------------------------------------------------
    | Duplicate Detection
    |--------------------------------------------------------------------------
    */

    'duplicate_keys' => [

        'org_id',

        'pro_id',

        'household_id'

    ],

    /*
    |--------------------------------------------------------------------------
    | Indexed Columns
    |--------------------------------------------------------------------------
    */

    'indexed_columns' => [

        'id',

        'org_id',

        'pro_id',

        'agent_id',

        'household_id',

        'alternative_id',

        'date_created'

    ],

    /*
    |--------------------------------------------------------------------------
    | Validation Rules
    |--------------------------------------------------------------------------
    */

    'validation' => [

        'org_id' => [

            'required',

            'integer'

        ],

        'pro_id' => [

            'required',

            'integer'

        ],

        'agent_id' => [

            'nullable',

            'integer'

        ],

        'household_id' => [

            'required'

        ],

        'caregiver_name' => [

            'required'

        ],

        'community' => [

            'required'

        ],

        'ward' => [

            'nullable'

        ],

        'lga' => [

            'nullable'

        ],

        'state' => [

            'nullable'

        ]

    ]

];