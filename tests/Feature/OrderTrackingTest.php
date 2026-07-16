<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

class OrderTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_token_returns_correct_filtered_payload()
    {
        $token = Str::uuid()->toString();
        $order = Order::create([
            'estado' => 'pendiente',
            'estado_preparacion' => 'en_preparacion',
            'tracking_token' => $token,
            'lock_version' => 0,
            'tipo_pedido' => 'llevar',
            'metodo_pago' => 'efectivo',
            'total' => 95.00,
            'items' => [
                ['id' => 'item_1', 'nombre' => 'Americano', 'tamano' => 'Chico', 'cantidad' => 1, 'estado' => 'listo', 'precioBase' => 35.00],
                ['id' => 'item_2', 'nombre' => 'Croissant', 'tamano' => 'Grande', 'cantidad' => 2, 'estado' => 'pendiente', 'precioBase' => 30.00]
            ]
        ]);

        $response = $this->withHeaders(['X-Tracking-Token' => $token])
            ->getJson('/api/pedidos/seguimiento');

        $response->assertStatus(200);
        $response->assertJson([
            'pedido_id' => $order->id,
            'estado_preparacion' => 'en_preparacion',
            'items' => [
                ['nombre' => 'Americano', 'tamano' => 'Chico', 'cantidad' => 1, 'estado' => 'listo'],
                ['nombre' => 'Croissant', 'tamano' => 'Grande', 'cantidad' => 2, 'estado' => 'pendiente']
            ]
        ]);

        // Assert it does NOT leak prices, payment methods, or tracking tokens in the response
        $response->assertJsonMissing(['total' => 95.00]);
        $response->assertJsonMissing(['metodo_pago' => 'efectivo']);
        $response->assertJsonMissing(['tracking_token' => $token]);
        $response->assertJsonMissing(['precioBase' => 35.00]);
    }

    public function test_invalid_token_returns_404()
    {
        $response = $this->withHeaders(['X-Tracking-Token' => 'invalid-token-uuid'])
            ->getJson('/api/pedidos/seguimiento');

        $response->assertStatus(404);
    }

    public function test_missing_token_header_returns_400()
    {
        $response = $this->getJson('/api/pedidos/seguimiento');
        $response->assertStatus(400);
    }
}
