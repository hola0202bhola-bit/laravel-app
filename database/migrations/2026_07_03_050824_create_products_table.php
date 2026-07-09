<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->integer('codigo')->unique();
            $table->string('nombre');
            $table->decimal('precio', 8, 2);
            $table->integer('existencia');
            $table->string('imagen')->nullable();
            $table->text('descripcion')->nullable();
            $table->json('extras')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
