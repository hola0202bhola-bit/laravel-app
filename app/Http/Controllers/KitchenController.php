<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

class KitchenController extends Controller
{
    public function index()
    {
        $activeOrders = Order::whereIn('estado', ['pendiente', 'en_preparacion', 'listo'])
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json($activeOrders);
    }

    public function updateStatus(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required|integer',
            'estado' => 'required|string'
        ]);

        $order = Order::findOrFail($validated['order_id']);
        $estadoAnterior = $order->estado;

        // Update all non-cancelled items to the new state
        $items = $order->items;
        if (is_array($items)) {
            foreach ($items as &$item) {
                if (($item['estado'] ?? 'pendiente') !== 'cancelado') {
                    $item['estado'] = $validated['estado'];
                }
            }
        }

        $order->items = $items;
        $order->save();

        // Record Audit History
        DB::table('order_status_histories')->insert([
            'order_id' => $order->id,
            'estado_anterior' => $estadoAnterior,
            'estado_nuevo' => $validated['estado'],
            'cambiado_en' => now(),
            'created_at' => now(),
            'updated_at' => now()
        ]);

        return response()->json([
            'message' => "Éxito: Orden #{$order->id} movida a '{$validated['estado']}'.",
            'order' => $order
        ]);
    }

    public function updateItemStatus(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required|integer',
            'item_id' => 'required|string',
            'estado' => 'required|string|in:pendiente,en_preparacion,listo,cancelado'
        ]);

        $order = Order::findOrFail($validated['order_id']);
        $items = $order->items;
        $updated = false;

        if (is_array($items)) {
            foreach ($items as &$item) {
                if (isset($item['id']) && $item['id'] === $validated['item_id']) {
                    $item['estado'] = $validated['estado'];
                    $updated = true;
                    break;
                }
            }
        }

        if (!$updated) {
            return response()->json(['error' => 'Error: Producto no encontrado en el pedido.'], 404);
        }

        $order->items = $items;
        $order->save();

        return response()->json([
            'message' => "Éxito: Estado del producto actualizado a '{$validated['estado']}'.",
            'order' => $order
        ]);
    }
}
