<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if (!Auth::attempt($validated + ['is_active' => true])) {
            return response()->json(['error' => 'Credenciales incorrectas.'], 401);
        }

        $user = Auth::user();
        $roles = $user->roles()->pluck('nombre')->toArray();
        $token = $user->createToken('KdsToken', ['kitchen'], now()->addHours(8))->plainTextToken;

        return response()->json([
            'message' => 'Inicio de sesión exitoso.',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $roles
            ]
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Cierre de sesión exitoso.'
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->user();
        $roles = $user->roles()->pluck('nombre')->toArray();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $roles
        ]);
    }
}
