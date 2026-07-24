<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DiningTable;
use App\Models\Product;
use App\Models\Role;
use App\Models\TableReservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Role $adminRole;
    private Role $managerRole;
    private Role $cookRole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminRole = Role::create(['nombre' => 'Administrador']);
        $this->managerRole = Role::create(['nombre' => 'Gerente']);
        $this->cookRole = Role::create(['nombre' => 'Barista/Cocinero']);
    }

    public function test_employee_area_requires_login(): void
    {
        $this->get('/empleado')->assertRedirect('/empleado/login');
    }

    public function test_employee_login_rejects_non_administrative_role(): void
    {
        $user = User::factory()->create(['email' => 'cook@example.test', 'password' => Hash::make('secret123')]);
        $user->roles()->attach($this->cookRole);

        $this->post('/empleado/login', ['email' => $user->email, 'password' => 'secret123'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_administrator_can_login_to_employee_area_with_new_admin_token(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.test', 'password' => Hash::make('secret123')]);
        $user->roles()->attach($this->adminRole);

        $this->post('/empleado/login', ['email' => $user->email, 'password' => 'secret123'])
            ->assertRedirect('/empleado')
            ->assertSessionHas('admin_api_token');
        $this->get('/empleado')->assertOk()->assertSee('Café Sublime - Administración');
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $user->id, 'name' => 'AdminDashboard']);
    }

    public function test_admin_api_returns_401_without_authentication(): void
    {
        $this->getJson('/api/admin/dashboard')->assertUnauthorized();
    }

    public function test_admin_api_returns_403_for_unauthorized_role(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach($this->cookRole);
        Sanctum::actingAs($user, ['admin']);

        $this->getJson('/api/admin/dashboard')->assertForbidden();
    }

    public function test_kds_token_cannot_be_reused_for_admin_api(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach($this->adminRole);
        $token = $user->createToken('KdsToken', ['kitchen'])->plainTextToken;

        $this->withToken($token)->getJson('/api/admin/dashboard')->assertForbidden();
    }

    public function test_admin_and_manager_can_access_protected_queries(): void
    {
        foreach ([$this->adminRole, $this->managerRole] as $role) {
            $user = User::factory()->create();
            $user->roles()->attach($role);
            Sanctum::actingAs($user, ['admin']);

            foreach (['dashboard', 'orders', 'sales', 'analytics', 'reservations'] as $endpoint) {
                $this->getJson("/api/admin/{$endpoint}")->assertOk();
            }
        }
    }

    public function test_category_crud_and_deactivation_preserves_relation(): void
    {
        $this->actAsAdmin();
        $category = $this->postJson('/api/admin/categories', ['nombre' => 'Bebidas', 'icono' => 'coffee'])
            ->assertCreated()->json();
        $this->putJson("/api/admin/categories/{$category['id']}", ['nombre' => 'Bebidas frías'])
            ->assertOk()->assertJsonPath('nombre', 'Bebidas frías');

        $product = Product::create(['codigo' => 101, 'nombre' => 'Latte', 'precio' => '45.50', 'existencia' => 4, 'category_id' => $category['id']]);
        $this->assertSame('Bebidas frías', $product->category->nombre);
        $this->patchJson("/api/admin/categories/{$category['id']}/status", ['is_active' => false])
            ->assertOk()->assertJsonPath('is_active', false);
        $this->assertSame($category['id'], $product->fresh()->category_id);
        $this->assertDatabaseHas('products', ['codigo' => 101]);
        $this->patchJson("/api/admin/categories/{$category['id']}/status", ['is_active' => true])
            ->assertOk()->assertJsonPath('is_active', true);
    }

    public function test_product_crud_validates_decimal_and_records_initial_stock(): void
    {
        $this->actAsAdmin();
        $category = Category::create(['nombre' => 'Panadería']);
        $created = $this->postJson('/api/admin/products', [
            'codigo' => 202, 'nombre' => 'Croissant', 'precio' => '29.90',
            'existencia' => 8, 'category_id' => $category->id,
        ])->assertCreated()->assertJsonPath('precio', '29.90')->json();

        $this->assertDatabaseHas('inventory_logs', ['product_codigo' => 202, 'cantidad' => 8, 'motivo' => 'Existencia inicial']);
        $this->putJson('/api/admin/products/202', ['nombre' => 'Croissant mantequilla', 'precio' => '31.25'])
            ->assertOk()->assertJsonPath('precio', '31.25');
        $this->putJson('/api/admin/products/202', ['precio' => '31.999'])->assertUnprocessable();
        $this->getJson('/api/admin/products/202')->assertOk()->assertJsonPath('category.id', $category->id);
        $this->patchJson('/api/admin/products/202/status', ['is_active' => false])
            ->assertOk()->assertJsonPath('is_active', false);
        $this->assertDatabaseHas('products', ['id' => $created['id'], 'is_active' => false]);
        $this->patchJson('/api/admin/products/202/status', ['is_active' => true])
            ->assertOk()->assertJsonPath('is_active', true);
    }

    public function test_inventory_adjustment_is_logged_and_cannot_make_stock_negative(): void
    {
        $this->actAsAdmin();
        Product::create(['codigo' => 303, 'nombre' => 'Té', 'precio' => '20.00', 'existencia' => 10]);

        $this->postJson('/api/admin/inventory/adjustments', ['codigo' => 303, 'cantidad' => -3, 'motivo' => 'Merma'])
            ->assertCreated()->assertJsonPath('product.existencia', 7);
        $this->assertDatabaseHas('inventory_logs', ['product_codigo' => 303, 'tipo_movimiento' => 'salida', 'cantidad' => 3, 'motivo' => 'Merma']);
        $this->postJson('/api/admin/inventory/adjustments', ['codigo' => 303, 'cantidad' => -8, 'motivo' => 'Error'])
            ->assertUnprocessable();
        $this->assertSame(7, Product::where('codigo', 303)->value('existencia'));
        $this->getJson('/api/admin/inventory')->assertOk()->assertJsonPath('0.motivo', 'Merma');
    }

    public function test_table_reservation_table_relation_is_available(): void
    {
        $table = DiningTable::create(['numero' => 'M1', 'capacidad' => 4]);
        $reservation = TableReservation::create([
            'folio' => 'R-1', 'cliente_nombre' => 'Ana', 'cliente_telefono' => '555',
            'fecha' => '2026-07-16', 'hora' => '12:00', 'personas' => 2,
            'dining_table_id' => $table->id,
        ]);

        $this->assertTrue($reservation->table->is($table));
        $this->assertTrue($table->reservations->contains($reservation));
    }

    private function actAsAdmin(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach($this->adminRole);
        Sanctum::actingAs($user, ['admin']);

        return $user;
    }
}
