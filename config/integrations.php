<?php

return [
    /*
     * Operation-level API scopes. Anything not granted is denied. "dangerous" scopes
     * change physical state or expose personal data and are highlighted in the UI.
     */
    'scopes' => [
        'products:read' => ['label' => 'Чтение товаров и остатков', 'dangerous' => false],
        'products:write' => ['label' => 'Создание и изменение товаров', 'dangerous' => false],
        'stock:write' => ['label' => 'Оприходование и списание товаров', 'dangerous' => false],
        'sales:read' => ['label' => 'Чтение продаж и чеков', 'dangerous' => false],
        'computers:read' => ['label' => 'Чтение компьютеров и зон', 'dangerous' => false],
        'bookings:write' => ['label' => 'Бронирование компьютеров', 'dangerous' => false],
        'computers:control' => ['label' => 'Включение, перезагрузка и выключение компьютеров', 'dangerous' => true],
        'tariffs:read' => ['label' => 'Чтение тарифов', 'dangerous' => false],
        'members:read' => ['label' => 'Чтение клиентов клуба (персональные данные)', 'dangerous' => true],
    ],

    'webhook_events' => [
        'product.created' => 'Создан товар',
        'product.updated' => 'Изменён товар',
        'stock.changed' => 'Изменился остаток товара',
        'sale.completed' => 'Проведена продажа',
        'computer.reservation_changed' => 'Изменилась бронь компьютера',
        'computer.controlled' => 'Команда управления компьютером',
    ],

    'key_prefix' => 'zfk_',
    'key_cache_ttl_seconds' => 300,
    'default_rate_limit_per_minute' => 120,
    'max_rate_limit_per_minute' => 1000,
    'club_rate_limit_per_minute' => 600,
    'failed_auth_per_minute_per_ip' => 20,
    'max_request_kb' => 256,
    'max_bulk_items' => 50,
    'request_log_retention_days' => 90,
    'idempotency_retention_hours' => 24,
    'webhook_retry_backoff_seconds' => [10, 60, 300, 1800, 7200],
    'webhook_timeout_seconds' => 5,
    'webhook_signature_tolerance_seconds' => 300,
];
