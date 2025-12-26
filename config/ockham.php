<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Настройки расчетов Project Ockham
    |--------------------------------------------------------------------------
    */

    'calculations' => [
        
        // Синхронный режим (Fixed/Interactive)
        'sync' => [
            // TTL кэша в Redis (секунды)
            'cache_ttl' => env('CALC_SYNC_CACHE_TTL', 3600), // 1 час
            
            // Таймаут выполнения (секунды)
            'timeout' => env('CALC_SYNC_TIMEOUT', 30),
        ],
        
        // Асинхронный режим (Monte Carlo)
        'async' => [
            // Имя очереди для расчетов
            'queue_name' => env('CALC_QUEUE_NAME', 'calculations'),
            
            // Таймаут выполнения job (секунды)
            'timeout' => env('CALC_ASYNC_TIMEOUT', 3600), // 1 час
            
            // Количество попыток при ошибке
            'retry_attempts' => env('CALC_RETRY_ATTEMPTS', 3),
            
            // Задержка между retry (секунды)
            'retry_backoff' => env('CALC_RETRY_BACKOFF', 60),
            
            // Максимальное количество итераций Monte Carlo
            'max_iterations' => env('CALC_MAX_ITERATIONS', 10000),
            
            // Дефолтное количество итераций
            'default_iterations' => env('CALC_DEFAULT_ITERATIONS', 1000),
        ],
        
        // Smart Binding
        'binding' => [
            // Grace Period для отвязки старых расчетов (дни)
            'grace_period_days' => env('CALC_GRACE_PERIOD_DAYS', 7),
            
            // Период до физического удаления после отвязки (дни)
            'delete_after_days' => env('CALC_DELETE_AFTER_DAYS', 30),
        ],
        
        // Хэширование
        'hashing' => [
            // Алгоритм хэширования
            'algorithm' => 'sha256',
            
            // Точность для округления float-значений
            'float_precision' => 10,
        ],
        
        // WebSocket Broadcasting
        'broadcasting' => [
            // Включить WebSocket уведомления
            'enabled' => env('CALC_BROADCASTING_ENABLED', true),
            
            // Частота отправки прогресса (процент)
            'progress_interval' => env('CALC_PROGRESS_INTERVAL', 5),
        ],
    ],

];
