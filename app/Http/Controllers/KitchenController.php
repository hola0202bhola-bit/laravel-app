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

        $order->update(['estado' => $validated['estado']]);

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
}
