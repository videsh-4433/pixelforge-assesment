<?php

return [
    'base_url' => env('PF_BASE_URL', 'https://pixelforge.promptedit.dev/v1'),
    'api_key' => env('PF_API_KEY'),
    'webhook_secret' => env('PF_WEBHOOK_SECRET'),

    // Reject webhook signatures older/newer than this many seconds (replay protection).
    'webhook_tolerance' => (int) env('PF_WEBHOOK_TOLERANCE', 300),

    // What we charge users on top of Pixelforge's cost, in whole percent.
    'markup_percent' => (int) env('PF_MARKUP_PERCENT', 25),

    // Request timeout (seconds) for calls to Pixelforge.
    'timeout' => 15,

    // Give up submitting a generation (rate limited / overloaded) after this many minutes.
    'submit_give_up_after_minutes' => 15,

    /*
     * Abuse limits. Pixelforge's limits are per *account* (shared by all our users): 3 generations
     * in flight, 60 requests/minute, 500 generations/day. Without per-user caps, one user with free
     * credits could exhaust them for everyone and run up unbounded provider costs.
     */
    'limits' => [
        'max_in_flight_per_user' => (int) env('PF_MAX_IN_FLIGHT_PER_USER', 3),
        'max_generations_per_user_per_day' => (int) env('PF_MAX_GENERATIONS_PER_USER_PER_DAY', 50),
        // Credits are fake and free, so cap how much a user can hold (whole dollars).
        'max_balance_dollars' => (int) env('PF_MAX_BALANCE_DOLLARS', 50),
    ],
];
