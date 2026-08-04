<?php

return [

    'trial_days' => (int) env('RETAIL360_TRIAL_DAYS', 15),

    'trial_plan_slug' => env('RETAIL360_TRIAL_PLAN', 'startup'),

    'default_branch_name' => env('RETAIL360_DEFAULT_BRANCH_NAME', 'My Store'),

    'expired_branch_purge_days' => (int) env('RETAIL360_EXPIRED_BRANCH_PURGE_DAYS', 30),

    'pin_lockout' => [
        'max_attempts' => (int) env('PIN_LOCKOUT_MAX_ATTEMPTS', 5),
        'lockout_minutes' => (int) env('PIN_LOCKOUT_MINUTES', 15),
    ],

    'tenant_roles' => ['owner', 'cashier', 'staff'],

    'vat_types' => ['percent', 'fixed'],

    'uoms' => [
        ['code' => 'pcs', 'label' => 'Pieces', 'fractional' => false],
        ['code' => 'pair', 'label' => 'Pairs', 'fractional' => false],
        ['code' => 'kg', 'label' => 'Kg', 'fractional' => true],
        ['code' => 'g', 'label' => 'Gram', 'fractional' => true],
        ['code' => 'L', 'label' => 'Liters', 'fractional' => true],
        ['code' => 'ml', 'label' => 'ml', 'fractional' => true],
        ['code' => 'box', 'label' => 'Box', 'fractional' => false],
        ['code' => 'pack', 'label' => 'Pack', 'fractional' => false],
        ['code' => 'set', 'label' => 'Set', 'fractional' => false],
    ],

    'bkash' => [
        'app_key' => env('BKASH_APP_KEY'),
        'app_secret' => env('BKASH_APP_SECRET'),
        'username' => env('BKASH_USERNAME'),
        'password' => env('BKASH_PASSWORD'),
        'sandbox' => filter_var(env('BKASH_SANDBOX', true), FILTER_VALIDATE_BOOL),
        'base_url' => env(
            'BKASH_BASE_URL',
            'https://tokenized.sandbox.bka.sh/v1.2.0-beta'
        ),
        'callback_url' => env('BKASH_CALLBACK_URL'),
    ],

];
