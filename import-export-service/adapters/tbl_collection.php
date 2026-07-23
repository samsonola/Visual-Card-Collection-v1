<?php

/*
|--------------------------------------------------------------------------
| TBL COLLECTION ADAPTER
|--------------------------------------------------------------------------
|
| Import / Export configuration for tbl_collection table.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Metadata
    |--------------------------------------------------------------------------
    */

    'name' => 'Collections',

    'table' => 'tbl_collection',

    'primary_key' => 'id',

    'batch_size' => 5000,

    'max_import_rows' => 1000000,

    'max_export_rows' => 1000000,

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

        'beneficiary_id',

        'beneficiary_name',

        'collection_reference',

        'payment_reference',

        'payment_status',

        'amount',

        'community',

        'ward',

        'lga',

        'state',

        'latitude',

        'longitude',

        'date_collected',

        'date_created'

    ],

    'required_columns' => [

        'org_id',

        'pro_id',

        'agent_id',

        'household_id',

        'amount'

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

        'beneficiary_id',

        'beneficiary_name',

        'collection_reference',

        'payment_reference',

        'payment_status',

        'amount',

        'community',

        'ward',

        'lga',

        'state',

        'latitude',

        'longitude',

        'date_collected',

        'date_created'

    ],

    /*
    |--------------------------------------------------------------------------
    | Export Profiles
    |--------------------------------------------------------------------------
    */

    'profiles' => [

        'basic' => [

            'beneficiary_name',

            'amount',

            'community',

            'payment_status',

            'date_created'

        ],

        'finance' => [

            'beneficiary_name',

            'collection_reference',

            'payment_reference',

            'amount',

            'payment_status',

            'agent_id',

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

        'beneficiary_id',

        'payment_status',

        'collection_reference',

        'payment_reference',

        'community',

        'ward',

        'lga',

        'state',

        'amount_min',

        'amount_max',

        'date_created_from',

        'date_created_to',

        'date_collected_from',

        'date_collected_to'

    ],

    /*
    |--------------------------------------------------------------------------
    | Searchable Columns
    |--------------------------------------------------------------------------
    */

    'searchable' => [

        'beneficiary_name',

        'beneficiary_id',

        'collection_reference',

        'payment_reference',

        'household_id'

    ],

    /*
    |--------------------------------------------------------------------------
    | Sortable Columns
    |--------------------------------------------------------------------------
    */

    'sortable' => [

        'id',

        'beneficiary_name',

        'amount',

        'payment_status',

        'date_created',

        'date_collected'

    ],

    /*
    |--------------------------------------------------------------------------
    | Duplicate Detection
    |--------------------------------------------------------------------------
    */

    'duplicate_keys' => [

        'org_id',

        'collection_reference'

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

        'payment_status',

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

            'required',

            'integer'

        ],

        'household_id' => [

            'required'

        ],

        'amount' => [

            'required',

            'numeric'

        ],

        'payment_status' => [

            'nullable'

        ]

    ]

];