<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('data_migration_runs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('finished_at')->nullable();
            $table->string('status')->default('pending'); // pending, failed, success
            $table->json('options')->nullable();
            $table->timestamps();
        });

        Schema::create('data_migration_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('data_migration_runs')->onDelete('cascade');
            $table->string('table_name');
            $table->bigInteger('last_migrated_id')->default(0);
            $table->integer('rows_copied')->default(0);
            $table->string('status')->default('processing'); // processing, completed
            $table->timestamps();

            $table->unique(['run_id', 'table_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('data_migration_checkpoints');
        Schema::dropIfExists('data_migration_runs');
    }
};
