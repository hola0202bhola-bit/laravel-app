<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('estado')->default('pendiente');
            $table->string('tipo_pedido')->default('llevar');
            $table->string('numero_mesa')->nullable();
            $table->string('codigo_delivery')->nullable();
            $table->string('metodo_pago')->default('efectivo');
            $table->json('items');
            $table->decimal('total', 8, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
