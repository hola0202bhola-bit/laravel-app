<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Menu;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CatalogMenuLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Role $adminRole;
    private Role $cookRole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminRole = Role::create(['nombre' => 'Administrador']);
        $this->cookRole = Role::create(['nombre' => 'Barista/Cocinero']);
    }

    public function test_catalog_lifecycle_admin_endpoints_require_authorization(): void
    {
        $this->getJson('/api/admin/menus')->assertUnauthorized();

        $user = User::factory()->create();
        $user->roles()->attach($this->cookRole);
        Sanctum::actingAs($user, ['admin']);

        $product = $this->product(901);
        $category = Category::create(['nombre' => 'Restringida']);

        $this->patchJson("/api/admin/products/{$product->codigo}/status", ['is_active' => false])
            ->assertForbidden();
        $this->patchJson("/api/admin/categories/{$category->id}/status", ['is_active' => false])
            ->assertForbidden();
        $this->postJson('/api/admin/menus', ['name' => 'No autorizado'])->assertForbidden();
    }

    public function test_employee_interface_exposes_non_destructive_catalog_controls(): void
    {
        $user = $this->actAsAdmin();

        $this->actingAs($user)->get('/empleado')
            ->assertOk()
            ->assertSee('Menús y composición')
            ->assertSee('Vigencia manual, sin horarios automáticos.')
            ->assertSee('Desactivar producto')
            ->assertSee('Suspender venta')
            ->assertDontSee('Eliminar producto');
    }

    public function test_product_can_be_deactivated_and_reactivated_without_losing_history(): void
    {
        $this->actAsAdmin();
        $product = $this->sellableProduct(902);
        $this->recordHistory($product);

        $this->getJson('/api/productos')->assertJsonFragment(['codigo' => 902]);
        $this->patchJson('/api/admin/products/902/status', ['is_active' => false])
            ->assertOk()->assertJsonPath('is_active', false);

        $this->getJson('/api/productos')->assertJsonMissing(['codigo' => 902]);
        $this->postOrder(902)->assertStatus(409);
        $this->getJson('/api/admin/products')->assertJsonFragment(['codigo' => 902, 'is_active' => false]);
        $this->assertDatabaseHas('products', ['codigo' => 902, 'is_active' => false]);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('sales', 1);

        $this->patchJson('/api/admin/products/902/status', ['is_active' => true])
            ->assertOk()->assertJsonPath('is_active', true);
        $this->getJson('/api/productos')->assertJsonFragment(['codigo' => 902]);
    }

    public function test_product_can_be_temporarily_suspended_and_resumed(): void
    {
        $this->actAsAdmin();
        $this->sellableProduct(903);

        $this->patchJson('/api/admin/products/903/availability', ['is_available' => false])
            ->assertOk()
            ->assertJsonPath('is_active', true)
            ->assertJsonPath('is_available', false);

        $this->getJson('/api/productos')->assertJsonMissing(['codigo' => 903]);
        $this->postOrder(903)->assertStatus(409);
        $this->assertDatabaseHas('products', [
            'codigo' => 903,
            'is_active' => true,
            'is_available' => false,
        ]);

        $this->patchJson('/api/admin/products/903/availability', ['is_available' => true])
            ->assertOk()->assertJsonPath('is_available', true);
        $this->getJson('/api/productos')->assertJsonFragment(['codigo' => 903]);
        $this->postOrder(903)->assertOk();
    }

    public function test_category_deactivation_hides_products_without_modifying_them(): void
    {
        $this->actAsAdmin();
        $category = Category::create(['nombre' => 'Temporada']);
        $product = $this->sellableProduct(904, $category);

        $this->patchJson("/api/admin/categories/{$category->id}/status", ['is_active' => false])
            ->assertOk()->assertJsonPath('is_active', false);

        $this->assertSame($category->id, $product->fresh()->category_id);
        $this->assertTrue($product->fresh()->is_active);
        $this->getJson('/api/productos')->assertJsonMissing(['codigo' => 904]);
        $this->postOrder(904)->assertStatus(409);

        $this->patchJson("/api/admin/categories/{$category->id}/status", ['is_active' => true])
            ->assertOk()->assertJsonPath('is_active', true);
        $this->getJson('/api/productos')->assertJsonFragment(['codigo' => 904]);
    }

    public function test_menu_lifecycle_and_composition_do_not_delete_products(): void
    {
        $this->actAsAdmin();
        $product = $this->product(905);

        $menu = $this->postJson('/api/admin/menus', [
            'name' => 'Barra principal',
            'description' => 'Oferta manual',
        ])->assertCreated()->assertJsonPath('is_active', true)->json();

        $this->putJson("/api/admin/menus/{$menu['id']}", [
            'name' => 'Barra y vitrina',
            'description' => 'Composición monositio',
        ])->assertOk()->assertJsonPath('name', 'Barra y vitrina');

        $this->putJson("/api/admin/menus/{$menu['id']}/products/905")
            ->assertOk()->assertJsonFragment(['codigo' => 905]);
        $this->getJson('/api/productos')->assertJsonFragment(['codigo' => 905]);

        $this->patchJson("/api/admin/menus/{$menu['id']}/status", ['is_active' => false])
            ->assertOk()->assertJsonPath('is_active', false);
        $this->getJson('/api/productos')->assertJsonMissing(['codigo' => 905]);
        $this->postOrder(905)->assertStatus(409);

        $this->patchJson("/api/admin/menus/{$menu['id']}/status", ['is_active' => true])
            ->assertOk()->assertJsonPath('is_active', true);
        $this->deleteJson("/api/admin/menus/{$menu['id']}/products/905")
            ->assertOk()->assertJsonMissing(['codigo' => 905]);

        $this->assertDatabaseHas('products', ['codigo' => 905]);
        $this->assertDatabaseMissing('menu_product', ['menu_id' => $menu['id'], 'product_id' => $product->id]);
        $this->getJson('/api/productos')->assertJsonMissing(['codigo' => 905]);
    }

    private function actAsAdmin(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach($this->adminRole);
        Sanctum::actingAs($user, ['admin']);

        return $user;
    }

    private function product(int $code, ?Category $category = null): Product
    {
        return Product::create([
            'codigo' => $code,
            'nombre' => "Producto {$code}",
            'precio' => '25.00',
            'existencia' => 10,
            'category_id' => $category?->id,
        ]);
    }

    private function sellableProduct(int $code, ?Category $category = null): Product
    {
        $product = $this->product($code, $category);
        $menu = Menu::create(['name' => "Menú {$code}", 'is_active' => true]);
        $menu->products()->attach($product);

        return $product;
    }

    private function postOrder(int $code)
    {
        return $this->postJson('/api/pedidos/crear', [
            'tipoPedido' => 'llevar',
            'metodoPago' => 'efectivo',
            'items' => [[
                'codigo' => $code,
                'tamano' => 'Chico',
                'cantidad' => 1,
                'extras' => [],
            ]],
        ]);
    }

    private function recordHistory(Product $product): void
    {
        $items = [[
            'codigo' => $product->codigo,
            'nombre' => $product->nombre,
            'cantidad' => 1,
            'subtotal' => '25.00',
        ]];

        Order::create([
            'estado' => 'entregado',
            'estado_preparacion' => 'listo',
            'tipo_pedido' => 'llevar',
            'metodo_pago' => 'efectivo',
            'items' => $items,
            'total' => '25.00',
        ]);
        Sale::create(['items' => $items, 'total' => '25.00']);
    }
}
