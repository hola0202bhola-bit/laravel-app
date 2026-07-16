<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\DiningTable;
use Illuminate\Support\Str;
use App\Services\ReservationService;

class ReservationController extends Controller
{
    public function __construct(private ReservationService $reservations)
    {
    }

    public function tables()
    {
        return response()->json(DiningTable::orderBy('numero', 'asc')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'cliente_nombre' => 'required|string|max:255',
            'cliente_telefono' => 'required|string|max:50',
            'fecha' => 'required|date_format:Y-m-d|after_or_equal:today',
            'hora' => 'required|date_format:H:i',
            'personas' => 'required|integer|min:1',
            'dining_table_id' => 'required|exists:dining_tables,id',
            'notas' => 'nullable|string'
        ]);

        $table = DiningTable::findOrFail($validated['dining_table_id']);
        $folio = 'RES-' . strtoupper(Str::random(6));
        $reservation = $this->reservations->create([
            'folio' => $folio,
            'cliente_nombre' => $validated['cliente_nombre'],
            'cliente_telefono' => $validated['cliente_telefono'],
            'fecha' => $validated['fecha'],
            'hora' => $validated['hora'],
            'personas' => $validated['personas'],
            'dining_table_id' => $table->id,
            'notas' => $validated['notas'] ?? ''
        ]);

        return response()->json([
            'message' => "Éxito: Reservación {$folio} registrada para {$table->numero}.",
            'reservacion' => $reservation
        ], 201);
    }
}
