# Café Sublime

Sistema académico de operación para una cafetería. Integra menú y pedidos para clientes, pantalla de cocina (KDS), seguimiento de pedidos y un panel administrativo para productos, categorías, inventario, ventas y reservaciones.

## Tecnologías

- PHP 8.1 o superior y Laravel 10.
- Laravel Sanctum para tokens y protección de la API.
- Eloquent ORM, migraciones y seeders.
- SQLite para ejecución local; el esquema también es compatible con PostgreSQL.
- Blade, HTML, CSS y JavaScript sin framework de frontend.
- Chart.js para las gráficas administrativas y Server-Sent Events para actualización operativa.
- PHPUnit 10 y GitHub Actions para pruebas automatizadas.

## Instalación local

Requisitos:

- Git.
- PHP 8.1 o superior con `pdo_sqlite`, `mbstring`, `openssl` y `bcmath`.
- Composer.
- Node.js es opcional: la versión actual usa los recursos estáticos incluidos en `public`.
- Para validar el perfil PostgreSQL se requieren PostgreSQL 15 y `pdo_pgsql`, pero no son necesarios para la demo local con SQLite.

```bash
git clone https://github.com/hola0202bhola-bit/laravel-app.git
cd laravel-app
composer install
cp .env.example .env
php artisan key:generate
```

En Windows PowerShell, sustituir `cp` por:

```powershell
Copy-Item .env.example .env
```

Crear la base SQLite vacía:

```bash
# Linux/macOS/Git Bash
touch database/database.sqlite

# Windows PowerShell
New-Item database/database.sqlite -ItemType File -Force
```

Preparar el esquema y los datos de demostración:

```bash
php artisan migrate:fresh --seed
php artisan serve
```

La aplicación queda disponible normalmente en `http://127.0.0.1:8000`.

## Configuración de `.env`

El archivo `.env.example` está preparado para SQLite. Las variables mínimas son:

```dotenv
APP_NAME="Café Sublime"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000
DB_CONNECTION=sqlite
SESSION_DRIVER=file
QUEUE_CONNECTION=sync
```

No es necesario definir `DB_DATABASE`: Laravel utiliza `database/database.sqlite`. La `APP_KEY` se genera con `php artisan key:generate`. No se deben compartir ni versionar archivos `.env` reales.

## Cuentas de demostración

Todas usan la contraseña ficticia `Demo123!`.

| Rol | Correo | Acceso principal |
|---|---|---|
| Administrador | `admin@cafesublime.test` | `/empleado` y funciones administrativas |
| Gerente | `gerente@cafesublime.test` | `/empleado` y funciones administrativas |
| Barista/Cocinero | `cocina@cafesublime.test` | `/cocina` y operación KDS |

Los seeders también generan los roles de dominio Mesero y Cajero, pero no crean cuentas demo para ellos.

## Rutas principales

| Ruta | Uso |
|---|---|
| `/cliente` | Menú, personalización, carrito, creación de pedidos y reservaciones |
| `/empleado/login` | Inicio de sesión administrativo |
| `/empleado` | Dashboard, pedidos, ventas, catálogo, menús, reservaciones, inventario y reportes |
| `/cocina` | Inicio de sesión y tablero KDS |
| `GET /api/pedidos/seguimiento` | Seguimiento mediante `X-Tracking-Token` |
| `/api/admin/*` | API Sanctum para Administrador y Gerente |
| `/api/cocina/*` | API Sanctum para operación de cocina |

El pedido precargado usa el token académico `demo-tracking-001`. Los pedidos creados desde `/cliente` devuelven su propio token de seguimiento.

## Módulos terminados

- Catálogo público con filtros, alérgenos, etiquetas y productos personalizables.
- Creación de pedidos para llevar, mesa o delivery.
- Reservación de mesas.
- KDS con estados por artículo, transiciones validadas, bloqueo optimista y auditoría.
- Seguimiento público limitado por token, sin exponer importes ni datos administrativos.
- Login administrativo independiente y API protegida.
- Gestión no destructiva del ciclo de vida de productos y categorías.
- Suspensión y reanudación temporal de productos.
- Menús manuales monositio y composición de productos, sin horarios automáticos.
- Ajustes transaccionales de existencias e historial de inventario.
- Dashboard con pedidos, ventas, reservaciones, analítica y productos con poco stock.
- Reportes con periodo predeterminado de 30 días inclusivos y exportaciones CSV.
- Compatibilidad de esquema y pruebas de migración entre SQLite y PostgreSQL.

