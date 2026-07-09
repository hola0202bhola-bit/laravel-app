<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\Product;
use App\Models\Sale;

class OrderController extends Controller
{
    public function index()
    {
        return response()->json(Order::orderBy('id', 'asc')->get());
    }

    public function sales()
    {
        return response()->json(Sale::orderBy('id', 'asc')->get());
    }

    public function store(Request $request)
    {
        $items = $request->input('items', []);

        if (empty($items)) {
            return response()->json(['error' => 'Error: El pedido no contiene productos.'], 400);
        }

        // Validate stock
        foreach ($items as $item) {
            $product = Product::where('codigo', $item['codigo'])->first();
            if (!$product) {
                return response()->json(['error' => "Error: El producto con código {$item['codigo']} no existe."], 404);
            }
            if ($item['cantidad'] > $product->existencia) {
                return response()->json(['error' => "Error: No hay suficiente stock de '{$product->nombre}'. Disponible: {$product->existencia}"], 400);
            }
        }

        // Deduct stock & Process item pricing
        $processedItems = [];
        $grandTotal = 0;

        foreach ($items as $item) {
            $product = Product::where('codigo', $item['codigo'])->first();
            $product->decrement('existencia', intval($item['cantidad']));

            $unitPrice = $product->precio;
            if (($item['tamano'] ?? '') === 'Mediano') $unitPrice += 5.00;
            if (($item['tamano'] ?? '') === 'Grande') $unitPrice += 10.00;

            $extrasCost = 0;
            $extrasList = $item['extras'] ?? [];
            foreach ($extrasList as $ext) {
                $extrasCost += floatval($ext['precio']);
            }

            $finalUnitPrice = $unitPrice + $extrasCost;
            $subtotal = $finalUnitPrice * intval($item['cantidad']);
            $grandTotal += $subtotal;

            $processedItems[] = [
                'codigo' => $product->codigo,
                'nombre' => $product->nombre,
                'tamano' => $item['tamano'] ?? 'Chico',
                'precioBase' => $product->precio,
                'extras' => $extrasList,
                'precioFinalUnitario' => $finalUnitPrice,
                'cantidad' => intval($item['cantidad']),
                'subtotal' => $subtotal
            ];
        }

        // Generate Rappi delivery code if applicable
        $codigoDelivery = null;
        $tipoPedido = $request->input('tipoPedido', 'llevar');
        if ($tipoPedido === 'delivery') {
            $codigoDelivery = '#R-' . rand(1000, 9999);
        }

        // Save Order
        $order = Order::create([
            'estado' => 'pendiente',
            'tipo_pedido' => $tipoPedido,
            'numero_mesa' => $tipoPedido === 'mesa' ? ($request->input('numeroMesa') ?? 'Mesa 1') : null,
            'codigo_delivery' => $codigoDelivery,
            'metodo_pago' => $request->input('metodoPago', 'efectivo'),
            'items' => $processedItems,
            'total' => $grandTotal
        ]);

        // Save Sale record
        Sale::create([
            'items' => $processedItems,
            'total' => $grandTotal
        ]);

        return response()->json([
            'message' => "Éxito: Pedido #{$order->id} registrado correctamente.",
            'pedido' => [
                'id' => $order->id,
                'fecha' => $order->created_at->toISOString(),
                'estado' => $order->estado,
                'tipoPedido' => $order->tipo_pedido,
                'numeroMesa' => $order->numero_mesa,
                'codigoDelivery' => $order->codigo_delivery,
                'metodoPago' => $order->metodo_pago,
                'items' => $order->items,
                'total' => $order->total
            ]
        ]);
    }

    public function updateStatus(Request $request)
    {
        $id = $request->input('id');
        $estado = $request->input('estado');

        $order = Order::findOrFail($id);
        $order->update(['estado' => $estado]);

        return response()->json([
            'message' => "Éxito: El pedido #{$order->id} ha sido {$estado}.",
            'pedido' => $order
        ]);
    }
}
