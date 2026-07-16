<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TableReservation;
use App\Services\ReservationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminReservationController extends Controller
{
    public function __construct(private ReservationService $reservations)
    {
    }

    public function index()
    {
        return response()->json(
            TableReservation::with('table')
                ->orderByDesc('fecha')
                ->orderByDesc('hora')
                ->get()
        );
    }

    public function show(TableReservation $reservation)
    {
        return response()->json($reservation->load('table'));
    }

    public function update(Request $request, TableReservation $reservation)
    {
        $data = $request->validate([
            'fecha' => ['sometimes', 'required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'hora' => ['sometimes', 'required', 'date_format:H:i'],
            'personas' => ['sometimes', 'required', 'integer', 'min:1'],
            'dining_table_id' => ['sometimes', 'required', 'integer', 'exists:dining_tables,id'],
        ]);

        return response()->json($this->reservations->update($reservation, $data));
    }

    public function updateStatus(Request $request, TableReservation $reservation)
    {
        $data = $request->validate([
            'estado' => ['required', 'string', Rule::in(ReservationService::STATES)],
        ]);

        return response()->json($this->reservations->changeStatus($reservation, $data['estado']));
    }

    public function destroy(TableReservation $reservation)
    {
        $reservation = $this->reservations->changeStatus($reservation, 'cancelada');

        return response()->json([
            'message' => "Reservación {$reservation->folio} cancelada.",
            'reservation' => $reservation,
        ]);
    }
}
