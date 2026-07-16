<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminAuthController extends Controller
{
    public function showLogin()
    {
        return view('empleado-login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (!Auth::attempt($credentials + ['is_active' => true])) {
            return back()->withErrors(['email' => 'Credenciales incorrectas.'])->onlyInput('email');
        }

        $user = $request->user();

        if (!$this->hasAdminRole($user)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()->withErrors(['email' => 'La cuenta no tiene acceso administrativo.']);
        }

        $request->session()->regenerate();
        $user->tokens()->where('name', 'AdminDashboard')->delete();
        $token = $user->createToken('AdminDashboard', ['admin'], now()->addHours(8));

        $request->session()->put('admin_api_token', $token->plainTextToken);

        return redirect()->route('employee.dashboard');
    }

    public function logout(Request $request)
    {
        $request->user()->tokens()->where('name', 'AdminDashboard')->delete();
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('employee.login');
    }

    private function hasAdminRole($user): bool
    {
        return $user->roles()->whereIn('nombre', ['Administrador', 'Gerente'])->exists();
    }
}
