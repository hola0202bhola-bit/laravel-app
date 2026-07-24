<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
            $table->boolean('is_available')->default(true);
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
        });

        Schema::create('menus', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('menu_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['menu_id', 'product_id']);
        });

        $now = now();
        $menuId = DB::table('menus')->insertGetId([
            'name' => 'Menú principal',
            'description' => 'Menú predeterminado migrado desde el catálogo existente.',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('products')->orderBy('id')->pluck('id')->chunk(200)->each(
            fn ($productIds) => DB::table('menu_product')->insert(
                $productIds->map(fn ($productId) => [
                    'menu_id' => $menuId,
                    'product_id' => $productId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all()
            )
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_product');
        Schema::dropIfExists('menus');

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['is_active', 'is_available']);
        });
    }
};
