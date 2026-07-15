<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_status_histories', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->after('order_id');
            $table->string('item_id')->nullable()->after('user_id');
            $table->text('motivo')->nullable()->after('estado_nuevo');

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_status_histories', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn(['user_id', 'item_id', 'motivo']);
        });
    }
};
