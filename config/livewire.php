<?php

use App\Models\Attachment;

/*
|--------------------------------------------------------------------------
| Livewire
|--------------------------------------------------------------------------
|
| Only the keys that differ from Livewire's defaults; everything else comes
| from vendor/livewire/livewire/config/livewire.php.
|
*/

return [

    /*
    | Temporary uploads must allow attachments up to the app's own limit
    | (Livewire's default is 12 MB) and are throttled more tightly.
    */
    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK'),
        'rules' => ['required', 'file', 'max:'.Attachment::MAX_KILOBYTES],
        'directory' => null,
        'middleware' => 'throttle:30,1',
        'preview_mimes' => [
            'png', 'gif', 'bmp', 'svg', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
        ],
        'max_upload_time' => 5,
        'cleanup' => true,
    ],

];
