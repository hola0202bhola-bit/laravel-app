<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class KitchenAccess
{
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'Usuario no autenticado.'], 401);
        }

        if ($user->is_active === false) {
            return response()->json(['error' => 'La cuenta está inactiva.'], 403);
        }

        // Default to all authorized kitchen roles if none specified
        if (empty($roles)) {
            $roles = ['cocina', 'Barista/Cocinero', 'gerente', 'administrador'];
        }

        $userRoles = $user->roles()->pluck('nombre')->toArray();
        $hasAccess = false;

        foreach ($roles as $role) {
            foreach ($userRoles as $userRole) {
                // Case-insensitive check
                if (strcasecmp($userRole, $role) === 0) {
                    $hasAccess = true;
                    break 2;
                }
                // Map 'cocina' alias to 'Barista/Cocinero'
                if (strcasecmp($role, 'cocina') === 0 && strcasecmp($userRole, 'Barista/Cocinero') === 0) {
                    $hasAccess = true;
                    break 2;
                }
            }
        }

        if (!$hasAccess) {
            return response()->json(['error' => 'No tiene permisos para acceder a esta acción.'], 403);
        }

        return $next($request);
    }
}
