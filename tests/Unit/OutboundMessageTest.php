<?php

declare(strict_types=1);

use Uzbek\Sms\Data\OutboundMessage;

it('builds one message per phone with the same text', function () {
    $messages = OutboundMessage::sameText(['+998901111111', '+998902222222'], 'Salom');

    expect($messages)->toHaveCount(2)
        ->and($messages[0])->toBeInstanceOf(OutboundMessage::class)
        ->and($messages[0]->phone)->toBe('+998901111111')
        ->and($messages[1]->phone)->toBe('+998902222222')
        ->and($messages[0]->text)->toBe('Salom')
        ->and($messages[1]->text)->toBe('Salom');
});
