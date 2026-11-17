<?php

return [
    'bridge_url' => env('TRADING_BRIDGE_URL'),
    'bridge_token' => env('TRADING_BRIDGE_TOKEN'),
    'native_url' => env('TRADING_NATIVE_URL'),
    'native_account' => env('TRADING_NATIVE_ACCOUNT'),
    'native_account_fingerprint' => env('TRADING_NATIVE_ACCOUNT_FINGERPRINT'),
    'pdftotext_binary' => env('TRADING_PDFTOTEXT_BINARY', 'pdftotext'),
    'release_commit' => env('KERNSCALE_RELEASE_COMMIT'),
    'release_image' => env('KERNSCALE_RELEASE_IMAGE'),
    'model' => 'deepseek-v4.1-flash',
    'stale_after_seconds' => 600,
];
