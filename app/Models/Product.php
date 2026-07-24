<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $attributes = [
        'is_active' => true,
        'is_available' => true,
    ];

    protected $casts = [
        'extras' => 'array',
        'precio' => 'decimal:2',
        'existencia' => 'integer',
        'codigo' => 'integer',
        'category_id' => 'integer',
        'is_active' => 'boolean',
        'is_available' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function menus()
    {
        return $this->belongsToMany(Menu::class)->withTimestamps();
    }

    public function scopeSellable(Builder $query): Builder
    {
        return $query
            ->where('products.is_active', true)
            ->where('products.is_available', true)
            ->where(fn (Builder $categoryQuery) => $categoryQuery
                ->whereNull('products.category_id')
                ->orWhereHas('category', fn (Builder $query) => $query->where('is_active', true)))
            ->whereHas('menus', fn (Builder $query) => $query->where('menus.is_active', true));
    }

    public function isSellable(): bool
    {
        if (!$this->is_active || !$this->is_available) {
            return false;
        }

        if ($this->category_id !== null && !$this->category()->where('is_active', true)->exists()) {
            return false;
        }

        return $this->menus()->where('menus.is_active', true)->exists();
    }
}
