<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('driver')->index();
            $table->string('provider_message_id')->nullable();
            $table->string('phone');
            $table->text('text');
            $table->string('status')->default('pending');
            $table->text('error')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            // Webhooks match the original message through this pair.
            $table->unique(['driver', 'provider_message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_logs');
    }
};
