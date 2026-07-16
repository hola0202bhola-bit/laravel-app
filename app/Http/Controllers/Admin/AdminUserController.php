<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminUserResource;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminUserController extends Controller
{
    public function index()
    {
        $users = User::with('roles')->orderByDesc('is_active')->orderBy('name')->get();
        $roles = Role::orderBy('nombre')->get(['id', 'nombre']);

        return response()->json([
            'users' => AdminUserResource::collection($users),
            'roles' => $roles,
        ]);
    }

    public function store(Request $request)
    {
        $this->normalizeEmail($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
        ]);

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'is_active' => true,
            ]);
            $user->roles()->sync([$data['role_id']]);

            return $user->load('roles');
        });

        return (new AdminUserResource($user))->response()->setStatusCode(201);
    }

    public function update(Request $request, User $user)
    {
        $this->normalizeEmail($request);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes', 'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
        ]);

        $user->update($data);

        return new AdminUserResource($user->fresh()->load('roles'));
    }

    public function updateRole(Request $request, User $user)
    {
        $data = $request->validate([
            'role_id' => ['required', 'integer', 'exists:roles,id'],
        ]);
        $role = Role::findOrFail($data['role_id']);

        DB::transaction(function () use ($user, $role) {
            $lockedUser = User::lockForUpdate()->findOrFail($user->id);

            if ($lockedUser->is_active
                && $lockedUser->hasRole('Administrador')
                && $role->nombre !== 'Administrador'
                && $this->activeAdministratorsForUpdate()->count() <= 1) {
                throw ValidationException::withMessages([
                    'role_id' => 'No se puede retirar el rol al último Administrador activo.',
                ]);
            }

            $lockedUser->roles()->sync([$role->id]);
        });

        return new AdminUserResource($user->fresh()->load('roles'));
    }

    public function updatePassword(Request $request, User $user)
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user->update(['password' => $data['password']]);
        $user->tokens()->delete();

        return response()->json(['message' => 'Contraseña actualizada correctamente.']);
    }

    public function updateStatus(Request $request, User $user)
    {
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);
        $activate = (bool) $data['is_active'];

        DB::transaction(function () use ($request, $user, $activate) {
            $lockedUser = User::lockForUpdate()->findOrFail($user->id);

            if (!$activate && $lockedUser->is_active && $lockedUser->hasRole('Administrador')) {
                if ($this->activeAdministratorsForUpdate()->count() <= 1) {
                    throw ValidationException::withMessages([
                        'is_active' => 'No se puede desactivar al último Administrador activo.',
                    ]);
                }
            }

            if (!$activate && $request->user()->is($lockedUser)) {
                throw ValidationException::withMessages([
                    'is_active' => 'Un Administrador no puede desactivar su propia cuenta.',
                ]);
            }

            $lockedUser->update(['is_active' => $activate]);
            if (!$activate) {
                $lockedUser->tokens()->delete();
            }
        });

        return new AdminUserResource($user->fresh()->load('roles'));
    }

    private function activeAdministratorsForUpdate()
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->where('nombre', 'Administrador'))
            ->lockForUpdate()
            ->get();
    }

    private function normalizeEmail(Request $request): void
    {
        if ($request->has('email')) {
            $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        }
    }
}
