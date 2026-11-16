<?php

return [
    'prefix' => 'kernscale_trading_horizon:',
    'defaults' => [
        'supervisor-1' => [
            'connection' => 'redis', 'queue' => ['research', 'default'], 'balance' => 'auto',
            'autoScalingStrategy' => 'time', 'maxProcesses' => 2, 'maxTime' => 0, 'maxJobs' => 0,
            'memory' => 128, 'tries' => 3, 'timeout' => 45, 'nice' => 0,
        ],
    ],
    'environments' => [
        'production' => ['supervisor-1' => ['maxProcesses' => 2, 'balanceMaxShift' => 1, 'balanceCooldown' => 3]],
        'local' => ['supervisor-1' => ['maxProcesses' => 1]],
    ],
];
