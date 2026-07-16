<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InventoryLog;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminInventoryController extends Controller
{
    public function index()
    {
        return response()->json(
            InventoryLog::with('product:id,codigo,nombre')
                ->latest()
                ->limit(50)
                ->get()
        );
    }

    public function adjust(Request $request)
    {
        $data = $request->validate([
            'codigo' => ['required', 'integer', 'exists:products,codigo'],
            'cantidad' => ['required', 'integer', 'not_in:0'],
            'motivo' => ['required', 'string', 'max:255'],
        ]);

        [$product, $log] = DB::transaction(function () use ($data) {
            $product = Product::where('codigo', $data['codigo'])->lockForUpdate()->firstOrFail();
            $newStock = $product->existencia + $data['cantidad'];

            if ($newStock < 0) {
                throw ValidationException::withMessages([
                    'cantidad' => 'El ajuste dejaría existencias negativas.',
                ]);
            }

            $product->update(['existencia' => $newStock]);
            $log = InventoryLog::create([
                'product_codigo' => $product->codigo,
                'tipo_movimiento' => $data['cantidad'] > 0 ? 'entrada' : 'salida',
                'cantidad' => abs($data['cantidad']),
                'motivo' => $data['motivo'],
            ]);

            return [$product->fresh(), $log];
        });

        return response()->json(['product' => $product, 'movement' => $log], 201);
    }
}
