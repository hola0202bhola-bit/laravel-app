<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'items' => 'array',
        'total' => 'decimal:2'
    ];
}
