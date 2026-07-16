<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TableReservation extends Model
{
    use HasFactory;
    protected $guarded = [];

    public function table()
    {
        return $this->belongsTo(DiningTable::class, 'dining_table_id');
    }
}
