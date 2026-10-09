<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * The test from the profile: right away (not queued), so that the person sees at once whether push reaches their devices.
 */
class TestPush extends Notification
{
    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return (new WebPushMessage)
            ->title(config('app.name'))
            ->body(__('Push notifications work on this device.'))
            ->icon('/icon-192.png')
            ->lang(app()->getLocale())
            ->tag('test')
            ->data(['url' => route('profile', absolute: false)]);
    }
}
