<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;

class OrderTrackingController extends Controller
{
    public function track(Request $request)
    {
        $token = $request->header('X-Tracking-Token');

        if (!$token) {
            return response()->json(['error' => 'Token de seguimiento faltante.'], 400);
        }

        // Fetch order matching the tracking token
        $order = Order::where('tracking_token', $token)->first();

        if (!$order) {
            return response()->json(['error' => 'Pedido no encontrado.'], 404);
        }

        // Map minimal item data to prevent leaking pricing or administrative details
        $mappedItems = array_map(function ($item) {
            return [
                'nombre' => $item['nombre'] ?? 'Producto',
                'tamano' => $item['tamano'] ?? 'Chico',
                'cantidad' => intval($item['cantidad'] ?? 1),
                'estado' => $item['estado'] ?? 'pendiente'
            ];
        }, $order->items ?? []);

        return response()->json([
            'pedido_id' => $order->id,
            'estado_preparacion' => $order->estado_preparacion,
            'items' => $mappedItems,
            'actualizado_hace' => $order->updated_at->diffForHumans()
        ]);
    }
}
