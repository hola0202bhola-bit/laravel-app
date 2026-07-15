<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class KitchenSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed roles
        Role::create(['id' => 1, 'nombre' => 'Administrador']);
        Role::create(['id' => 2, 'nombre' => 'Barista/Cocinero']);
        Role::create(['id' => 3, 'nombre' => 'Mesero']);
    }

    public function test_unauthenticated_kds_orders_access_returns_401()
    {
        $response = $this->getJson('/api/cocina/pedidos');
        $response->assertStatus(401);
    }

    public function test_unauthenticated_kds_item_update_access_returns_401()
    {
        $response = $this->postJson('/api/cocina/items/estado', [
            'order_id' => 1,
            'item_id' => 'item_1',
            'estado' => 'en_preparacion'
        ]);
        $response->assertStatus(401);
    }

    public function test_unauthorized_role_kds_access_returns_403()
    {
        $user = User::factory()->create();
        // Attach Mesero role (unauthorized for kitchen)
        $user->roles()->attach(3);

        $response = $this->actingAs($user)->getJson('/api/cocina/pedidos');
        $response->assertStatus(403);
    }

    public function test_authorized_role_kds_access_success()
    {
        $user = User::factory()->create();
        // Attach Barista/Cocinero role
        $user->roles()->attach(2);

        $response = $this->actingAs($user)->getJson('/api/cocina/pedidos');
        $response->assertStatus(200);
    }

    public function test_login_returns_token_and_user_roles()
    {
        $user = User::factory()->create([
            'email' => 'cocina@cafe.com',
            'password' => Hash::make('password123')
        ]);
        $user->roles()->attach(2);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'cocina@cafe.com',
            'password' => 'password123'
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['token', 'user' => ['roles']]);
        $this->assertEquals(['Barista/Cocinero'], $response->json('user.roles'));
    }

    public function test_logout_revokes_current_sanctum_token()
    {
        $user = User::factory()->create();
        $user->roles()->attach(2);
        $token = $user->createToken('TestToken')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/auth/logout');

        $response->assertStatus(200);
        $this->assertCount(0, $user->tokens);
    }

    public function test_missing_order_returns_404()
    {
        $user = User::factory()->create();
        $user->roles()->attach(2);

        $response = $this->actingAs($user)->postJson('/api/cocina/items/estado', [
            'order_id' => 9999,
            'item_id' => 'item_1',
            'estado' => 'en_preparacion'
        ]);

        $response->assertStatus(404);
    }

    public function test_missing_item_returns_404()
    {
        $user = User::factory()->create();
        $user->roles()->attach(2);

        $order = Order::create([
            'estado' => 'pendiente',
            'estado_preparacion' => 'pendiente',
            'items' => [['id' => 'item_1', 'nombre' => 'Coffee', 'estado' => 'pendiente']],
            'total' => 35
        ]);

        $response = $this->actingAs($user)->postJson('/api/cocina/items/estado', [
            'order_id' => $order->id,
            'item_id' => 'item_nonexistent',
            'estado' => 'en_preparacion'
        ]);

        $response->assertStatus(404);
    }

    public function test_invalid_state_returns_422()
    {
        $user = User::factory()->create();
        $user->roles()->attach(2);

        $order = Order::create([
            'estado' => 'pendiente',
            'estado_preparacion' => 'pendiente',
            'items' => [['id' => 'item_1', 'nombre' => 'Coffee', 'estado' => 'pendiente']],
            'total' => 35
        ]);

        $response = $this->actingAs($user)->postJson('/api/cocina/items/estado', [
            'order_id' => $order->id,
            'item_id' => 'item_1',
            'estado' => 'quemado_invalido'
        ]);

        $response->assertStatus(422);
    }

    public function test_invalid_transition_returns_422()
    {
        $user = User::factory()->create();
        $user->roles()->attach(2);

        // Cancelled items cannot be set back to pending
        $order = Order::create([
            'estado' => 'pendiente',
            'estado_preparacion' => 'pendiente',
            'items' => [['id' => 'item_1', 'nombre' => 'Coffee', 'estado' => 'cancelado']],
            'total' => 35
        ]);

        $response = $this->actingAs($user)->postJson('/api/cocina/items/estado', [
            'order_id' => $order->id,
            'item_id' => 'item_1',
            'estado' => 'pendiente'
        ]);

        $response->assertStatus(422);
    }

    public function test_optimistic_lock_concurrency_conflict_returns_409()
    {
        $user = User::factory()->create();
        $user->roles()->attach(2);

        $order = Order::create([
            'estado' => 'pendiente',
            'estado_preparacion' => 'pendiente',
            'lock_version' => 1,
            'items' => [['id' => 'item_1', 'nombre' => 'Coffee', 'estado' => 'pendiente']],
            'total' => 35
        ]);

        // Hook into Order retrieved event to increment version in DB, forcing mismatch on update
        Order::retrieved(function ($retrievedOrder) use ($order) {
            if ($retrievedOrder->id === $order->id) {
                DB::table('orders')->where('id', $order->id)->increment('lock_version');
            }
        });

        $response = $this->actingAs($user)->postJson('/api/cocina/items/estado', [
            'order_id' => $order->id,
            'item_id' => 'item_1',
            'estado' => 'en_preparacion'
        ]);

        // Exits loop and returns 409 after 3 retries
        $response->assertStatus(409);
    }

    public function test_tracking_rate_limiting_triggers_429()
    {
        // Hit the rate limited tracking endpoint 31 times. The 31st request should trigger a 429
        $token = 'test-token';
        
        for ($i = 0; $i < 30; $i++) {
            $this->withHeaders(['X-Tracking-Token' => $token])
                 ->getJson('/api/pedidos/seguimiento');
        }

        $response = $this->withHeaders(['X-Tracking-Token' => $token])
                         ->getJson('/api/pedidos/seguimiento');
                         
        $response->assertStatus(429);
    }
}
