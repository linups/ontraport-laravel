<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API credentials
    |--------------------------------------------------------------------------
    |
    | Found in Ontraport under Administration > Integrations > Ontraport API.
    | Reading them here (instead of calling env() at runtime) keeps them working
    | after `php artisan config:cache`.
    |
    */

    'app_id' => env('ONTRAPORT_APP_ID'),

    'app_key' => env('ONTRAPORT_APP_KEY'),

    /*
    |--------------------------------------------------------------------------
    | chargeUser() defaults
    |--------------------------------------------------------------------------
    |
    | Values used to build the offer sent by Ontraport::chargeUser(). The defaults
    | are the values that were hard-coded before v1.1. `gateway_id` is only used
    | when the charge data has no `payment_gateway` key.
    |
    */

    'charge' => [
        'gateway_id' => (int) env('ONTRAPORT_GATEWAY_ID', 6),
        'invoice_template' => (int) env('ONTRAPORT_INVOICE_TEMPLATE', 1),
        'trial_period_unit' => 'month',
        'trial_period_count' => 6,
        'subscription_count' => 999,
    ],

];
