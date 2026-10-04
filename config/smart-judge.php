<?php

declare(strict_types=1);

return [

    /*
    | Off means no data leaves the server: JudgeFactory::make() gives no judge, so the app falls back.
    */
    'enabled' => filter_var(env('SMART_JUDGE_ENABLED', true), FILTER_VALIDATE_BOOL),

    /*
    | The driver that answers, one of `drivers` below or one registered with JudgeFactory::extend().
    */
    'driver' => env('SMART_JUDGE_DRIVER', 'typesafe'),

    'drivers' => [
        // without a key there is no judge
        'typesafe' => [
            'key' => env('TYPESAFE_API_KEY'),
            'model' => env('TYPESAFE_MODEL', 'jev-1.13.0'),
            'base_url' => env('TYPESAFE_BASE_URL', 'https://api.typesafe.ai/v1'),
            'timeout' => (float) env('TYPESAFE_TIMEOUT', 5),
        ],
    ],

    'cache' => [
        // null means the app's default store
        'store' => env('SMART_JUDGE_CACHE_STORE'),
        // seconds an answer is kept, unless its scope says otherwise
        'ttl' => 6 * 60 * 60,
        // seconds no scope asks a driver again after it was unavailable
        'unavailable_ttl' => 60,
    ],

    /*
    | Per scope, the name given to JudgeFactory::make(), e.g. 'recurring' => ['ttl' => 3600].
    */
    'scopes' => [],

    'log' => [
        'enabled' => filter_var(env('SMART_JUDGE_LOG_ENABLED', true), FILTER_VALIDATE_BOOL),
        // null means the app's default channel
        'channel' => env('SMART_JUDGE_LOG_CHANNEL'),
    ],

];
