<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

class KitchenTest extends TestCase
{
    use RefreshDatabase;

    private $cookUser;
    private $managerUser;

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

        // Seed roles
        $roleAdmin = Role::create(['id' => 1, 'nombre' => 'Administrador']);
        $roleCook = Role::create(['id' => 2, 'nombre' => 'Barista/Cocinero']);
        $roleManager = Role::create(['id' => 5, 'nombre' => 'Gerente']);

        // Create authorized users
        $this->cookUser = User::factory()->create();
        $this->cookUser->roles()->attach($roleCook);

        $this->managerUser = User::factory()->create();
        $this->managerUser->roles()->attach($roleManager);
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
        $this->assertEquals('pendiente', $order->estado_preparacion);
        $this->assertCount(1, $order->items);

        $item = $order->items[0];
        $this->assertArrayHasKey('id', $item);
        $this->assertEquals('pendiente', $item['estado']);
    }

    public function test_individual_item_status_update_recalculates_preparation_status_but_does_not_modify_commercial_status()
    {
        $order = Order::create([
            'estado' => 'pendiente',
            'estado_preparacion' => 'pendiente',
            'tipo_pedido' => 'llevar',
            'metodo_pago' => 'efectivo',
            'items' => [
                ['id' => 'item_1', 'codigo' => 1, 'nombre' => 'Americano', 'estado' => 'pendiente', 'cantidad' => 1, 'tamano' => 'Chico'],
                ['id' => 'item_2', 'codigo' => 1, 'nombre' => 'Espresso', 'estado' => 'pendiente', 'cantidad' => 1, 'tamano' => 'Chico']
            ],
            'total' => 65.00
        ]);

        // Move item_1 -> en_preparacion
        $response = $this->actingAs($this->cookUser)->postJson('/api/cocina/items/estado', [
            'order_id' => $order->id,
            'item_id' => 'item_1',
            'estado' => 'en_preparacion'
        ]);

        $response->assertStatus(200);
        $order->refresh();

        // Kitchen updates must modify estado_preparacion
        $this->assertEquals('en_preparacion', $order->estado_preparacion);
        // Kitchen updates must NOT modify commercial estado
        $this->assertEquals('pendiente', $order->estado);

        // Move item_1 -> listo, and cancel item_2
        $this->actingAs($this->cookUser)->postJson('/api/cocina/items/estado', [
            'order_id' => $order->id,
            'item_id' => 'item_2',
            'estado' => 'cancelado'
        ]);
        $this->actingAs($this->cookUser)->postJson('/api/cocina/items/estado', [
            'order_id' => $order->id,
            'item_id' => 'item_1',
            'estado' => 'listo'
        ]);

        $order->refresh();
        $this->assertEquals('listo', $order->estado_preparacion);
        $this->assertEquals('pendiente', $order->estado); // still untouched
    }

    public function test_commercial_changes_do_not_overwrite_items_kitchen_states()
    {
        $order = Order::create([
            'estado' => 'pendiente',
            'estado_preparacion' => 'en_preparacion',
            'tipo_pedido' => 'llevar',
            'metodo_pago' => 'efectivo',
            'items' => [
                ['id' => 'item_1', 'codigo' => 1, 'nombre' => 'Americano', 'estado' => 'listo', 'cantidad' => 1, 'tamano' => 'Chico'],
                ['id' => 'item_2', 'codigo' => 1, 'nombre' => 'Espresso', 'estado' => 'pendiente', 'cantidad' => 1, 'tamano' => 'Chico']
            ],
            'total' => 65.00
        ]);

        // Gerente updates commercial status to 'en_entrega' or similar via OrderController
        $response = $this->actingAs($this->managerUser)->postJson('/api/pedidos/estado', [
            'id' => $order->id,
            'estado' => 'entregado'
        ]);

        $response->assertStatus(200);
        $order->refresh();

        // Commercial changes must update estado
        $this->assertEquals('entregado', $order->estado);
        // Commercial changes must NOT overwrite items preparation states
        $this->assertEquals('listo', $order->items[0]['estado']);
        $this->assertEquals('pendiente', $order->items[1]['estado']);
    }

    public function test_kds_queries_active_orders_by_estado_preparacion()
    {
        // Order 1: commercial listo, but prep en_preparacion
        $order1 = Order::create([
            'estado' => 'entregado',
            'estado_preparacion' => 'en_preparacion',
            'items' => [['id' => 'item_1', 'nombre' => 'Coffee', 'estado' => 'en_preparacion']],
            'total' => 35
        ]);

        // Order 2: commercial pendiente, but prep listo (completed in kitchen, waiting for delivery)
        $order2 = Order::create([
            'estado' => 'pendiente',
            'estado_preparacion' => 'listo',
            'items' => [['id' => 'item_2', 'nombre' => 'Latte', 'estado' => 'listo']],
            'total' => 45
        ]);

        // Order 3: completed commercially and cancelled in kitchen
        $order3 = Order::create([
            'estado' => 'cancelado',
            'estado_preparacion' => 'cancelado',
            'items' => [['id' => 'item_3', 'nombre' => 'Matcha', 'estado' => 'cancelado']],
            'total' => 50
        ]);

        $response = $this->actingAs($this->cookUser)->getJson('/api/cocina/pedidos');

        $response->assertStatus(200);
        $orderIds = collect($response->json())->pluck('id')->toArray();

        // KDS loads orders based on active estado_preparacion (pendiente, en_preparacion, listo)
        $this->assertContains($order1->id, $orderIds);
        $this->assertContains($order2->id, $orderIds);
        // KDS must NOT load cancelled preparation orders
        $this->assertNotContains($order3->id, $orderIds);
    }

    public function test_all_cancelled_items_calculates_preparation_status_cancelled()
    {
        $order = Order::create([
            'estado' => 'pendiente',
            'estado_preparacion' => 'pendiente',
            'items' => [
                ['id' => 'item_1', 'nombre' => 'Espresso', 'estado' => 'pendiente'],
                ['id' => 'item_2', 'nombre' => 'Croissant', 'estado' => 'pendiente']
            ],
            'total' => 70
        ]);

        // Cancel item 1
        $this->actingAs($this->cookUser)->postJson('/api/cocina/items/estado', [
            'order_id' => $order->id,
            'item_id' => 'item_1',
            'estado' => 'cancelado'
        ]);

        // Cancel item 2
        $this->actingAs($this->cookUser)->postJson('/api/cocina/items/estado', [
            'order_id' => $order->id,
            'item_id' => 'item_2',
            'estado' => 'cancelado'
        ]);

        $order->refresh();
        $this->assertEquals('cancelado', $order->estado_preparacion);
    }

    public function test_global_start_action_only_updates_pending_items()
    {
        $order = Order::create([
            'estado' => 'pendiente',
            'estado_preparacion' => 'pendiente',
            'items' => [
                ['id' => 'item_1', 'nombre' => 'Espresso', 'estado' => 'pendiente'],
                ['id' => 'item_2', 'nombre' => 'Croissant', 'estado' => 'listo'],
                ['id' => 'item_3', 'nombre' => 'Muffin', 'estado' => 'cancelado']
            ],
            'total' => 90
        ]);

        // Global start order
        $response = $this->actingAs($this->cookUser)->postJson('/api/cocina/estado', [
            'order_id' => $order->id,
            'estado' => 'en_preparacion'
        ]);

        $response->assertStatus(200);
        $order->refresh();

        // Only item_1 (pending) changes to en_preparacion. listo and cancelado remain unchanged
        $this->assertEquals('en_preparacion', $order->items[0]['estado']);
        $this->assertEquals('listo', $order->items[1]['estado']);
        $this->assertEquals('cancelado', $order->items[2]['estado']);
    }

    public function test_global_finish_action_only_updates_pending_and_preparation_items()
    {
        $order = Order::create([
            'estado' => 'pendiente',
            'estado_preparacion' => 'en_preparacion',
            'items' => [
                ['id' => 'item_1', 'nombre' => 'Espresso', 'estado' => 'pendiente'],
                ['id' => 'item_2', 'nombre' => 'Croissant', 'estado' => 'en_preparacion'],
                ['id' => 'item_3', 'nombre' => 'Muffin', 'estado' => 'cancelado']
            ],
            'total' => 90
        ]);

        // Global finish order
        $response = $this->actingAs($this->cookUser)->postJson('/api/cocina/estado', [
            'order_id' => $order->id,
            'estado' => 'listo'
        ]);

        $response->assertStatus(200);
        $order->refresh();

        // item_1 and item_2 change to listo, cancelado remains unchanged
        $this->assertEquals('listo', $order->items[0]['estado']);
        $this->assertEquals('listo', $order->items[1]['estado']);
        $this->assertEquals('cancelado', $order->items[2]['estado']);
    }

    public function test_reveral_from_ready_to_preparation_is_allowed_for_manager_with_motivo()
    {
        $order = Order::create([
            'estado' => 'pendiente',
            'estado_preparacion' => 'listo',
            'items' => [['id' => 'item_1', 'nombre' => 'Espresso', 'estado' => 'listo']],
            'total' => 30
        ]);

        $response = $this->actingAs($this->managerUser)->postJson('/api/cocina/items/estado', [
            'order_id' => $order->id,
            'item_id' => 'item_1',
            'estado' => 'en_preparacion',
            'motivo' => 'Cliente cambio de opinion sobre azucar'
        ]);

        $response->assertStatus(200);
        $order->refresh();

        $this->assertEquals('en_preparacion', $order->items[0]['estado']);
        $this->assertEquals('en_preparacion', $order->estado_preparacion);

        // Check audit trail recorded user, item, reason, and transition
        $audit = DB::table('order_status_histories')
            ->where('order_id', $order->id)
            ->where('item_id', 'item_1')
            ->first();

        $this->assertNotNull($audit);
        $this->assertEquals($this->managerUser->id, $audit->user_id);
        $this->assertEquals('listo', $audit->estado_anterior);
        $this->assertEquals('en_preparacion', $audit->estado_nuevo);
        $this->assertEquals('Cliente cambio de opinion sobre azucar', $audit->motivo);
    }

    public function test_reveral_from_ready_to_preparation_is_denied_for_cook_role()
    {
        $order = Order::create([
            'estado' => 'pendiente',
            'estado_preparacion' => 'listo',
            'items' => [['id' => 'item_1', 'nombre' => 'Espresso', 'estado' => 'listo']],
            'total' => 30
        ]);

        $response = $this->actingAs($this->cookUser)->postJson('/api/cocina/items/estado', [
            'order_id' => $order->id,
            'item_id' => 'item_1',
            'estado' => 'en_preparacion',
            'motivo' => 'Error de preparacion'
        ]);

        $response->assertStatus(422); // Transition denied for cook
    }

    public function test_global_start_action_generates_granular_item_audit_logs()
    {
        $order = Order::create([
            'estado' => 'pendiente',
            'estado_preparacion' => 'pendiente',
            'items' => [
                ['id' => 'item_1', 'nombre' => 'Espresso', 'estado' => 'pendiente'],
                ['id' => 'item_2', 'nombre' => 'Latte', 'estado' => 'pendiente']
            ],
            'total' => 65
        ]);

        $this->actingAs($this->cookUser)->postJson('/api/cocina/estado', [
            'order_id' => $order->id,
            'estado' => 'en_preparacion'
        ]);

        // Should write 2 item audits plus 1 global audit
        $audits = DB::table('order_status_histories')
            ->where('order_id', $order->id)
            ->get();

        $this->assertCount(3, $audits);
        
        $itemAudits = $audits->whereNotNull('item_id');
        $this->assertCount(2, $itemAudits);
        $this->assertContains('item_1', $itemAudits->pluck('item_id')->toArray());
        $this->assertContains('item_2', $itemAudits->pluck('item_id')->toArray());

        $globalAudit = $audits->whereNull('item_id')->first();
        $this->assertNotNull($globalAudit);
        $this->assertEquals('pendiente', $globalAudit->estado_anterior);
        $this->assertEquals('en_preparacion', $globalAudit->estado_nuevo);
    }
}
