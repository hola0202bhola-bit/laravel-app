<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('estado_preparacion')->default('pendiente')->after('estado');
            $table->string('tracking_token')->nullable()->unique()->after('estado_preparacion');
            $table->unsignedBigInteger('lock_version')->default(0)->after('tracking_token');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['estado_preparacion', 'tracking_token', 'lock_version']);
        });
    }
};
