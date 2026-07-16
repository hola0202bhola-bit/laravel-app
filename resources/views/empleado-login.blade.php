<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso administrativo</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: Arial, sans-serif; background: #f4eee7; color: #2f2118; }
        main { width: min(380px, calc(100% - 2rem)); background: white; padding: 2rem; border-radius: 16px; box-shadow: 0 12px 36px #0002; }
        h1 { margin-top: 0; }
        label { display: block; margin: 1rem 0 .35rem; font-weight: 700; }
        input { box-sizing: border-box; width: 100%; padding: .75rem; border: 1px solid #c8b8aa; border-radius: 8px; }
        button { width: 100%; margin-top: 1.25rem; padding: .8rem; border: 0; border-radius: 8px; background: #6f4e37; color: white; font-weight: 700; cursor: pointer; }
        .error { color: #a61b1b; margin-top: .75rem; }
    </style>
</head>
<body>
<main>
    <h1>Panel de empleados</h1>
    <p>Ingresa con una cuenta de Administrador o Gerente.</p>
    <form method="POST" action="{{ route('employee.login.submit') }}">
        @csrf
        <label for="email">Correo</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username">
        <label for="password">Contraseña</label>
        <input id="password" name="password" type="password" required autocomplete="current-password">
        @error('email') <div class="error">{{ $message }}</div> @enderror
        <button type="submit">Ingresar</button>
    </form>
</main>
</body>
</html>
