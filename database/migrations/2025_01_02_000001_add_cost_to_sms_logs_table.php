<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_logs', function (Blueprint $table): void {
            $table->unsignedSmallInteger('segments')->nullable()->after('status');
            $table->decimal('cost', 12, 2)->nullable()->after('segments');
        });
    }

    public function down(): void
    {
        Schema::table('sms_logs', function (Blueprint $table): void {
            $table->dropColumn(['segments', 'cost']);
        });
    }
};
