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

    /*
    |--------------------------------------------------------------------------
    | Languages
    |--------------------------------------------------------------------------
    |
    | The languages people can choose, as two-letter codes with their names in
    | the language itself. `APP_LOCALE` is the default for new people and for
    | visitors whose browser asks for something else.
    |
    */

    'locales' => [
        'de' => 'Deutsch',
        'en' => 'English',
    ],

    /** How many days ahead the digest looks, besides today and overdue tasks. */
    'digest_days_ahead' => 3,

    /** Read notifications older than this many days are removed from the inbox every night. */
    'keep_read_notifications_days' => 90,

];
