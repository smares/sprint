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

    /*
    |--------------------------------------------------------------------------
    | Attachment storage
    |--------------------------------------------------------------------------
    |
    | Files attached to tasks are stored on this disk. Leave it empty to use the
    | default disk (`FILESYSTEM_DISK`): the private local folder on a server of
    | your own, an object storage bucket on hosts with a temporary file system.
    |
    */

    'attachments_disk' => env('ATTACHMENTS_DISK'),

    /** How many days ahead the digest looks, besides today and overdue tasks. */
    'digest_days_ahead' => 3,

];
