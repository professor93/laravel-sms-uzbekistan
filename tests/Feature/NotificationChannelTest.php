<?php

declare(strict_types=1);

use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Uzbek\Sms\Data\SentMessage;
use Uzbek\Sms\Facades\Sms;
use Uzbek\Sms\Notifications\SmsMessage;

it('sends a string toSms through the default provider', function () {
    Sms::fake();

    (new ChannelTestUser)->notify(new PlainSmsNotification);

    Sms::assertSent(fn (SentMessage $m): bool => $m->provider === 'eskiz'
        && $m->phone === '+998901234567'
        && $m->text === 'Xush kelibsiz!');
});

it('honors SmsMessage options: provider, from, otp', function () {
    Sms::fake();

    (new ChannelTestUser)->notify(new RichSmsNotification);

    Sms::assertSent(fn (SentMessage $m): bool => $m->provider === 'playmobile'
        && $m->text === 'Kod: 1234');
});

it('supports on-demand notifications', function () {
    Sms::fake();

    NotificationFacade::route('sms', '+998907777777')->notify(new PlainSmsNotification);

    Sms::assertSentTo('998907777777');
});

it('skips silently when the notifiable has no sms route', function () {
    Sms::fake();

    (new RoutelessUser)->notify(new PlainSmsNotification);

    Sms::assertNothingSent();
});

it('skips silently when the notification has no toSms method', function () {
    Sms::fake();

    (new ChannelTestUser)->notify(new BrokenSmsNotification);

    Sms::assertNothingSent();
});

class ChannelTestUser
{
    use Notifiable;

    public function routeNotificationForSms(): string
    {
        return '+998901234567';
    }
}

class RoutelessUser
{
    use Notifiable;
}

class PlainSmsNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['sms'];
    }

    public function toSms(object $notifiable): string
    {
        return 'Xush kelibsiz!';
    }
}

class RichSmsNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['sms'];
    }

    public function toSms(object $notifiable): SmsMessage
    {
        return SmsMessage::create('Kod: 1234')->provider('playmobile')->from('3700')->otp();
    }
}

class BrokenSmsNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['sms'];
    }
}
