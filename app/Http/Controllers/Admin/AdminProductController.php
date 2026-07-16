<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InventoryLog;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminProductController extends Controller
{
    public function index()
    {
        return response()->json(Product::with('category')->orderBy('codigo')->get());
    }

    public function store(Request $request)
    {
        $data = $this->validateProduct($request);

        $product = DB::transaction(function () use ($data) {
            $stock = $data['existencia'];
            $product = Product::create($data);

            if ($stock > 0) {
                InventoryLog::create([
                    'product_codigo' => $product->codigo,
                    'tipo_movimiento' => 'entrada',
                    'cantidad' => $stock,
                    'motivo' => 'Existencia inicial',
                ]);
            }

            return $product;
        });

        return response()->json($product->load('category'), 201);
    }

    public function show(Product $product)
    {
        return response()->json($product->load('category'));
    }

    public function update(Request $request, Product $product)
    {
        $data = $this->validateProduct($request, $product);
        unset($data['existencia']);
        $product->update($data);

        return response()->json($product->fresh()->load('category'));
    }

    public function destroy(Product $product)
    {
        $hasSales = DB::table('sale_details')->where('product_codigo', $product->codigo)->exists();
        if ($hasSales) {
            return response()->json([
                'message' => 'No se puede eliminar un producto con ventas registradas.',
            ], 409);
        }

        DB::transaction(function () use ($product) {
            foreach (['product_allergens', 'product_dietary_tags', 'product_recipes', 'product_extras'] as $table) {
                DB::table($table)->where('product_codigo', $product->codigo)->delete();
            }
            $product->delete();
        });

        return response()->noContent();
    }

    private function validateProduct(Request $request, ?Product $product = null): array
    {
        $required = $product ? 'sometimes' : 'required';

        return $request->validate([
            'codigo' => [$required, 'integer', Rule::unique('products', 'codigo')->ignore($product?->id)],
            'nombre' => [$required, 'string', 'max:255'],
            'precio' => [$required, 'regex:/^\\d{1,6}(?:\\.\\d{1,2})?$/'],
            'existencia' => [$required, 'integer', 'min:0'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'descripcion' => ['nullable', 'string'],
            'imagen' => ['nullable', 'string', 'max:2048'],
            'extras' => ['nullable', 'array'],
        ]);
    }
}
