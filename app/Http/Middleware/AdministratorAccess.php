<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdministratorAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user()?->hasRole('Administrador')) {
            return response()->json([
                'error' => 'Solo un Administrador puede gestionar empleados.',
            ], 403);
        }

        return $next($request);
    }
}
