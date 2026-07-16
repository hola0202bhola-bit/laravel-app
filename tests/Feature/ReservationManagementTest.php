<?php

namespace Tests\Feature;

use App\Models\DiningTable;
use App\Models\Role;
use App\Models\TableReservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReservationManagementTest extends TestCase
{
    use RefreshDatabase;

    private Role $adminRole;
    private Role $managerRole;
    private Role $cookRole;
    private DiningTable $table;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminRole = Role::create(['nombre' => 'Administrador']);
        $this->managerRole = Role::create(['nombre' => 'Gerente']);
        $this->cookRole = Role::create(['nombre' => 'Barista/Cocinero']);
        $this->table = DiningTable::create([
            'numero' => 'Mesa 1', 'capacidad' => 4, 'ubicacion' => 'Interior', 'estado' => 'libre',
        ]);
    }

    public function test_admin_reservation_operations_require_authentication(): void
    {
        $reservation = $this->reservation();

        $this->getJson('/api/admin/reservations')->assertUnauthorized();
        $this->getJson("/api/admin/reservations/{$reservation->id}")->assertUnauthorized();
        $this->putJson("/api/admin/reservations/{$reservation->id}", ['personas' => 3])->assertUnauthorized();
        $this->patchJson("/api/admin/reservations/{$reservation->id}/status", ['estado' => 'confirmada'])->assertUnauthorized();
        $this->deleteJson("/api/admin/reservations/{$reservation->id}")->assertUnauthorized();
    }

    public function test_non_administrative_role_is_forbidden(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach($this->cookRole);
        Sanctum::actingAs($user, ['admin']);

        $this->getJson('/api/admin/reservations')->assertForbidden();
    }

    public function test_administrator_and_manager_can_list_and_view_reservations(): void
    {
        $reservation = $this->reservation();

        foreach ([$this->adminRole, $this->managerRole] as $role) {
            $this->actAs($role);
            $this->getJson('/api/admin/reservations')
                ->assertOk()
                ->assertJsonPath('0.folio', $reservation->folio)
                ->assertJsonPath('0.table.numero', 'Mesa 1');
            $this->getJson("/api/admin/reservations/{$reservation->id}")
                ->assertOk()
                ->assertJsonPath('folio', $reservation->folio);
        }
    }

    public function test_customer_can_create_pending_reservation_and_table_becomes_reserved(): void
    {
        $response = $this->postJson('/api/reservaciones/crear', $this->payload())
            ->assertCreated()
            ->assertJsonPath('reservacion.estado', 'pendiente')
            ->assertJsonPath('reservacion.table.numero', 'Mesa 1');

        $this->assertNotEmpty($response->json('reservacion.folio'));
        $this->assertSame('reservada', $this->table->fresh()->estado);
    }

    public function test_admin_can_update_schedule_people_and_table(): void
    {
        $oldTable = $this->table;
        $newTable = DiningTable::create([
            'numero' => 'Mesa 2', 'capacidad' => 6, 'ubicacion' => 'Terraza', 'estado' => 'libre',
        ]);
        $reservation = $this->reservation(['estado' => 'confirmada']);
        $oldTable->update(['estado' => 'reservada']);
        $this->actAs($this->adminRole);

        $this->putJson("/api/admin/reservations/{$reservation->id}", [
            'fecha' => now()->addDays(3)->toDateString(),
            'hora' => '14:30',
            'personas' => 5,
            'dining_table_id' => $newTable->id,
        ])->assertOk()
            ->assertJsonPath('hora', '14:30')
            ->assertJsonPath('personas', 5)
            ->assertJsonPath('table.id', $newTable->id);

        $this->assertSame('libre', $oldTable->fresh()->estado);
        $this->assertSame('reservada', $newTable->fresh()->estado);
    }

    public function test_active_reservations_cannot_overlap_same_table_slot(): void
    {
        $this->postJson('/api/reservaciones/crear', $this->payload(['hora' => '12:00']))->assertCreated();

        $this->postJson('/api/reservaciones/crear', $this->payload(['hora' => '13:00']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('hora');

        $this->postJson('/api/reservaciones/crear', $this->payload(['hora' => '13:30']))
            ->assertCreated();
        $this->assertDatabaseCount('table_reservations', 2);
    }

    public function test_admin_update_rejects_schedule_conflict(): void
    {
        $first = $this->reservation(['hora' => '10:00', 'estado' => 'confirmada']);
        $second = $this->reservation(['folio' => 'RES-002', 'hora' => '13:00', 'estado' => 'pendiente']);
        $this->actAs($this->managerRole);

        $this->putJson("/api/admin/reservations/{$second->id}", ['hora' => '10:30'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('hora');
        $this->assertSame('13:00', $second->fresh()->hora);
        $this->assertSame('10:00', $first->fresh()->hora);
    }

    public function test_overlap_is_detected_when_slot_crosses_midnight(): void
    {
        $date = now()->addDays(2)->toDateString();
        $nextDate = now()->addDays(3)->toDateString();
        $this->postJson('/api/reservaciones/crear', $this->payload([
            'fecha' => $date, 'hora' => '23:30',
        ]))->assertCreated();

        $this->postJson('/api/reservaciones/crear', $this->payload([
            'fecha' => $nextDate, 'hora' => '00:30',
        ]))->assertUnprocessable()->assertJsonValidationErrors('hora');
    }

    public function test_status_changes_update_table_and_finalization_releases_it(): void
    {
        $reservation = $this->reservation(['estado' => 'confirmada']);
        $this->table->update(['estado' => 'reservada']);
        $this->actAs($this->adminRole);

        $this->patchJson("/api/admin/reservations/{$reservation->id}/status", ['estado' => 'ocupada'])
            ->assertOk()->assertJsonPath('estado', 'ocupada');
        $this->assertSame('ocupada', $this->table->fresh()->estado);

        $this->patchJson("/api/admin/reservations/{$reservation->id}/status", ['estado' => 'finalizada'])
            ->assertOk()->assertJsonPath('estado', 'finalizada');
        $this->assertSame('libre', $this->table->fresh()->estado);
    }

    public function test_cancel_endpoint_cancels_reservation_releases_table_and_slot(): void
    {
        $reservation = $this->reservation(['estado' => 'confirmada']);
        $this->table->update(['estado' => 'reservada']);
        $this->actAs($this->adminRole);

        $this->deleteJson("/api/admin/reservations/{$reservation->id}")
            ->assertOk()
            ->assertJsonPath('reservation.estado', 'cancelada');
        $this->assertSame('libre', $this->table->fresh()->estado);

        $this->postJson('/api/reservaciones/crear', $this->payload())->assertCreated();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'cliente_nombre' => 'Ana Pérez',
            'cliente_telefono' => '555-1234',
            'fecha' => now()->addDays(2)->toDateString(),
            'hora' => '12:00',
            'personas' => 2,
            'dining_table_id' => $this->table->id,
            'notas' => 'Cumpleaños',
        ], $overrides);
    }

    private function reservation(array $overrides = []): TableReservation
    {
        return TableReservation::create(array_merge([
            'folio' => 'RES-001',
            'cliente_nombre' => 'Ana Pérez',
            'cliente_telefono' => '555-1234',
            'fecha' => now()->addDays(2)->toDateString(),
            'hora' => '12:00',
            'personas' => 2,
            'dining_table_id' => $this->table->id,
            'estado' => 'pendiente',
            'notas' => '',
        ], $overrides));
    }

    private function actAs(Role $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach($role);
        Sanctum::actingAs($user, ['admin']);

        return $user;
    }
}
