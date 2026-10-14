<?php

return [
    'computer_heartbeat_interval_seconds' => (int) env('COMPUTER_HEARTBEAT_INTERVAL_SECONDS', 30),

    // Latest PC agent release. Clubs whose PCs report an older version get a notice in the panel;
    // the agent never updates itself. Set these after tagging agent-vX.Y.Z, then `php artisan optimize`.
    'agent' => [
        'latest_version' => env('AGENT_LATEST_VERSION'),
        'released_at' => env('AGENT_RELEASED_AT'),
        'download_url' => env('AGENT_DOWNLOAD_URL'),
    ],
];
