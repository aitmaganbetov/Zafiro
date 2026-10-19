<?php

return [
    // Request counters: each request appends one short line to a per-minute file, the scheduler
    // (monitoring:collect) folds the files into request_metrics. Nothing is written to the database per request.
    'record_requests' => (bool) env('MONITORING_RECORD_REQUESTS', true),
    'requests_path' => env('MONITORING_REQUESTS_PATH', storage_path('framework/metrics')),

    // Snapshots older than this are removed.
    'retention_days' => 30,

    // Newest file in this folder is the last backup; null — backups are not set up.
    'backup_path' => env('BACKUP_PATH'),

    // Hosts whose HTTPS certificates are checked (comma-separated); empty — the hosts of
    // APP_URL, SUPERADMIN_DOMAIN and WIKI_DOMAIN.
    'certificate_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('MONITORING_SSL_HOSTS', ''))))),

    // Alert thresholds (Telegram).
    'scheduler_silent_minutes' => 5,
    'disk_percent' => 85,
    'error_rate_percent' => 5,
    'error_rate_min_errors' => 10,
    'queue_pending' => 100,
    'queue_oldest_minutes' => 10,
];
