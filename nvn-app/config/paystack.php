<?php

return [
    'secret_key'   => env('PAYSTACK_SECRET_KEY'),
    'public_key'   => env('PAYSTACK_PUBLIC_KEY'),
    'base_url'     => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),

    // Used to verify incoming webhook signatures (Paystack signs with your secret key).
    'webhook_secret' => env('PAYSTACK_SECRET_KEY'),

    'currency' => 'NGN',
];
