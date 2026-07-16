<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReadOnlyMode
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (config('app.read_only') && in_array($request->method(), ['POST', 'PUT', 'DELETE', 'PATCH'])) {
            $bypassToken = config('app.read_only_bypass_token');
            $requestToken = $request->header('X-Read-Only-Bypass');

            // Constant-time bypass token validation
            if ($bypassToken && $requestToken && hash_equals($bypassToken, $requestToken)) {
                return $next($request);
            }

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'The application is currently in read-only maintenance mode. Writes are disabled.'
                ], 503);
            }

            abort(503, 'The application is currently in read-only maintenance mode. Writes are disabled.');
        }

        return $next($request);
    }
}
