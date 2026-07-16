<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminCategoryController extends Controller
{
    public function index()
    {
        return response()->json(Category::withCount('products')->orderBy('nombre')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:255', 'unique:categories,nombre'],
            'icono' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(Category::create($data), 201);
    }

    public function show(Category $category)
    {
        return response()->json($category->load('products'));
    }

    public function update(Request $request, Category $category)
    {
        $data = $request->validate([
            'nombre' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('categories', 'nombre')->ignore($category->id)],
            'icono' => ['nullable', 'string', 'max:255'],
        ]);

        $category->update($data);

        return response()->json($category->fresh()->loadCount('products'));
    }

    public function destroy(Category $category)
    {
        $category->products()->update(['category_id' => null]);
        $category->delete();

        return response()->noContent();
    }
}
