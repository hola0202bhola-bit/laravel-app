<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TableReservation extends Model
{
    use HasFactory;
    protected $guarded = [];

    protected $casts = [
        'personas' => 'integer',
        'dining_table_id' => 'integer',
    ];

    public function getHoraAttribute(?string $value): ?string
    {
        return $value === null ? null : substr($value, 0, 5);
    }

    public function table()
    {
        return $this->belongsTo(DiningTable::class, 'dining_table_id');
    }
}
