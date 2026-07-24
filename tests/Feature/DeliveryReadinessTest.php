<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Menu;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DeliveryReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_demo_seed_contains_the_required_presentation_data(): void
    {
        foreach (['Administrador', 'Gerente', 'Barista/Cocinero'] as $role) {
            $this->assertDatabaseHas('roles', ['nombre' => $role]);
        }

        foreach (['admin@cafesublime.test', 'gerente@cafesublime.test', 'cocina@cafesublime.test'] as $email) {
            $user = User::where('email', $email)->firstOrFail();
            $this->assertTrue(Hash::check('Demo123!', $user->password));
        }

        $this->assertSame(15, Product::count());
        $this->assertSame(0, Product::whereNull('category_id')->count());
        $this->assertSame(1, Menu::where('name', 'Menú principal')->count());
        $this->assertSame(15, Menu::where('name', 'Menú principal')->firstOrFail()->products()->count());
        $this->assertDatabaseCount('inventory_logs', 15);
        $this->assertDatabaseCount('dining_tables', 8);
        $this->assertDatabaseHas('table_reservations', ['folio' => 'DEMO-001']);
        $this->assertDatabaseHas('orders', ['tracking_token' => 'demo-tracking-001']);
        $this->getJson('/api/productos')->assertOk()->assertJsonCount(15);
    }

    public function test_main_pages_and_demo_login_logout_are_functional(): void
    {
        $this->get('/cliente')->assertOk()->assertSee('Café Sublime - Menú');
        $this->get('/cocina')->assertOk()->assertSee('Pantalla de Cocina');
        $this->get('/empleado')->assertRedirect('/empleado/login');
        $this->get('/empleado/login')->assertOk()->assertSee('Panel de empleados');

        $this->post('/empleado/login', [
            'email' => 'admin@cafesublime.test',
            'password' => 'Demo123!',
        ])->assertRedirect('/empleado');
        $this->get('/empleado')->assertOk()->assertSee('Café Sublime - Administración');
        $this->post('/empleado/logout')->assertRedirect('/empleado/login');
        $this->get('/empleado')->assertRedirect('/empleado/login');
    }

    public function test_demo_seed_can_be_run_twice_without_duplicate_demo_records(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 3);
        $this->assertDatabaseCount('products', 15);
        $this->assertDatabaseCount('menus', 1);
        $this->assertDatabaseCount('menu_product', 15);
        $this->assertDatabaseCount('inventory_logs', 15);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('table_reservations', 1);
    }

    public function test_demo_kds_and_customer_order_tracking_flow(): void
    {
        $login = $this->postJson('/api/auth/login', [
            'email' => 'cocina@cafesublime.test',
            'password' => 'Demo123!',
        ])->assertOk();

        $this->withToken($login->json('token'))
            ->getJson('/api/cocina/pedidos')
            ->assertOk()
            ->assertJsonFragment(['nombre' => 'Americano Tradicional']);

        $order = $this->postJson('/api/pedidos/crear', [
            'tipoPedido' => 'llevar',
            'metodoPago' => 'efectivo',
            'items' => [[
                'codigo' => 2,
                'nombre' => 'Cappuccino Art',
                'tamano' => 'Chico',
                'cantidad' => 1,
                'extras' => [],
            ]],
        ])->assertOk();

        $token = $order->json('pedido.tracking_token');
        $this->assertNotEmpty($token);
        $this->withHeader('X-Tracking-Token', $token)
            ->getJson('/api/pedidos/seguimiento')
            ->assertOk()
            ->assertJsonFragment(['nombre' => 'Cappuccino Art', 'estado' => 'pendiente']);
    }
}
