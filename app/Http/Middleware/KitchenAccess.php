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

        // Default to all authorized kitchen roles if none specified
        if (empty($roles)) {
            $roles = ['cocina', 'gerente', 'administrador'];
        }

        $hasAccess = false;
        foreach ($roles as $role) {
            if ($user->hasRole($role)) {
                $hasAccess = true;
                break;
            }
        }

        if (!$hasAccess) {
            return response()->json(['error' => 'No tiene permisos para acceder a esta acción.'], 403);
        }

        return $next($request);
    }
}
