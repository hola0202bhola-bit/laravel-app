<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DiningTable extends Model
{
    use HasFactory;
    protected $guarded = [];

    public function reservations()
    {
        return $this->hasMany(TableReservation::class);
    }

    public function activeReservations()
    {
        return $this->reservations()->whereIn('estado', ['pendiente', 'confirmada', 'ocupada']);
    }
}
