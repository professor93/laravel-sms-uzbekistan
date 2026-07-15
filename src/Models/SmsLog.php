<?php

declare(strict_types=1);

namespace Uzbek\Sms\Models;

use Illuminate\Database\Eloquent\Model;
use Uzbek\Sms\Enums\DeliveryStatus;

final class SmsLog extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => DeliveryStatus::class,
            'payload' => 'array',
            'segments' => 'integer',
            'cost' => 'float',
        ];
    }
}
