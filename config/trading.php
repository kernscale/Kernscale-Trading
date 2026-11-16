<?php

return [
    'bridge_url' => env('TRADING_BRIDGE_URL'),
    'bridge_token' => env('TRADING_BRIDGE_TOKEN'),
    'release_commit' => env('KERNSCALE_RELEASE_COMMIT'),
    'release_image' => env('KERNSCALE_RELEASE_IMAGE'),
    'model' => 'deepseek-v4.1-flash',
    'stale_after_seconds' => 600,
];
