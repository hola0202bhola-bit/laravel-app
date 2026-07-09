<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::query();

        // Filter by Dietary Tag
        if ($request->filled('tag')) {
            $tagName = $request->tag;
            $taggedCodigos = DB::table('product_dietary_tags')
                ->join('dietary_tags', 'dietary_tags.id', '=', 'product_dietary_tags.dietary_tag_id')
                ->where('dietary_tags.nombre', 'LIKE', "%{$tagName}%")
                ->pluck('product_codigo');

            $query->where(function($q) use ($taggedCodigos, $tagName) {
                $q->whereIn('codigo', $taggedCodigos)
                  ->orWhere('nombre', 'LIKE', "%{$tagName}%");
            });
        }

        // Filter out Allergens
        if ($request->filled('exclude_allergen')) {
            $allergenName = $request->exclude_allergen;
            $excludedCodigos = DB::table('product_allergens')
                ->join('allergens', 'allergens.id', '=', 'product_allergens.allergen_id')
                ->where('allergens.nombre', 'LIKE', "%{$allergenName}%")
                ->pluck('product_codigo');

            $query->whereNotIn('codigo', $excludedCodigos);
        }

        // Sorting
        if ($request->sort === 'price_asc') {
            $query->orderBy('precio', 'asc');
        } elseif ($request->sort === 'price_desc') {
            $query->orderBy('precio', 'desc');
        } elseif ($request->sort === 'popularity') {
            $query->orderBy('existencia', 'asc'); // items with lower stock sold more
        } else {
            $query->orderBy('codigo', 'asc');
        }

        return response()->json($query->get());
    }

    public function customBases()
    {
        return response()->json(DB::table('custom_bases')->get());
    }

    public function customOptions()
    {
        return response()->json(DB::table('custom_options')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'codigo' => 'required|integer|unique:products,codigo',
            'nombre' => 'required|string',
            'precio' => 'required|numeric|min:0.01',
            'existencia' => 'required|integer|min:0',
            'descripcion' => 'nullable|string',
            'imagen' => 'nullable|string'
        ]);

        $standardExtras = [
            ['id' => 'extra_leche', 'nombre' => 'Leche de Almendras', 'precio' => 6.00],
            ['id' => 'extra_shot', 'nombre' => 'Shot de Espresso Extra', 'precio' => 8.00],
            ['id' => 'extra_jarabe', 'nombre' => 'Jarabe de Vainilla', 'precio' => 7.00],
            ['id' => 'extra_crema', 'nombre' => 'Crema Batida', 'precio' => 5.00]
        ];

        $product = Product::create([
            'codigo' => $validated['codigo'],
            'nombre' => $validated['nombre'],
            'precio' => $validated['precio'],
            'existencia' => $validated['existencia'],
            'descripcion' => $validated['descripcion'] ?? '',
            'imagen' => $validated['imagen'] ?? '',
            'extras' => $request->extras ?? $standardExtras
        ]);

        return response()->json([
            'message' => "Éxito: Producto '{$product->nombre}' registrado correctamente.",
            'producto' => $product
        ]);
    }

    public function reabastecer(Request $request)
    {
        $validated = $request->validate([
            'codigo' => 'required|integer',
            'cantidad' => 'required|integer|min:1'
        ]);

        $product = Product::where('codigo', $validated['codigo'])->firstOrFail();
        $product->increment('existencia', $validated['cantidad']);

        return response()->json([
            'message' => "Éxito: Inventario de '{$product->nombre}' actualizado a {$product->existencia} unidades."
        ]);
    }

    public function actualizarPrecio(Request $request)
    {
        $validated = $request->validate([
            'codigo' => 'required|integer',
            'nuevoPrecio' => 'required|numeric|min:0.01'
        ]);

        $product = Product::where('codigo', $validated['codigo'])->firstOrFail();
        $product->update(['precio' => $validated['nuevoPrecio']]);

        return response()->json([
            'message' => "Éxito: El precio de '{$product->nombre}' se actualizó a $" . number_format($product->precio, 2) . "."
        ]);
    }

    public function update(Request $request, $codigo)
    {
        $product = Product::where('codigo', $codigo)->firstOrFail();

        $product->update(array_filter([
            'nombre' => $request->nombre,
            'precio' => $request->precio,
            'existencia' => $request->existencia,
            'descripcion' => $request->descripcion,
            'imagen' => $request->imagen
        ], fn($val) => !is_null($val)));

        return response()->json([
            'message' => "Éxito: Producto '{$product->nombre}' actualizado correctamente.",
            'producto' => $product
        ]);
    }
}
