<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'Usuario no autenticado.'], 401);
            }

            return redirect()->route('employee.login');
        }

        if ($user->is_active === false) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'La cuenta está inactiva.'], 403);
            }

            abort(403, 'La cuenta está inactiva.');
        }

        $authorized = $user->roles()
            ->whereIn('nombre', ['Administrador', 'Gerente'])
            ->exists();

        if (!$authorized) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'No tiene permisos administrativos.'], 403);
            }

            abort(403, 'No tiene permisos administrativos.');
        }

        if ($request->is('api/*') && !$user->tokenCan('admin')) {
            return response()->json(['error' => 'El token no tiene alcance administrativo.'], 403);
        }

        return $next($request);
    }
}
