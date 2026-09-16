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
    ],

    'tailoring' => [
        // Attempts allowed when generated content fails fact validation.
        'regeneration_limit' => (int) env('APPLYR_REGENERATION_LIMIT', 3),
    ],

];
