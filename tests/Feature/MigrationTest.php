<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

class MigrationTest extends TestCase
{
    use RefreshDatabase;

    private $migration;

    protected function setUp(): void
    {
        parent::setUp();
        // Load the migration instance
        $this->migration = require database_path('migrations/2026_07_15_200003_backfill_estado_preparacion_from_estado.php');
    }

    public function test_migration_backfills_pending_orders_correctly()
    {
        $id = DB::table('orders')->insertGetId([
            'estado' => 'pendiente',
            'estado_preparacion' => 'pendiente',
            'items' => json_encode([['id' => 'item_1', 'nombre' => 'Espresso', 'estado' => 'pendiente']]),
            'total' => 30,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        $this->migration->up();

        $order = DB::table('orders')->where('id', $id)->first();
        $items = json_decode($order->items, true);

        $this->assertEquals('pendiente', $order->estado_preparacion);
        $this->assertEquals('pendiente', $items[0]['estado']);
        $this->assertNotEmpty($order->tracking_token);
        $this->assertEquals(0, $order->lock_version);
    }

    public function test_migration_backfills_en_preparacion_orders_correctly()
    {
        $id = DB::table('orders')->insertGetId([
            'estado' => 'en_preparacion',
            'estado_preparacion' => 'pendiente',
            'items' => json_encode([['id' => 'item_1', 'nombre' => 'Espresso', 'estado' => 'pendiente']]),
            'total' => 30,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        $this->migration->up();

        $order = DB::table('orders')->where('id', $id)->first();
        $items = json_decode($order->items, true);

        $this->assertEquals('en_preparacion', $order->estado_preparacion);
        $this->assertEquals('en_preparacion', $items[0]['estado']);
    }

    public function test_migration_backfills_listo_and_entregado_orders_correctly()
    {
        $id1 = DB::table('orders')->insertGetId([
            'estado' => 'listo',
            'estado_preparacion' => 'pendiente',
            'items' => json_encode([['id' => 'item_1', 'nombre' => 'Espresso', 'estado' => 'pendiente']]),
            'total' => 30,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        $id2 = DB::table('orders')->insertGetId([
            'estado' => 'entregado',
            'estado_preparacion' => 'pendiente',
            'items' => json_encode([['id' => 'item_2', 'nombre' => 'Latte', 'estado' => 'pendiente']]),
            'total' => 45,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        $this->migration->up();

        $order1 = DB::table('orders')->where('id', $id1)->first();
        $items1 = json_decode($order1->items, true);
        $this->assertEquals('listo', $order1->estado_preparacion);
        $this->assertEquals('listo', $items1[0]['estado']);

        $order2 = DB::table('orders')->where('id', $id2)->first();
        $items2 = json_decode($order2->items, true);
        $this->assertEquals('listo', $order2->estado_preparacion);
        $this->assertEquals('listo', $items2[0]['estado']);
    }

    public function test_migration_backfills_cancelled_and_rejected_orders_correctly()
    {
        $id1 = DB::table('orders')->insertGetId([
            'estado' => 'cancelado',
            'estado_preparacion' => 'pendiente',
            'items' => json_encode([['id' => 'item_1', 'nombre' => 'Espresso', 'estado' => 'pendiente']]),
            'total' => 30,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        $id2 = DB::table('orders')->insertGetId([
            'estado' => 'rechazado',
            'estado_preparacion' => 'pendiente',
            'items' => json_encode([['id' => 'item_2', 'nombre' => 'Latte', 'estado' => 'pendiente']]),
            'total' => 45,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        $this->migration->up();

        $order1 = DB::table('orders')->where('id', $id1)->first();
        $items1 = json_decode($order1->items, true);
        $this->assertEquals('cancelado', $order1->estado_preparacion);
        $this->assertEquals('cancelado', $items1[0]['estado']);

        $order2 = DB::table('orders')->where('id', $id2)->first();
        $items2 = json_decode($order2->items, true);
        $this->assertEquals('cancelado', $order2->estado_preparacion);
        $this->assertEquals('cancelado', $items2[0]['estado']);
    }

    public function test_migration_omits_orders_with_existing_operated_kitchen_activity()
    {
        // An order with operated items (one ready, one pending) should NOT be overwritten
        $id = DB::table('orders')->insertGetId([
            'estado' => 'listo', // Commercial status says listo, but kitchen was in a different state
            'estado_preparacion' => 'pendiente',
            'items' => json_encode([
                ['id' => 'item_1', 'nombre' => 'Espresso', 'estado' => 'listo'],
                ['id' => 'item_2', 'nombre' => 'Latte', 'estado' => 'en_preparacion']
            ]),
            'total' => 75,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        $this->migration->up();

        $order = DB::table('orders')->where('id', $id)->first();
        $items = json_decode($order->items, true);

        // Individual item states must NOT be changed to match commercial 'listo'
        $this->assertEquals('listo', $items[0]['estado']);
        $this->assertEquals('en_preparacion', $items[1]['estado']);

        // estado_preparacion must be correctly derived from items: mix of ready and preparation is 'en_preparacion'
        $this->assertEquals('en_preparacion', $order->estado_preparacion);
    }

    public function test_migration_does_not_invent_status_when_evidence_is_insufficient()
    {
        // If commercial status is empty or invalid, default to pending
        $id = DB::table('orders')->insertGetId([
            'estado' => 'unknown_invalid_status',
            'estado_preparacion' => 'pendiente',
            'items' => json_encode([['id' => 'item_1', 'nombre' => 'Espresso', 'estado' => 'pendiente']]),
            'total' => 30,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        $this->migration->up();

        $order = DB::table('orders')->where('id', $id)->first();
        $items = json_decode($order->items, true);

        $this->assertEquals('pendiente', $order->estado_preparacion);
        $this->assertEquals('pendiente', $items[0]['estado']);
    }
}
