<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryLog extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'product_codigo' => 'integer',
        'cantidad' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_codigo', 'codigo');
    }
}
