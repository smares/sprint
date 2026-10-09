<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Version
    |--------------------------------------------------------------------------
    |
    | The release the application runs. The Docker image of a release sets
    | APP_VERSION; a checkout or a build without it shows "dev".
    |
    */

    'version' => env('APP_VERSION') ?: 'dev',

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
    | Reminders
    |--------------------------------------------------------------------------
    |
    | Time of day at which assignees and collaborators get an inbox entry for
    | each open task that is due tomorrow (every day; people can turn it off
    | in their profile).
    |
    */

    'reminder_time' => env('SPRINT_REMINDER_TIME', '08:00'),

    /*
    |--------------------------------------------------------------------------
    | Celebration
    |--------------------------------------------------------------------------
    |
    | The chance, from 0 to 1, that a unicorn flies across the screen when
    | someone completes a task: about one in sixteen by default. 0 turns it off
    | for everyone; people can also turn it off in their profile.
    |
    */

    'celebration_chance' => (float) env('SPRINT_CELEBRATION_CHANCE', 0.06),

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
    | Backups
    |--------------------------------------------------------------------------
    |
    | Every night `php artisan sprint:backup` writes a ZIP with a consistent copy
    | of the SQLite database and the attachments of a local disk to the given
    | disk ("backups" is storage/app/backups; a bucket keeps them off the
    | server) and removes backups older than `keep_days`.
    |
    */

    'backup' => [
        'enabled' => (bool) env('BACKUP_ENABLED', true),
        'disk' => env('BACKUP_DISK', 'backups'),
        'time' => env('BACKUP_TIME', '02:30'),
        'keep_days' => (int) env('BACKUP_KEEP_DAYS', 14),
    ],

    /** How many days ahead the digest looks, besides today and overdue tasks. */
    'digest_days_ahead' => 3,

    /** Read notifications older than this many days are removed from the inbox every night. */
    'keep_read_notifications_days' => 90,

];