## Roles y permisos

- **Administrador y Gerente:** acceso al panel y a `/api/admin`; pueden consultar pedidos, ventas y reportes, administrar catálogo, menús y reservaciones, y ajustar inventario.
- **Barista/Cocinero:** acceso al KDS y actualización de estados de preparación. No puede acceder a la API administrativa.
- **Mesero y Cajero:** roles de dominio incluidos para futuras ampliaciones; no tienen módulos propios en esta entrega.

Los tokens KDS tienen alcance `kitchen`; los tokens temporales del panel tienen alcance `admin`. Un token KDS no puede reutilizarse para consultar o modificar administración.

## Flujo principal

1. El cliente selecciona productos en `/cliente`, configura tamaño y extras, y confirma el pedido.
2. Laravel valida productos y existencias, calcula importes con precisión decimal, descuenta stock y crea el pedido y la venta.
3. El pedido aparece en `/cocina`; el barista inicia y termina artículos individualmente.
4. El estado de preparación se recalcula sin sobrescribir el estado comercial del pedido.
5. El cliente consulta el avance con el token de seguimiento recibido al crear el pedido.
6. Administrador o Gerente revisan ventas, pedidos, bajo stock y movimientos desde `/empleado`.

## Esquema general de datos

- **Identidad y permisos:** `users`, `roles`, `user_roles`, `personal_access_tokens`.
- **Catálogo:** `categories`, `products`, `menus`, `menu_product`, `allergens`, `dietary_tags` y tablas pivote.
- **Personalización:** `custom_bases`, `custom_options`, `custom_items`, extras y recetas.
- **Operación:** `orders`, `sales`, `sale_details`, `order_statuses`, `order_status_histories`.
- **Inventario:** `ingredients`, `inventory_logs`, proveedores y relaciones de ingredientes.
- **Salón:** `dining_tables`, `table_reservations`.
- **Migración controlada:** `data_migration_runs`, `data_migration_checkpoints`.

Las relaciones históricas de producto usan `product_codigo → products.codigo`. Productos y categorías se desactivan sin borrarse; desactivar una categoría conserva `products.category_id` y oculta sus productos del catálogo público.

## Decisiones técnicas importantes

- Los importes se guardan en columnas `decimal`, se exponen con casts `decimal:2` y se calculan con BCMath; no se usa punto flotante para dinero.
- Los estados de preparación KDS están separados del estado comercial para evitar cambios destructivos.
- Las actualizaciones KDS utilizan control de versión y registran auditoría por artículo.
- Los ajustes de inventario bloquean el producto dentro de una transacción y rechazan existencias negativas.
- Las consultas y mutaciones administrativas requieren Sanctum, alcance `admin` y rol Administrador o Gerente.
- El seguimiento utiliza tokens aleatorios y una respuesta reducida, además de limitación de solicitudes.

## Pruebas

```bash
php artisan test
```

La suite cubre seguridad 401/403, permisos, KDS, tracking, lifecycle de catálogo, menús, reservaciones, inventario, reportes, precisión decimal y compatibilidad de migración. El workflow `.github/workflows/laravel.yml` ejecuta la suite con PostgreSQL en CI.

## Despliegue académico en Render

La demostración remota utiliza SQLite efímera. Render puede reiniciar o reemplazar el sistema de archivos y, por tanto, restablecer la base; al iniciar el contenedor, las migraciones y los datos ficticios se regeneran automáticamente. Como el servicio académico puede entrar en inactividad, el primer acceso posterior puede tardar mientras el contenedor vuelve a iniciar.

## Presentación

El guion reproducible de 8–12 minutos, las credenciales, los resultados esperados y el procedimiento de recuperación están en [`docs/DEMO.md`](docs/DEMO.md).

Esta entrega no representa pagos bancarios reales, logística de delivery, cuentas persistentes de cliente, un POS industrial, HACCP formal, operación multisucursal, nómina, marketplace ni fiscalización CFDI. Son exclusiones deliberadas del alcance académico.
