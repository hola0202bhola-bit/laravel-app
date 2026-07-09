<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\DiningTable;
use App\Models\TableReservation;
use Illuminate\Support\Str;

class ReservationController extends Controller
{
    public function tables()
    {
        return response()->json(DiningTable::orderBy('numero', 'asc')->get());
    }

    public function index()
    {
        return response()->json(TableReservation::with('table')->orderBy('fecha', 'desc')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'cliente_nombre' => 'required|string',
            'cliente_telefono' => 'required|string',
            'fecha' => 'required|date',
            'hora' => 'required',
            'personas' => 'required|integer|min:1',
            'dining_table_id' => 'required|exists:dining_tables,id',
            'notas' => 'nullable|string'
        ]);

        $table = DiningTable::findOrFail($validated['dining_table_id']);
        $folio = 'RES-' . strtoupper(Str::random(6));

        $reservation = TableReservation::create([
            'folio' => $folio,
            'cliente_nombre' => $validated['cliente_nombre'],
            'cliente_telefono' => $validated['cliente_telefono'],
            'fecha' => $validated['fecha'],
            'hora' => $validated['hora'],
            'personas' => $validated['personas'],
            'dining_table_id' => $table->id,
            'estado' => 'confirmada',
            'notas' => $validated['notas'] ?? ''
        ]);

        $table->update(['estado' => 'reservada']);

        return response()->json([
            'message' => "Éxito: Reservación {$folio} confirmada para {$table->numero}.",
            'reservacion' => $reservation
        ]);
    }
}
