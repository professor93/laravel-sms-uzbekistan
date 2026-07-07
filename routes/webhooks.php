<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Uzbek\Sms\Http\Controllers\WebhookController;

Route::post(config('sms.webhook.path').'/{provider}', WebhookController::class)
    ->middleware(config('sms.webhook.middleware', []))
    ->name('sms.webhook');
