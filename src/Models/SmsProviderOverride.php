<?php

declare(strict_types=1);

namespace Uzbek\Sms\Models;

use Illuminate\Database\Eloquent\Model;

final class SmsProviderOverride extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'config' => 'array',
        ];
    }
}
