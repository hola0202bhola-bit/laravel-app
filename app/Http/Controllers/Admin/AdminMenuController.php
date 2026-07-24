<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Menu;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminMenuController extends Controller
{
    public function index()
    {
        return response()->json(
            Menu::with(['products' => fn ($query) => $query->orderBy('codigo')])
                ->orderBy('name')
                ->get()
        );
    }

    public function store(Request $request)
    {
        $data = $this->validateMenu($request);

        return response()->json(Menu::create($data)->load('products'), 201);
    }

    public function show(Menu $menu)
    {
        return response()->json($menu->load(['products' => fn ($query) => $query->orderBy('codigo')]));
    }

    public function update(Request $request, Menu $menu)
    {
        $menu->update($this->validateMenu($request, $menu));

        return response()->json($menu->fresh()->load('products'));
    }

    public function updateStatus(Request $request, Menu $menu)
    {
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $menu->update($data);

        return response()->json($menu->fresh()->load('products'));
    }

    public function addProduct(Menu $menu, Product $product)
    {
        $menu->products()->syncWithoutDetaching([$product->id]);

        return response()->json($menu->fresh()->load('products'));
    }

    public function removeProduct(Menu $menu, Product $product)
    {
        $menu->products()->detach($product->id);

        return response()->json($menu->fresh()->load('products'));
    }

    private function validateMenu(Request $request, ?Menu $menu = null): array
    {
        $required = $menu ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:255', Rule::unique('menus', 'name')->ignore($menu?->id)],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);
    }
}
