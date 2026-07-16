<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeManagementTest extends TestCase
{
    use RefreshDatabase;

    private Role $adminRole;
    private Role $managerRole;
    private Role $cookRole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminRole = Role::create(['nombre' => 'Administrador']);
        $this->managerRole = Role::create(['nombre' => 'Gerente']);
        $this->cookRole = Role::create(['nombre' => 'Barista/Cocinero']);
    }

    public function test_user_management_requires_authentication(): void
    {
        $user = User::factory()->create();

        $this->getJson('/api/admin/users')->assertUnauthorized();
        $this->postJson('/api/admin/users', [])->assertUnauthorized();
        $this->patchJson("/api/admin/users/{$user->id}/status", ['is_active' => false])->assertUnauthorized();
    }

    public function test_manager_cannot_access_user_management(): void
    {
        $this->actAs($this->managerRole);

        $this->getJson('/api/admin/users')->assertForbidden();
        $this->postJson('/api/admin/users', [])->assertForbidden();
    }

    public function test_administrator_can_list_users_without_password_fields(): void
    {
        $admin = $this->actAs($this->adminRole);

        $response = $this->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonPath('users.0.email', $admin->email)
            ->assertJsonPath('users.0.role.nombre', 'Administrador');

        $listedUser = $response->json('users.0');
        $this->assertArrayNotHasKey('password', $listedUser);
        $this->assertArrayNotHasKey('remember_token', $listedUser);
        $this->assertStringNotContainsString($admin->password, $response->getContent());
    }

    public function test_employee_management_interface_is_visible_only_to_administrator(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach($this->adminRole);
        $this->actingAs($admin)->get('/empleado')
            ->assertOk()
            ->assertSee('Gestión de Empleados');

        $manager = User::factory()->create();
        $manager->roles()->attach($this->managerRole);
        $this->actingAs($manager)->get('/empleado')
            ->assertOk()
            ->assertDontSee('Gestión de Empleados');
    }

    public function test_administrator_can_create_employee_with_hashed_password_and_role(): void
    {
        $this->actAs($this->adminRole);

        $response = $this->postJson('/api/admin/users', [
            'name' => 'Empleado Nuevo',
            'email' => 'NUEVO@CAFESUBLIME.TEST',
            'password' => 'Secreto123!',
            'role_id' => $this->cookRole->id,
        ])->assertCreated();

        $employee = User::where('email', 'nuevo@cafesublime.test')->firstOrFail();
        $this->assertTrue(Hash::check('Secreto123!', $employee->password));
        $this->assertTrue($employee->is_active);
        $this->assertTrue($employee->hasRole('Barista/Cocinero'));
        $this->assertStringNotContainsString($employee->password, $response->getContent());
    }

    public function test_administrator_can_edit_name_and_email_but_duplicate_email_is_rejected(): void
    {
        $this->actAs($this->adminRole);
        $employee = User::factory()->create(['email' => 'original@cafesublime.test']);
        $other = User::factory()->create(['email' => 'ocupado@cafesublime.test']);

        $this->putJson("/api/admin/users/{$employee->id}", [
            'name' => 'Nombre Editado',
            'email' => 'EDITADO@CAFESUBLIME.TEST',
        ])->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $employee->id,
            'name' => 'Nombre Editado',
            'email' => 'editado@cafesublime.test',
        ]);

        $this->putJson("/api/admin/users/{$employee->id}", ['email' => $other->email])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_administrator_can_change_password_and_existing_tokens_are_invalidated(): void
    {
        $this->actAs($this->adminRole);
        $employee = User::factory()->create(['password' => 'Anterior123!']);
        $employee->roles()->attach($this->managerRole);
        $employee->createToken('ExistingSession', ['admin']);

        $this->patchJson("/api/admin/users/{$employee->id}/password", [
            'password' => 'NuevaClave123!',
        ])->assertOk();

        $this->assertTrue(Hash::check('NuevaClave123!', $employee->fresh()->password));
        $this->assertCount(0, $employee->tokens()->get());
    }

    public function test_administrator_can_assign_one_existing_role_and_unknown_role_is_rejected(): void
    {
        $this->actAs($this->adminRole);
        $employee = User::factory()->create();
        $employee->roles()->attach($this->cookRole);

        $this->patchJson("/api/admin/users/{$employee->id}/role", [
            'role_id' => $this->managerRole->id,
        ])->assertOk()->assertJsonPath('data.role.nombre', 'Gerente');

        $this->assertSame(['Gerente'], $employee->fresh()->roles()->pluck('nombre')->all());

        $this->patchJson("/api/admin/users/{$employee->id}/role", ['role_id' => 999999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role_id');
    }

    public function test_deactivation_invalidates_all_sanctum_tokens(): void
    {
        $this->actAs($this->adminRole);
        $employee = User::factory()->create();
        $employee->roles()->attach($this->managerRole);
        $employee->createToken('AdminDashboard', ['admin']);
        $employee->createToken('KdsToken', ['kitchen']);

        $this->patchJson("/api/admin/users/{$employee->id}/status", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse($employee->fresh()->is_active);
        $this->assertCount(0, $employee->tokens()->get());
    }

    public function test_administrator_cannot_deactivate_own_account(): void
    {
        $admin = $this->actAs($this->adminRole);
        $otherAdmin = User::factory()->create();
        $otherAdmin->roles()->attach($this->adminRole);

        $this->patchJson("/api/admin/users/{$admin->id}/status", ['is_active' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_active')
            ->assertJsonPath('errors.is_active.0', 'Un Administrador no puede desactivar su propia cuenta.');

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_last_active_administrator_cannot_be_deactivated_or_lose_role(): void
    {
        $admin = $this->actAs($this->adminRole);

        $this->patchJson("/api/admin/users/{$admin->id}/status", ['is_active' => false])
            ->assertUnprocessable()
            ->assertJsonPath('errors.is_active.0', 'No se puede desactivar al último Administrador activo.');

        $this->patchJson("/api/admin/users/{$admin->id}/role", ['role_id' => $this->managerRole->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.role_id.0', 'No se puede retirar el rol al último Administrador activo.');

        $this->assertTrue($admin->fresh()->is_active);
        $this->assertTrue($admin->fresh()->hasRole('Administrador'));
    }

    public function test_inactive_employee_cannot_log_in(): void
    {
        $employee = User::factory()->create([
            'email' => 'inactivo@cafesublime.test',
            'password' => 'Clave123!',
            'is_active' => false,
        ]);
        $employee->roles()->attach($this->cookRole);

        $this->postJson('/api/auth/login', [
            'email' => $employee->email,
            'password' => 'Clave123!',
        ])->assertUnauthorized();
    }

    private function actAs(Role $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach($role);
        Sanctum::actingAs($user, ['admin']);

        return $user;
    }
}
