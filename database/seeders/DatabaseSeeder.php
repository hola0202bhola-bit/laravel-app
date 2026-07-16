<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Product;
use App\Models\Category;
use App\Models\Allergen;
use App\Models\DietaryTag;
use App\Models\CustomBase;
use App\Models\CustomOption;
use App\Models\DiningTable;
use App\Models\PaymentMethod;
use App\Models\DeliveryProvider;
use App\Models\InventoryLog;
use App\Models\Order;
use App\Models\Sale;
use App\Models\TableReservation;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Roles
        DB::table('roles')->insertOrIgnore([
            ['id' => 1, 'nombre' => 'Administrador', 'descripcion' => 'Acceso total'],
            ['id' => 2, 'nombre' => 'Barista/Cocinero', 'descripcion' => 'Pantalla KDS'],
            ['id' => 3, 'nombre' => 'Mesero', 'descripcion' => 'Atención a mesas'],
            ['id' => 4, 'nombre' => 'Cajero', 'descripcion' => 'Cobro y facturación'],
            ['id' => 5, 'nombre' => 'Gerente', 'descripcion' => 'Administración operativa'],
        ]);

        $demoUsers = [
            ['name' => 'Administrador Demo', 'email' => 'admin@cafesublime.test', 'role_id' => 1],
            ['name' => 'Gerente Demo', 'email' => 'gerente@cafesublime.test', 'role_id' => 5],
            ['name' => 'Barista Demo', 'email' => 'cocina@cafesublime.test', 'role_id' => 2],
        ];

        foreach ($demoUsers as $demoUser) {
            $user = User::updateOrCreate(
                ['email' => $demoUser['email']],
                ['name' => $demoUser['name'], 'password' => Hash::make('Demo123!')]
            );
            $user->roles()->sync([$demoUser['role_id']]);
        }

        // 2. Categories
        $catHot = Category::updateOrCreate(['nombre' => 'Cafés Calientes'], ['icono' => 'coffee']);
        $catCold = Category::updateOrCreate(['nombre' => 'Bebidas Heladas'], ['icono' => 'snowflake']);
        $catPastry = Category::updateOrCreate(['nombre' => 'Repostería & Hojaldre'], ['icono' => 'cake']);

        // 3. Allergens
        $algGluten = Allergen::updateOrCreate(['nombre' => 'Gluten'], ['icono' => 'wheat']);
        $algLactosa = Allergen::updateOrCreate(['nombre' => 'Lactosa'], ['icono' => 'milk']);
        $algNueces = Allergen::updateOrCreate(['nombre' => 'Nueces'], ['icono' => 'nut']);

        // 4. Dietary Tags
        $tagVeg = DietaryTag::updateOrCreate(['nombre' => 'Vegetariano'], ['color' => '#10b981']);
        $tagKeto = DietaryTag::updateOrCreate(['nombre' => 'Keto'], ['color' => '#f59e0b']);
        $tagSugarFree = DietaryTag::updateOrCreate(['nombre' => 'Sin Azúcar'], ['color' => '#06b6d4']);

        // 5. Raw Ingredients & Suppliers
        DB::table('suppliers')->insertOrIgnore([
            ['id' => 1, 'nombre' => 'Tostadores de Café Chiapas', 'contacto' => 'Juan Pérez', 'telefono' => '555-1234'],
            ['id' => 2, 'nombre' => 'Lácteos y Bebidas Alpura', 'contacto' => 'María Gómez', 'telefono' => '555-5678']
        ]);

        DB::table('ingredients')->insertOrIgnore([
            ['id' => 1, 'nombre' => 'Café Arábica en Grano', 'stock_actual' => '50000.00', 'unidad_medida' => 'g'],
            ['id' => 2, 'nombre' => 'Leche Entera', 'stock_actual' => '40000.00', 'unidad_medida' => 'ml'],
            ['id' => 3, 'nombre' => 'Leche de Almendras', 'stock_actual' => '20000.00', 'unidad_medida' => 'ml']
        ]);

        // 6. Custom Bases & Options
        CustomBase::updateOrCreate(['nombre' => 'Doble Shot Espresso'], ['precio_base' => '30.00', 'categoria' => 'bebida']);
        CustomBase::updateOrCreate(['nombre' => 'Té Matcha Ceremonial'], ['precio_base' => '45.00', 'categoria' => 'bebida']);
        CustomBase::updateOrCreate(['nombre' => 'Infusión de Manzanilla / Menta'], ['precio_base' => '25.00', 'categoria' => 'bebida']);
        CustomBase::updateOrCreate(['nombre' => 'Base de Chocolate Artesanal'], ['precio_base' => '38.00', 'categoria' => 'bebida']);

        CustomOption::updateOrCreate(['nombre' => 'Sin Azúcar (0%)', 'grupo' => 'dulzor'], ['precio_adicional' => '0.00']);
        CustomOption::updateOrCreate(['nombre' => 'Dulce Medio (50%)', 'grupo' => 'dulzor'], ['precio_adicional' => '0.00']);
        CustomOption::updateOrCreate(['nombre' => 'Dulce Estándar (100%)', 'grupo' => 'dulzor'], ['precio_adicional' => '0.00']);
        CustomOption::updateOrCreate(['nombre' => 'Leche de Almendras', 'grupo' => 'leche'], ['precio_adicional' => '6.00']);
        CustomOption::updateOrCreate(['nombre' => 'Leche de Avena', 'grupo' => 'leche'], ['precio_adicional' => '7.00']);
        CustomOption::updateOrCreate(['nombre' => 'Servido Caliente', 'grupo' => 'temperatura'], ['precio_adicional' => '0.00']);
        CustomOption::updateOrCreate(['nombre' => 'Servido con Hielo (Helado)', 'grupo' => 'temperatura'], ['precio_adicional' => '3.00']);
        CustomOption::updateOrCreate(['nombre' => 'Estilo Frappé Licuado', 'grupo' => 'temperatura'], ['precio_adicional' => '8.00']);

        // 7. Dining Tables
        for ($i = 1; $i <= 8; $i++) {
            DiningTable::updateOrCreate(
                ['numero' => "Mesa {$i}"],
                [
                    'capacidad' => $i % 2 === 0 ? 4 : 2,
                    'ubicacion' => $i > 5 ? 'Terraza' : 'Interior',
                    'estado' => 'libre'
                ]
            );
        }

        // 8. Order Statuses
        DB::table('order_statuses')->insertOrIgnore([
            ['id' => 1, 'clave' => 'pendiente', 'nombre' => 'Pendiente', 'color' => '#ef4444'],
            ['id' => 2, 'clave' => 'en_preparacion', 'nombre' => 'En Preparación', 'color' => '#f59e0b'],
            ['id' => 3, 'clave' => 'listo', 'nombre' => 'Listo para Entregar', 'color' => '#10b981'],
            ['id' => 4, 'clave' => 'entregado', 'nombre' => 'Entregado', 'color' => '#6366f1'],
            ['id' => 5, 'clave' => 'rechazado', 'nombre' => 'Rechazado', 'color' => '#6b7280']
        ]);

        // 9. Payment Methods & Delivery Providers
        PaymentMethod::updateOrCreate(['clave' => 'efectivo'], ['nombre' => 'Efectivo']);
        PaymentMethod::updateOrCreate(['clave' => 'tarjeta'], ['nombre' => 'Tarjeta']);
        PaymentMethod::updateOrCreate(['clave' => 'delivery'], ['nombre' => 'App Delivery']);

        DeliveryProvider::updateOrCreate(['nombre' => 'Rappi'], ['comision_porcentaje' => '15.00']);
        DeliveryProvider::updateOrCreate(['nombre' => 'UberEats'], ['comision_porcentaje' => '18.00']);

        // 10. Products & Attach Allergens / Dietary Tags
        $standardExtras = [
            ['id' => 'extra_leche', 'nombre' => 'Leche de Almendras', 'precio' => '6.00'],
            ['id' => 'extra_shot', 'nombre' => 'Shot de Espresso Extra', 'precio' => '8.00'],
            ['id' => 'extra_jarabe', 'nombre' => 'Jarabe de Vainilla', 'precio' => '7.00'],
            ['id' => 'extra_crema', 'nombre' => 'Crema Batida', 'precio' => '5.00']
        ];

        $products = [
            ['codigo' => 1, 'category_id' => $catHot->id, 'nombre' => 'Americano Tradicional', 'precio' => '35.00', 'existencia' => 15, 'imagen' => '/assets/americano_cup.png', 'descripcion' => 'Café negro suave elaborado con granos arábicos de tueste medio.', 'extras' => $standardExtras],
            ['codigo' => 2, 'category_id' => $catHot->id, 'nombre' => 'Cappuccino Art', 'precio' => '45.50', 'existencia' => 10, 'imagen' => '/assets/cappuccino_art.png', 'descripcion' => 'Espresso con leche al vapor y capa abundante de espuma con arte barista.', 'extras' => $standardExtras],
            ['codigo' => 3, 'category_id' => $catPastry->id, 'nombre' => 'Muffin de Chocolate', 'precio' => '28.00', 'existencia' => 12, 'imagen' => '/assets/chocolate_muffin.png', 'descripcion' => 'Muffin esponjoso horneado diariamente con chispas de chocolate semi-amargo.', 'extras' => []],
            ['codigo' => 4, 'category_id' => $catHot->id, 'nombre' => 'Espresso Italiano', 'precio' => '30.00', 'existencia' => 20, 'imagen' => '/assets/americano_cup.png', 'descripcion' => 'Extracción concentrada de café con crema dorada y aroma intenso.', 'extras' => $standardExtras],
            ['codigo' => 5, 'category_id' => $catHot->id, 'nombre' => 'Latte Vainilla', 'precio' => '48.00', 'existencia' => 15, 'imagen' => '/assets/cappuccino_art.png', 'descripcion' => 'Espresso combinado con abundante leche cremosa y toque sutil de vainilla.', 'extras' => $standardExtras],
            ['codigo' => 6, 'category_id' => $catHot->id, 'nombre' => 'Flat White', 'precio' => '46.00', 'existencia' => 8, 'imagen' => '/assets/cappuccino_art.png', 'descripcion' => 'Doble shot de espresso con microespuma tersa de leche.', 'extras' => $standardExtras],
            ['codigo' => 7, 'category_id' => $catHot->id, 'nombre' => 'Mocha de Caramelo', 'precio' => '52.00', 'existencia' => 12, 'imagen' => '/assets/cappuccino_art.png', 'descripcion' => 'Espresso con chocolate fino, leche, jarabe de caramelo y crema batida.', 'extras' => $standardExtras],
            ['codigo' => 8, 'category_id' => $catPastry->id, 'nombre' => 'Croissant Clásico', 'precio' => '32.00', 'existencia' => 10, 'imagen' => '/assets/croissant_pastry.png', 'descripcion' => 'Hojaldre mantequilloso crujiente por fuera y suave por dentro.', 'extras' => []],
            ['codigo' => 9, 'category_id' => $catPastry->id, 'nombre' => 'Tarta de Cheesecake', 'precio' => '45.00', 'existencia' => 3, 'imagen' => '/assets/chocolate_muffin.png', 'descripcion' => 'Cheesecake cremoso estilo Nueva York con base de galleta crocante.', 'extras' => []],
            ['codigo' => 10, 'category_id' => $catPastry->id, 'nombre' => 'Galleta Chispas', 'precio' => '22.00', 'existencia' => 25, 'imagen' => '/assets/chocolate_muffin.png', 'descripcion' => 'Galleta tradicional recién horneada repleta de chispas de chocolate.', 'extras' => []],
            ['codigo' => 11, 'category_id' => $catHot->id, 'nombre' => 'Matcha Latte', 'precio' => '50.00', 'existencia' => 10, 'imagen' => '/assets/cappuccino_art.png', 'descripcion' => 'Té verde matcha de grado ceremonial batido con leche espumada.', 'extras' => $standardExtras],
            ['codigo' => 12, 'category_id' => $catCold->id, 'nombre' => 'Frappé de Oreo', 'precio' => '55.00', 'existencia' => 15, 'imagen' => '/assets/cappuccino_art.png', 'descripcion' => 'Bebida helada licuada con galletas Oreo, crema y jarabe de chocolate.', 'extras' => $standardExtras],
            ['codigo' => 13, 'category_id' => $catCold->id, 'nombre' => 'Cold Brew Tonic', 'precio' => '42.00', 'existencia' => 18, 'imagen' => '/assets/americano_cup.png', 'descripcion' => 'Café extraído en frío durante 18 horas servido con agua tónica y hielo.', 'extras' => $standardExtras],
            ['codigo' => 14, 'category_id' => $catPastry->id, 'nombre' => 'Bagel de Queso Crema', 'precio' => '38.00', 'existencia' => 10, 'imagen' => '/assets/croissant_pastry.png', 'descripcion' => 'Bagel tostado untado con rico queso crema con finas hierbas.', 'extras' => []],
            ['codigo' => 15, 'category_id' => $catPastry->id, 'nombre' => 'Brownie con Nuez', 'precio' => '25.00', 'existencia' => 14, 'imagen' => '/assets/chocolate_muffin.png', 'descripcion' => 'Brownie fudgy intenso de chocolate acompañado de trozos de nuez troceada.', 'extras' => []]
        ];

        foreach ($products as $pData) {
            $prod = Product::updateOrCreate(['codigo' => $pData['codigo']], $pData);

            InventoryLog::updateOrCreate(
                ['product_codigo' => $prod->codigo, 'motivo' => 'Inventario inicial demo'],
                ['tipo_movimiento' => 'entrada', 'cantidad' => $prod->existencia]
            );

            // Pivot attachments
            if (in_array($prod->codigo, [1, 4, 13])) { // Coffee black
                DB::table('product_dietary_tags')->insertOrIgnore([
                    ['product_codigo' => $prod->codigo, 'dietary_tag_id' => $tagKeto->id],
                    ['product_codigo' => $prod->codigo, 'dietary_tag_id' => $tagSugarFree->id]
                ]);
            }

            if (in_array($prod->codigo, [3, 8, 9, 10, 14, 15])) { // Pastries
                DB::table('product_allergens')->insertOrIgnore([
                    ['product_codigo' => $prod->codigo, 'allergen_id' => $algGluten->id],
                    ['product_codigo' => $prod->codigo, 'allergen_id' => $algLactosa->id]
                ]);
                DB::table('product_dietary_tags')->insertOrIgnore([
                    ['product_codigo' => $prod->codigo, 'dietary_tag_id' => $tagVeg->id]
                ]);
            }

            if (in_array($prod->codigo, [15])) { // Brownie with nuts
                DB::table('product_allergens')->insertOrIgnore([
                    ['product_codigo' => $prod->codigo, 'allergen_id' => $algNueces->id]
                ]);
            }
        }

        $demoItems = [[
            'id' => 'demo_item_1',
            'estado' => 'pendiente',
            'codigo' => 1,
            'nombre' => 'Americano Tradicional',
            'tamano' => 'Chico',
            'precioBase' => '35.00',
            'extras' => [],
            'precioFinalUnitario' => '35.00',
            'cantidad' => 1,
            'subtotal' => '35.00',
        ]];

        Order::updateOrCreate(
            ['tracking_token' => 'demo-tracking-001'],
            [
                'estado' => 'pendiente',
                'estado_preparacion' => 'pendiente',
                'lock_version' => 0,
                'tipo_pedido' => 'mesa',
                'numero_mesa' => 'Mesa 1',
                'metodo_pago' => 'efectivo',
                'items' => $demoItems,
                'total' => '35.00',
            ]
        );

        $demoSaleExists = Sale::all()->contains(
            fn (Sale $sale) => collect($sale->items)->contains('id', 'demo_item_1')
        );
        if (!$demoSaleExists) {
            Sale::create(['items' => $demoItems, 'total' => '35.00']);
        }

        TableReservation::updateOrCreate(
            ['folio' => 'DEMO-001'],
            [
                'cliente_nombre' => 'Cliente Demostración',
                'cliente_telefono' => '555-0101',
                'fecha' => now()->addDay()->toDateString(),
                'hora' => '12:00:00',
                'personas' => 2,
                'dining_table_id' => DiningTable::where('numero', 'Mesa 2')->value('id'),
                'estado' => 'confirmada',
                'notas' => 'Reserva de ejemplo para la presentación',
            ]
        );
    }
}
