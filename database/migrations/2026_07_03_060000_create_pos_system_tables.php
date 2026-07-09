<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Roles
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique();
            $table->string('descripcion')->nullable();
            $table->timestamps();
        });

        // 2. User Roles Pivot
        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('role_id')->constrained()->onDelete('cascade');
            $table->timestamps();
        });

        // 3. Categories
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique();
            $table->string('icono')->nullable();
            $table->timestamps();
        });

        // 4. Allergens
        Schema::create('allergens', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique();
            $table->string('icono')->nullable();
            $table->timestamps();
        });

        // 5. Dietary Tags
        Schema::create('dietary_tags', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique();
            $table->string('color')->default('#10b981');
            $table->timestamps();
        });

        // 6. Product Allergens Pivot
        Schema::create('product_allergens', function (Blueprint $table) {
            $table->id();
            $table->integer('product_codigo');
            $table->foreignId('allergen_id')->constrained()->onDelete('cascade');
            $table->timestamps();
        });

        // 7. Product Dietary Tags Pivot
        Schema::create('product_dietary_tags', function (Blueprint $table) {
            $table->id();
            $table->integer('product_codigo');
            $table->foreignId('dietary_tag_id')->constrained()->onDelete('cascade');
            $table->timestamps();
        });

        // 8. Raw Ingredients
        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique();
            $table->decimal('stock_actual', 10, 2);
            $table->string('unidad_medida')->default('g'); // g, ml, pza
            $table->decimal('costo_unitario', 8, 2)->default(0.00);
            $table->timestamps();
        });

        // 9. Suppliers
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('contacto')->nullable();
            $table->string('telefono')->nullable();
            $table->timestamps();
        });

        // 10. Ingredient Suppliers Pivot
        Schema::create('ingredient_suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingredient_id')->constrained()->onDelete('cascade');
            $table->foreignId('supplier_id')->constrained()->onDelete('cascade');
            $table->timestamps();
        });

        // 11. Product Recipes
        Schema::create('product_recipes', function (Blueprint $table) {
            $table->id();
            $table->integer('product_codigo');
            $table->foreignId('ingredient_id')->constrained()->onDelete('cascade');
            $table->decimal('cantidad_requerida', 8, 2);
            $table->timestamps();
        });

        // 12. Extra Ingredients
        Schema::create('extra_ingredients', function (Blueprint $table) {
            $table->id();
            $table->string('clave')->unique();
            $table->string('nombre');
            $table->decimal('precio', 8, 2);
            $table->timestamps();
        });

        // 13. Product Extras Pivot
        Schema::create('product_extras', function (Blueprint $table) {
            $table->id();
            $table->integer('product_codigo');
            $table->foreignId('extra_ingredient_id')->constrained()->onDelete('cascade');
            $table->timestamps();
        });

        // 14. Custom Bases (Custom Builder)
        Schema::create('custom_bases', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->decimal('precio_base', 8, 2);
            $table->string('categoria')->default('bebida'); // bebida, comida
            $table->timestamps();
        });

        // 15. Custom Options
        Schema::create('custom_options', function (Blueprint $table) {
            $table->id();
            $table->string('grupo'); // dulzor, temperatura, leche, topping
            $table->string('nombre');
            $table->decimal('precio_adicional', 8, 2)->default(0.00);
            $table->timestamps();
        });

        // 16. Custom Items (Saved User Custom Creations)
        Schema::create('custom_items', function (Blueprint $table) {
            $table->id();
            $table->string('nombre_personalizado');
            $table->foreignId('custom_base_id')->constrained('custom_bases')->onDelete('cascade');
            $table->decimal('precio_total', 8, 2);
            $table->timestamps();
        });

        // 17. Custom Item Details
        Schema::create('custom_item_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_item_id')->constrained('custom_items')->onDelete('cascade');
            $table->foreignId('custom_option_id')->constrained('custom_options')->onDelete('cascade');
            $table->timestamps();
        });

        // 18. Dining Tables
        Schema::create('dining_tables', function (Blueprint $table) {
            $table->id();
            $table->string('numero')->unique();
            $table->integer('capacidad');
            $table->string('ubicacion')->default('Interior'); // Interior, Terraza
            $table->string('estado')->default('libre'); // libre, ocupada, reservada
            $table->timestamps();
        });

        // 19. Table Reservations
        Schema::create('table_reservations', function (Blueprint $table) {
            $table->id();
            $table->string('folio')->unique();
            $table->string('cliente_nombre');
            $table->string('cliente_telefono');
            $table->date('fecha');
            $table->time('hora');
            $table->integer('personas');
            $table->foreignId('dining_table_id')->constrained('dining_tables')->onDelete('cascade');
            $table->string('estado')->default('confirmada'); // confirmada, cancelada, completada
            $table->text('notas')->nullable();
            $table->timestamps();
        });

        // 20. Order Statuses
        Schema::create('order_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('clave')->unique(); // pendiente, en_preparacion, listo, entregado, rechazado
            $table->string('nombre');
            $table->string('color');
            $table->timestamps();
        });

        // 21. Order Status History (Audit Trail for Kitchen Metrics)
        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->integer('order_id');
            $table->string('estado_anterior');
            $table->string('estado_nuevo');
            $table->timestamp('cambiado_en');
            $table->timestamps();
        });

        // 22. Delivery Providers
        Schema::create('delivery_providers', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique(); // Rappi, UberEats, DidiFood, Local
            $table->decimal('comision_porcentaje', 5, 2)->default(0.00);
            $table->timestamps();
        });

        // 23. Payment Methods
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('clave')->unique(); // efectivo, tarjeta, delivery, transferencia
            $table->string('nombre');
            $table->timestamps();
        });

        // 24. Sale Details
        Schema::create('sale_details', function (Blueprint $table) {
            $table->id();
            $table->integer('sale_id');
            $table->integer('product_codigo');
            $table->integer('cantidad');
            $table->decimal('precio_unitario', 8, 2);
            $table->decimal('subtotal', 8, 2);
            $table->timestamps();
        });

        // 25. Inventory Logs
        Schema::create('inventory_logs', function (Blueprint $table) {
            $table->id();
            $table->integer('product_codigo');
            $table->string('tipo_movimiento'); // entrada, salida, ajuste
            $table->integer('cantidad');
            $table->string('motivo');
            $table->timestamps();
        });

        // 26. Order Items Extras Junction
        Schema::create('order_item_extras', function (Blueprint $table) {
            $table->id();
            $table->integer('order_id');
            $table->integer('product_codigo');
            $table->string('extra_nombre');
            $table->decimal('extra_precio', 8, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_extras');
        Schema::dropIfExists('inventory_logs');
        Schema::dropIfExists('sale_details');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('delivery_providers');
        Schema::dropIfExists('order_status_histories');
        Schema::dropIfExists('order_statuses');
        Schema::dropIfExists('table_reservations');
        Schema::dropIfExists('dining_tables');
        Schema::dropIfExists('custom_item_details');
        Schema::dropIfExists('custom_items');
        Schema::dropIfExists('custom_options');
        Schema::dropIfExists('custom_bases');
        Schema::dropIfExists('product_extras');
        Schema::dropIfExists('extra_ingredients');
        Schema::dropIfExists('product_recipes');
        Schema::dropIfExists('ingredient_suppliers');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('ingredients');
        Schema::dropIfExists('product_dietary_tags');
        Schema::dropIfExists('product_allergens');
        Schema::dropIfExists('dietary_tags');
        Schema::dropIfExists('allergens');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('roles');
    }
};
