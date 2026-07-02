<?php

declare(strict_types=1);

use Uzbek\Sms\Enums\DeliveryStatus;

it('knows which statuses are final', function (DeliveryStatus $status, bool $final) {
    expect($status->isFinal())->toBe($final);
})->with([
    [DeliveryStatus::Pending, false],
    [DeliveryStatus::Sent, false],
    [DeliveryStatus::Unknown, false],
    [DeliveryStatus::Delivered, true],
    [DeliveryStatus::Undelivered, true],
    [DeliveryStatus::Failed, true],
]);
