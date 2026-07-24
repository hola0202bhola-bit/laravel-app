# Guion de demostración — Café Sublime

Duración objetivo: 8–12 minutos.

## Preparación

Desde la raíz del proyecto:

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
New-Item database\database.sqlite -ItemType File -Force
php artisan migrate:fresh --seed
php artisan serve
```

Si `.env`, `APP_KEY` o la base ya existen, no deben sobrescribirse sin comprobar su contenido. Para una regeneración aislada, usar el Plan B al final.

## Credenciales demo

Todas usan la contraseña ficticia `Demo123!`.

| Rol | Correo |
|---|---|
| Administrador | `admin@cafesublime.test` |
| Gerente | `gerente@cafesublime.test` |
| Barista/Cocinero | `cocina@cafesublime.test` |

## Recorrido

### 1. Cliente y catálogo

- URL: `http://127.0.0.1:8000/cliente`
- Usuario: no requiere autenticación.
- Acción: recorrer categorías y mostrar los 15 productos demo.
- Resultado esperado: catálogo visible con filtros y productos disponibles.

### 2. Carrito y pedido

- URL: `/cliente`
- Usuario: no requiere autenticación.
- Acción: abrir un producto, elegir tamaño, seleccionar **Para Llevar**, añadir al carrito y confirmar con el método demo `Efectivo`.
- Resultado esperado: pedido creado, carrito vacío y confirmación con número de pedido. El método de pago es académico; no procesa transacciones bancarias.

### 3. Tracking

- URL: `/cliente`, panel que aparece después de crear el pedido.
- Usuario: no requiere cuenta; usa el token aleatorio del pedido.
- Acción: conservar el panel abierto mientras cocina cambia el estado.
- Resultado esperado: avance `pendiente → en_preparacion → listo`, sin mostrar importes, token ni datos administrativos.

### 4. KDS

- URL: `http://127.0.0.1:8000/cocina`
- Usuario: `cocina@cafesublime.test`.
- Acción: iniciar sesión, localizar el pedido, usar **Iniciar todo** y luego **Terminar todo**.
- Resultado esperado: pedido en la columna correspondiente y artículos listos. El estado comercial se mantiene separado.

### 5. Panel administrativo

- URL: `http://127.0.0.1:8000/empleado/login`
- Usuario: `admin@cafesublime.test`.
- Acción: iniciar sesión y recorrer dashboard, pedidos, catálogo y ventas.
- Resultado esperado: panel visible; una sesión KDS no permite acceder a administración.

### 6. Lifecycle de producto

- URL: `/empleado`, catálogo.
- Usuario: Administrador.
- Acción: desactivar/reactivar un producto y después suspender/reanudar su venta.
- Resultado esperado: desaparece del catálogo público cuando está inactivo o suspendido y vuelve al restaurarlo.
- Estado final obligatorio: producto **activo y disponible**.

### 7. Menús y categorías

- URL: `/empleado`, categorías y menús.
- Usuario: Administrador.
- Acción: mostrar la categoría del producto y el `Menú principal`; retirar y volver a agregar un producto sólo si queda tiempo.
- Resultado esperado: no se borran productos ni históricos. El menú principal es manual, está activo y contiene 15 productos.
- Estado final obligatorio: categoría activa y producto asociado al menú.

### 8. Reservaciones y mesas

- URL: `/cliente` para crear; `/empleado` para administrar.
- Usuario: cliente sin cuenta y Administrador para gestión.
- Acción: mostrar las 8 mesas y la reserva `DEMO-001`; opcionalmente crear una reserva futura.
- Resultado esperado: reserva pendiente visible y control de solapamientos activo.

### 9. Inventario

- URL: `/empleado`, inventario.
- Usuario: Administrador.
- Acción: registrar una entrada `+1` con motivo y una salida `-1` para restaurar stock.
- Resultado esperado: ambos movimientos en el historial; existencias negativas son rechazadas.

### 10. Reportes y CSV

- URL: `/empleado`, reportes.
- Usuario: Administrador o Gerente.
- Acción: abrir sin fechas y descargar CSV de ventas y pedidos.
- Resultado esperado: periodo de 30 días inclusivos, totales visibles y CSV descargables; la regresión del reloj permanece cubierta.

### 11. Seguridad y arquitectura

- URL: `/api/admin/*`, `/api/cocina/*`, `/api/pedidos/seguimiento`.
- Usuario: según el área.
- Acción: explicar la separación de alcances, la revalidación del producto en servidor y el tracking reducido.
- Resultado esperado: 401/403 sin autorización, 409 al comprar manualmente un producto inactivo o suspendido y tracking sin información sensible.

### 12. Alcance deliberado

- Acción: cerrar aclarando el alcance académico.
- Resultado esperado: no presentar como implementados pagos bancarios reales, delivery/logística real, cuentas persistentes de cliente, POS industrial, HACCP formal, multi-sucursal, payroll/nómina, marketplace ni fiscalización CFDI.

## Plan B: regenerar una demo aislada

No toca `database/database.sqlite`; crea una base desechable:

```powershell
$demoDb = Join-Path (Resolve-Path .\database) 'demo-release.sqlite'

if (Test-Path $demoDb) {
    Write-Host "Se reemplazará únicamente la base demo aislada: $demoDb"
    Remove-Item -LiteralPath $demoDb
}

New-Item -ItemType File -Path $demoDb | Out-Null
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = $demoDb

php artisan migrate:fresh --seed
php artisan serve
```

Resultado esperado:

- 3 cuentas demo con roles Administrador, Gerente y Barista/Cocinero.
- 15 productos y 3 categorías.
- `Menú principal` activo con 15 productos.
- 8 mesas, reserva `DEMO-001` y 15 movimientos iniciales de inventario.
- Pedido `demo-tracking-001` y una venta demo.

Para volver al entorno habitual, cerrar el servidor y abrir otra terminal, o eliminar las variables temporales:

```powershell
Remove-Item Env:DB_CONNECTION
Remove-Item Env:DB_DATABASE
```
