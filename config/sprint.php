<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Daily digest
    |--------------------------------------------------------------------------
    |
    | Time of day (24h, in the application time zone) at which the morning
    | summary of overdue and upcoming tasks is mailed on weekdays. It only runs
    | when the scheduler is set up (`php artisan schedule:run` every minute).
    |
    */

    'digest_time' => env('SPRINT_DIGEST_TIME', '07:30'),

    /** How many days ahead the digest looks, besides today and overdue tasks. */
    'digest_days_ahead' => 3,

];
