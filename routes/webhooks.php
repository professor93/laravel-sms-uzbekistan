<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Uzbek\Sms\Http\Controllers\WebhookController;

Route::post(config('sms.webhook.path').'/{driver}', WebhookController::class)
    ->middleware(config('sms.webhook.middleware', []))
    ->name('sms.webhook');
