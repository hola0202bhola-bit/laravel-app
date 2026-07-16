<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::query();

        if ($request->filled('tag')) {
            $tagName = $request->tag;
            $taggedCodes = DB::table('product_dietary_tags')
                ->join('dietary_tags', 'dietary_tags.id', '=', 'product_dietary_tags.dietary_tag_id')
                ->where('dietary_tags.nombre', 'LIKE', "%{$tagName}%")
                ->pluck('product_codigo');
            $query->where(fn ($q) => $q->whereIn('codigo', $taggedCodes)
                ->orWhere('nombre', 'LIKE', "%{$tagName}%"));
        }

        if ($request->filled('exclude_allergen')) {
            $allergenName = $request->exclude_allergen;
            $excludedCodes = DB::table('product_allergens')
                ->join('allergens', 'allergens.id', '=', 'product_allergens.allergen_id')
                ->where('allergens.nombre', 'LIKE', "%{$allergenName}%")
                ->pluck('product_codigo');
            $query->whereNotIn('codigo', $excludedCodes);
        }

        match ($request->sort) {
            'price_asc' => $query->orderBy('precio'),
            'price_desc' => $query->orderByDesc('precio'),
            'popularity' => $query->orderBy('existencia'),
            default => $query->orderBy('codigo'),
        };

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
}
