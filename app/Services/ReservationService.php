<?php

namespace App\Services;

use App\Models\DiningTable;
use App\Models\TableReservation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReservationService
{
    public const SLOT_MINUTES = 90;
    public const ACTIVE_STATES = ['pendiente', 'confirmada', 'ocupada'];
    public const STATES = ['pendiente', 'confirmada', 'ocupada', 'finalizada', 'cancelada'];

    public function create(array $data): TableReservation
    {
        return DB::transaction(function () use ($data) {
            $table = DiningTable::lockForUpdate()->findOrFail($data['dining_table_id']);
            $this->assertCapacity($table, $data['personas']);
            $this->assertAvailable($table->id, $data['fecha'], $data['hora']);

            $reservation = TableReservation::create($data + ['estado' => 'pendiente']);
            $this->syncTableState($table);

            return $reservation->load('table');
        });
    }

    public function update(TableReservation $reservation, array $data): TableReservation
    {
        return DB::transaction(function () use ($reservation, $data) {
            $oldTableId = $reservation->dining_table_id;
            $newTableId = $data['dining_table_id'] ?? $oldTableId;
            $tableIds = array_values(array_unique([$oldTableId, $newTableId]));
            sort($tableIds);
            $tables = DiningTable::whereIn('id', $tableIds)->lockForUpdate()->get()->keyBy('id');
            $newTable = $tables->get($newTableId);

            $date = $data['fecha'] ?? $reservation->fecha;
            $time = $data['hora'] ?? $reservation->hora;
            $people = $data['personas'] ?? $reservation->personas;
            $this->assertCapacity($newTable, $people);

            if (in_array($reservation->estado, self::ACTIVE_STATES, true)) {
                $this->assertAvailable($newTableId, $date, $time, $reservation->id);
            }

            $reservation->update($data);
            $this->syncTableState($tables->get($oldTableId));
            if ($newTableId !== $oldTableId) {
                $this->syncTableState($newTable);
            }

            return $reservation->fresh()->load('table');
        });
    }

    public function changeStatus(TableReservation $reservation, string $status): TableReservation
    {
        return DB::transaction(function () use ($reservation, $status) {
            $table = DiningTable::lockForUpdate()->findOrFail($reservation->dining_table_id);

            if (in_array($status, self::ACTIVE_STATES, true)) {
                $this->assertAvailable(
                    $table->id,
                    $reservation->fecha,
                    $reservation->hora,
                    $reservation->id
                );
            }

            $reservation->update(['estado' => $status]);
            $this->syncTableState($table);

            return $reservation->fresh()->load('table');
        });
    }

    private function assertAvailable(int $tableId, string $date, string $time, ?int $ignoreId = null): void
    {
        $requestedStart = Carbon::parse("{$date} {$time}");
        $requestedEnd = $requestedStart->copy()->addMinutes(self::SLOT_MINUTES);

        $conflict = TableReservation::query()
            ->where('dining_table_id', $tableId)
            ->whereBetween('fecha', [
                $requestedStart->copy()->subDay()->toDateString(),
                $requestedStart->copy()->addDay()->toDateString(),
            ])
            ->whereIn('estado', self::ACTIVE_STATES)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->get()
            ->contains(function (TableReservation $reservation) use ($requestedStart, $requestedEnd) {
                $existingStart = Carbon::parse("{$reservation->fecha} {$reservation->hora}");
                $existingEnd = $existingStart->copy()->addMinutes(self::SLOT_MINUTES);

                return $existingStart->lt($requestedEnd) && $existingEnd->gt($requestedStart);
            });

        if ($conflict) {
            throw ValidationException::withMessages([
                'hora' => 'La mesa ya tiene una reservación activa que se superpone con este horario.',
            ]);
        }
    }

    private function assertCapacity(DiningTable $table, int $people): void
    {
        if ($people > $table->capacidad) {
            throw ValidationException::withMessages([
                'personas' => "La mesa admite como máximo {$table->capacidad} personas.",
            ]);
        }
    }

    private function syncTableState(DiningTable $table): void
    {
        $activeStates = $table->reservations()
            ->whereIn('estado', self::ACTIVE_STATES)
            ->pluck('estado');

        $state = $activeStates->contains('ocupada')
            ? 'ocupada'
            : ($activeStates->isNotEmpty() ? 'reservada' : 'libre');

        $table->update(['estado' => $state]);
    }
}
