<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportsAndExportsTest extends TestCase
{
    use RefreshDatabase;

    private Role $adminRole;
    private Role $managerRole;
    private Role $cookRole;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-16 12:00:00');
        $this->adminRole = Role::create(['nombre' => 'Administrador']);
        $this->managerRole = Role::create(['nombre' => 'Gerente']);
        $this->cookRole = Role::create(['nombre' => 'Barista/Cocinero']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_reports_and_exports_require_authentication(): void
    {
        $this->getJson('/api/admin/reports')->assertUnauthorized();
        $this->getJson('/api/admin/reports/exports/sales')->assertUnauthorized();
        $this->getJson('/api/admin/reports/exports/orders')->assertUnauthorized();
    }

    public function test_non_administrative_role_is_forbidden(): void
    {
        $this->actAs($this->cookRole);

        $this->getJson('/api/admin/reports')->assertForbidden();
        $this->getJson('/api/admin/reports/exports/sales')->assertForbidden();
    }

    public function test_administrator_and_manager_can_access_reports(): void
    {
        foreach ([$this->adminRole, $this->managerRole] as $role) {
            $this->actAs($role);
            $this->getJson('/api/admin/reports')
                ->assertOk()
                ->assertJsonPath('summary.total_sales', '0.00');
        }
    }

    public function test_employee_dashboard_shows_report_controls_to_administrator_and_manager(): void
    {
        foreach ([$this->adminRole, $this->managerRole] as $role) {
            $user = User::factory()->create();
            $user->roles()->attach($role);
            $this->actingAs($user)->get('/empleado')
                ->assertOk()
                ->assertSee('Reportes de Ventas y Pedidos')
                ->assertSee('CSV ventas')
                ->assertSee('CSV pedidos');
        }
    }

    public function test_default_period_is_last_thirty_inclusive_days(): void
    {
        $this->actAs($this->adminRole);

        $this->getJson('/api/admin/reports')
            ->assertOk()
            ->assertJsonPath('period.start', '2026-06-17')
            ->assertJsonPath('period.end', '2026-07-16');
    }

    public function test_invalid_dates_and_excessive_ranges_are_rejected(): void
    {
        $this->actAs($this->adminRole);

        $this->getJson('/api/admin/reports?fecha_inicio=no-es-fecha')
            ->assertUnprocessable()->assertJsonValidationErrors('fecha_inicio');
        $this->getJson('/api/admin/reports?fecha_inicio=2026-07-10&fecha_fin=2026-07-01')
            ->assertUnprocessable()->assertJsonValidationErrors('fecha_fin');
        $this->getJson('/api/admin/reports?fecha_inicio=2025-01-01&fecha_fin=2026-07-16')
            ->assertUnprocessable()->assertJsonValidationErrors('fecha_fin');
    }

    public function test_date_filters_include_both_day_boundaries(): void
    {
        $this->actAs($this->adminRole);
        $this->sale('10.00', '2026-06-01 00:00:00');
        $this->sale('20.00', '2026-06-02 23:59:59');
        $this->sale('40.00', '2026-05-31 23:59:59');
        $this->sale('80.00', '2026-06-03 00:00:00');
        $this->order('10.00', '2026-06-01 00:00:00');
        $this->order('20.00', '2026-06-02 23:59:59');
        $this->order('40.00', '2026-05-31 23:59:59');

        $this->getJson($this->reportUrl('2026-06-01', '2026-06-02'))
            ->assertOk()
            ->assertJsonPath('summary.total_sales', '30.00')
            ->assertJsonPath('summary.order_count', 2);
    }

    public function test_period_without_results_returns_zeroed_report(): void
    {
        $this->actAs($this->managerRole);

        $this->getJson($this->reportUrl('2024-01-01', '2024-01-31'))
            ->assertOk()
            ->assertJsonPath('summary.total_sales', '0.00')
            ->assertJsonPath('summary.order_count', 0)
            ->assertJsonPath('summary.average_ticket', '0.00')
            ->assertJsonCount(0, 'daily_sales')
            ->assertJsonCount(0, 'top_products')
            ->assertJsonCount(0, 'orders_by_status');
    }

    public function test_totals_and_average_ticket_use_exact_decimal_arithmetic(): void
    {
        $this->actAs($this->adminRole);
        $this->sale('10.01', '2026-07-10 10:00:00');
        $this->sale('0.02', '2026-07-10 11:00:00');
        $this->order('10.01', '2026-07-10 10:00:00');
        $this->order('0.02', '2026-07-10 11:00:00');

        $this->getJson($this->reportUrl('2026-07-10', '2026-07-10'))
            ->assertOk()
            ->assertJsonPath('summary.total_sales', '10.03')
            ->assertJsonPath('summary.order_count', 2)
            ->assertJsonPath('summary.average_ticket', '5.02');
    }

    public function test_sales_are_grouped_by_day_with_exact_totals(): void
    {
        $this->actAs($this->managerRole);
        $this->sale('1.10', '2026-07-08 08:00:00');
        $this->sale('2.20', '2026-07-08 18:00:00');
        $this->sale('4.40', '2026-07-09 09:00:00');

        $this->getJson($this->reportUrl('2026-07-08', '2026-07-09'))
            ->assertOk()
            ->assertJsonPath('daily_sales.0.date', '2026-07-08')
            ->assertJsonPath('daily_sales.0.total', '3.30')
            ->assertJsonPath('daily_sales.1.date', '2026-07-09')
            ->assertJsonPath('daily_sales.1.total', '4.40');
    }

    public function test_top_products_and_order_statuses_are_aggregated(): void
    {
        $this->actAs($this->adminRole);
        $this->sale('70.00', '2026-07-05 10:00:00', [
            $this->item(1, 'Café', 2, '70.00'),
            $this->item(2, 'Muffin', 4, '40.00'),
        ]);
        $this->sale('105.00', '2026-07-06 10:00:00', [$this->item(1, 'Café', 3, '105.00')]);
        $this->order('10.00', '2026-07-05 10:00:00', 'pendiente');
        $this->order('10.00', '2026-07-06 10:00:00', 'entregado');
        $this->order('10.00', '2026-07-06 11:00:00', 'entregado');

        $this->getJson($this->reportUrl('2026-07-01', '2026-07-10'))
            ->assertOk()
            ->assertJsonPath('top_products.0.nombre', 'Café')
            ->assertJsonPath('top_products.0.cantidad', 5)
            ->assertJsonPath('top_products.0.total', '175.00')
            ->assertJsonFragment(['status' => 'entregado', 'count' => 2])
            ->assertJsonFragment(['status' => 'pendiente', 'count' => 1]);
    }

    public function test_low_inventory_report_only_includes_products_at_or_below_threshold(): void
    {
        $this->actAs($this->managerRole);
        Product::create(['codigo' => 10, 'nombre' => 'Stock bajo', 'precio' => '20.00', 'existencia' => 5]);
        Product::create(['codigo' => 11, 'nombre' => 'Stock suficiente', 'precio' => '20.00', 'existencia' => 6]);

        $this->getJson('/api/admin/reports')
            ->assertOk()
            ->assertJsonCount(1, 'low_inventory')
            ->assertJsonPath('low_inventory.0.nombre', 'Stock bajo')
            ->assertJsonPath('low_inventory.0.existencia', 5);
    }

    public function test_csv_exports_have_utf8_headers_and_respect_date_range(): void
    {
        $this->actAs($this->managerRole);
        $this->sale('12.50', '2026-07-10 10:00:00', [$this->item(1, 'Café dentro', 1, '12.50')]);
        $this->sale('50.00', '2026-07-11 10:00:00', [$this->item(2, 'Fuera', 1, '50.00')]);
        $this->order('12.50', '2026-07-10 10:00:00', 'entregado', 'Café dentro');

        $sales = $this->get('/api/admin/reports/exports/sales?fecha_inicio=2026-07-10&fecha_fin=2026-07-10')
            ->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $sales->getContent());
        $this->assertStringContainsString('"ID venta",Fecha,Total,Productos', $sales->getContent());
        $this->assertStringContainsString('Café dentro', $sales->getContent());
        $this->assertStringNotContainsString('Fuera', $sales->getContent());

        $orders = $this->get('/api/admin/reports/exports/orders?fecha_inicio=2026-07-10&fecha_fin=2026-07-10')
            ->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('"ID pedido",Fecha,Estado,"Tipo de pedido",Mesa,"Método de pago",Total,Productos', $orders->getContent());
        $this->assertStringContainsString('Café dentro', $orders->getContent());
    }

    public function test_csv_exports_escape_formula_prefixes(): void
    {
        $this->actAs($this->adminRole);
        $this->sale('1.00', '2026-07-10 10:00:00', [$this->item(1, '=2+2', 1, '1.00')]);
        $this->order('1.00', '2026-07-10 10:00:00', '@estado', '-PRODUCTO');

        $sales = $this->get('/api/admin/reports/exports/sales?fecha_inicio=2026-07-10&fecha_fin=2026-07-10')->getContent();
        $orders = $this->get('/api/admin/reports/exports/orders?fecha_inicio=2026-07-10&fecha_fin=2026-07-10')->getContent();

        $this->assertStringContainsString("'=2+2 x1", $sales);
        $this->assertStringContainsString("'@estado", $orders);
        $this->assertStringContainsString("'-PRODUCTO", $orders);
    }

    public function test_report_queries_run_on_supported_sqlite_or_postgresql_driver(): void
    {
        $this->assertContains(DB::connection()->getDriverName(), ['sqlite', 'pgsql']);
        $this->actAs($this->managerRole);
        $this->sale('9.99', '2026-07-10 10:00:00');

        $this->getJson($this->reportUrl('2026-07-10', '2026-07-10'))
            ->assertOk()->assertJsonPath('summary.total_sales', '9.99');
    }

    private function actAs(Role $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach($role);
        Sanctum::actingAs($user, ['admin']);

        return $user;
    }

    private function sale(string $total, string $createdAt, array $items = []): Sale
    {
        return Sale::create(['items' => $items, 'total' => $total, 'created_at' => $createdAt, 'updated_at' => $createdAt]);
    }

    private function order(
        string $total,
        string $createdAt,
        string $status = 'pendiente',
        string $itemName = 'Producto'
    ): Order {
        return Order::create([
            'estado' => $status,
            'tipo_pedido' => 'mesa',
            'numero_mesa' => $itemName,
            'metodo_pago' => 'efectivo',
            'items' => [$this->item(1, $itemName, 1, $total)],
            'total' => $total,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function item(int $code, string $name, int $quantity, string $subtotal): array
    {
        return ['codigo' => $code, 'nombre' => $name, 'cantidad' => $quantity, 'subtotal' => $subtotal];
    }

    private function reportUrl(string $start, string $end): string
    {
        return "/api/admin/reports?fecha_inicio={$start}&fecha_fin={$end}";
    }
}
