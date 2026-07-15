<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

class KitchenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed basic products
        Product::create([
            'codigo' => 1,
            'nombre' => 'Americano Tradicional',
            'precio' => 35.00,
            'existencia' => 10,
            'imagen' => '',
            'descripcion' => 'Café negro suave',
            'extras' => []
        ]);
    }

    public function test_order_creation_initializes_individual_statuses()
    {
        $payload = [
            'tipoPedido' => 'llevar',
            'metodoPago' => 'efectivo',
            'items' => [
                [
                    'codigo' => 1,
                    'nombre' => 'Americano Tradicional',
                    'cantidad' => 2,
                    'tamano' => 'Chico',
                    'extras' => []
                ]
            ]
        ];

        $response = $this->postJson('/api/pedidos/crear', $payload);

        $response->assertStatus(200);
        $order = Order::first();
        
        $this->assertNotNull($order);
        $this->assertEquals('pendiente', $order->estado);
        $this->assertCount(1, $order->items);
        
        $item = $order->items[0];
        $this->assertArrayHasKey('id', $item);
        $this->assertEquals('pendiente', $item['estado']);
    }

    public function test_individual_item_status_update_recalculates_general_order_status()
    {
        // 1. Create order with 2 items
        $order = Order::create([
            'estado' => 'pendiente',
            'tipo_pedido' => 'llevar',
            'metodo_pago' => 'efectivo',
            'items' => [
                [
                    'id' => 'item_1',
                    'codigo' => 1,
                    'nombre' => 'Americano',
                    'estado' => 'pendiente',
                    'cantidad' => 1,
                    'tamano' => 'Chico'
                ],
                [
                    'id' => 'item_2',
                    'codigo' => 1,
                    'nombre' => 'Espresso',
                    'estado' => 'pendiente',
                    'cantidad' => 1,
                    'tamano' => 'Chico'
                ]
            ],
            'total' => 65.00
        ]);

        // 2. Move item_1 to en_preparacion
        $response = $this->postJson('/api/cocina/items/estado', [
            'order_id' => $order->id,
            'item_id' => 'item_1',
            'estado' => 'en_preparacion'
        ]);

        $response->assertStatus(200);
        $order->refresh();
        $this->assertEquals('en_preparacion', $order->estado);
        $this->assertEquals('en_preparacion', $order->items[0]['estado']);
        $this->assertEquals('pendiente', $order->items[1]['estado']);

        // 3. Move item_1 to listo, item_2 to cancelado
        $this->postJson('/api/cocina/items/estado', [
            'order_id' => $order->id,
            'item_id' => 'item_2',
            'estado' => 'cancelado'
        ]);
        
        $this->postJson('/api/cocina/items/estado', [
            'order_id' => $order->id,
            'item_id' => 'item_1',
            'estado' => 'listo'
        ]);

        $order->refresh();
        // Since item_2 is cancelled, and item_1 is ready, general status must be ready (listo)
        $this->assertEquals('listo', $order->estado);

        // 4. Cancel item_1 too
        $this->postJson('/api/cocina/items/estado', [
            'order_id' => $order->id,
            'item_id' => 'item_1',
            'estado' => 'cancelado'
        ]);

        $order->refresh();
        // Since all items are now cancelled, the order is cancelled
        $this->assertEquals('cancelado', $order->estado);
    }
}
