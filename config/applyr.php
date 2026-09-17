<?php

return [

    'polling' => [
        // Cron expression for polling active SearchProfiles. Hourly by default
        // to keep Cloudflare bot-scoring risk low.
        'schedule' => env('APPLYR_POLL_SCHEDULE', '0 * * * *'),
    ],

    'adapters' => [
        // Maximum result pages an Adapter fetches per SearchProfile per run.
        'page_cap' => (int) env('APPLYR_ADAPTER_PAGE_CAP', 5),

        // Pause between consecutive requests to one platform, in milliseconds.
        'request_delay_ms' => (int) env('APPLYR_ADAPTER_REQUEST_DELAY_MS', 2000),

        // Maximum open Jobs an Adapter fetches by id per run because its searches
        // stopped returning them; the longest-unrefreshed go first.
        'refresh_cap' => (int) env('APPLYR_ADAPTER_REFRESH_CAP', 50),
    ],

    'tailoring' => [
        // Attempts allowed when generated content fails fact validation.
        'regeneration_limit' => (int) env('APPLYR_REGENERATION_LIMIT', 3),
    ],

];
