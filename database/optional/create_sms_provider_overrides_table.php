<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_provider_overrides', function (Blueprint $table): void {
            $table->id();
            $table->string('provider')->unique();
            $table->json('config');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_provider_overrides');
    }
};
