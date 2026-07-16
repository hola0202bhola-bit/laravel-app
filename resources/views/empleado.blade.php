<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="admin-api-token" content="{{ $adminApiToken }}">
    <title>Café Sublime - Panel de Empleado (Laravel)</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Fira+Code:wght@400;500;700&display=swap" rel="stylesheet">
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="/css/empleado.css">
</head>
<body>
    <div class="glass-bg"></div>
    <div class="app-container">
        
        <!-- HEADER -->
        <header class="app-header">
            <div class="header-brand">
                <div class="logo-container">
                    <i data-lucide="coffee" class="brand-icon"></i>
                </div>
                <div>
                    <h1>Café Sublime - Administración</h1>
                    <p class="subtitle">Laravel 10 Backend & Control Analítico en Vivo</p>
                </div>
            </div>
            <div class="header-actions">
                <a href="/cliente" class="btn-secondary" style="text-decoration:none; border-color: rgba(99, 102, 241, 0.4); color: #818cf8;">
                    <i data-lucide="shopping-bag"></i> Ver Menú Cliente
                </a>
                <a href="/cocina" target="_blank" class="btn-primary" style="text-decoration:none; background:linear-gradient(135deg, #ef4444, #b91c1c);">
                    <i data-lucide="chef-hat"></i> Pantalla de Cocina (KDS)
                </a>
                <div class="live-status-badge">
                    <span class="status-dot pulsing"></span>
                    <span>Sincronizado en Vivo</span>
                </div>
                <form method="POST" action="{{ route('employee.logout') }}">
                    @csrf
                    <button type="submit" class="btn-secondary">Cerrar sesión</button>
                </form>
            </div>
        </header>

        <!-- STATS DASHBOARD -->
        <section class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon-wrapper sales">
                    <i data-lucide="dollar-sign"></i>
                </div>
                <div class="stat-info">
                    <span class="stat-label">Ventas Totales</span>
                    <span class="stat-value" id="stat-total-sales">$0.00</span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon-wrapper products">
                    <i data-lucide="package"></i>
                </div>
                <div class="stat-info">
                    <span class="stat-label">Productos en Menú</span>
                    <span class="stat-value" id="stat-total-products">0</span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon-wrapper stock-alert">
                    <i data-lucide="alert-triangle"></i>
                </div>
                <div class="stat-info">
                    <span class="stat-label">Alertas de Stock</span>
                    <span class="stat-value" id="stat-low-stock">0</span>
                </div>
            </div>
        </section>

        <!-- CHART.JS VISUAL ANALYTICS DASHBOARD -->
        <section class="analytics-grid glass-card" style="padding: 20px;">
            <div style="display:flex; align-items:center; gap:10px; margin-bottom: 20px;">
                <i data-lucide="bar-chart-2" style="color:var(--primary); width:24px; height:24px;"></i>
                <h2>Dashboard Analítico de Ventas & Rendimiento</h2>
            </div>
            <div class="charts-row">
                <div class="chart-box">
                    <h4>Ventas Recientes ($)</h4>
                    <canvas id="chart-sales-trend"></canvas>
                </div>
                <div class="chart-box">
                    <h4>Top 5 Platillos Más Populares</h4>
                    <canvas id="chart-top-products"></canvas>
                </div>
                <div class="chart-box">
                    <h4>Ingresos por Método de Pago</h4>
                    <canvas id="chart-payment-methods"></canvas>
                </div>
            </div>
        </section>

        <!-- MAIN LAYOUT -->
        <div class="main-layout">
            
            <!-- CATALOG & TABLES SECTION -->
            <section class="catalog-section">
                
                <!-- LIVE PENDING ORDERS SECTION -->
                <div class="pending-orders-block glass-card">
                    <div class="pending-orders-header">
                        <i data-lucide="bell-ring" class="icon-pulse"></i>
                        <h2>Pedidos Entrantes en Tiempo Real (Pendientes)</h2>
                        <span class="badge-count" id="pending-count">0</span>
                    </div>
                    <div class="pending-orders-grid" id="pending-orders-grid">
                        <!-- Pending order cards rendered dynamically here -->
                    </div>
                </div>

                <!-- TABLE RESERVATIONS BLOCK -->
                <div class="sales-history-block glass-card">
                    <div class="sales-history-header">
                        <i data-lucide="calendar"></i>
                        <div><h2>Gestión de Reservaciones</h2><small>Disponibilidad calculada en bloques de 90 minutos.</small></div>
                    </div>
                    <div class="table-container">
                        <table class="sales-table">
                            <thead>
                                <tr>
                                    <th>Folio</th>
                                    <th>Cliente</th>
                                    <th>Teléfono</th>
                                    <th>Fecha & Hora</th>
                                    <th>Personas</th>
                                    <th>Mesa</th>
                                    <th>Estado</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="reservations-table-body">
                                <!-- Reservations rendered here -->
                            </tbody>
                        </table>
                    </div>
                </div>

                @if(auth()->user()->hasRole('Administrador'))
                <!-- EMPLOYEE MANAGEMENT BLOCK -->
                <div class="sales-history-block glass-card">
                    <div class="sales-history-header" style="justify-content:space-between;">
                        <div style="display:flex; align-items:center; gap:10px;">
                            <i data-lucide="users"></i>
                            <div><h2>Gestión de Empleados</h2><small>Acceso exclusivo para Administradores.</small></div>
                        </div>
                        <button type="button" class="btn-primary" id="new-employee-button">
                            <i data-lucide="user-plus"></i> Nuevo empleado
                        </button>
                    </div>
                    <div class="table-container">
                        <table class="sales-table">
                            <thead><tr><th>Nombre</th><th>Correo</th><th>Rol</th><th>Estado</th><th>Acciones</th></tr></thead>
                            <tbody id="employees-table-body"></tbody>
                        </table>
                    </div>
                </div>
                @endif

                <!-- PRODUCT CATALOG -->
                <div class="catalog-block">
                    <div class="section-header">
                        <div class="section-title">
                            <i data-lucide="grid"></i>
                            <h2>Catálogo de Inventario (Haz clic para editar)</h2>
                        </div>
                        <div class="search-bar">
                            <i data-lucide="search"></i>
                            <input type="text" id="search-input" placeholder="Buscar por código o nombre...">
                        </div>
                    </div>
                    <div class="products-grid" id="products-grid">
                        <!-- Products generated here -->
                    </div>
                </div>

                <!-- SALES HISTORY TABLE -->
                <div class="sales-history-block glass-card">
                    <div class="sales-history-header">
                        <i data-lucide="receipt"></i>
                        <h2>Historial de Transacciones Confirmadas</h2>
                    </div>
                    <div class="table-container">
                        <table class="sales-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Fecha/Hora</th>
                                    <th>Productos & Extras</th>
                                    <th>Tipo / Mesa / Delivery</th>
                                    <th>Método Pago</th>
                                    <th>Total</th>
                                </tr>
                            </thead>
                            <tbody id="sales-history-body">
                                <!-- Sales rows generated here -->
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="sales-history-block glass-card">
                    <div class="sales-history-header"><i data-lucide="history"></i><h2>Movimientos recientes de inventario</h2></div>
                    <div class="table-container">
                        <table class="sales-table">
                            <thead><tr><th>Fecha</th><th>Producto</th><th>Tipo</th><th>Cantidad</th><th>Motivo</th></tr></thead>
                            <tbody id="inventory-history-body"></tbody>
                        </table>
                    </div>
                </div>

            </section>

            <!-- SIDEBAR ACTIONS & TERMINAL -->
            <aside class="sidebar-section">
                
                <!-- OPERATIONAL TABS -->
                <div class="actions-container glass-card">
                    <div class="tabs-header">
                        <button class="tab-btn active" data-tab="registrar">
                            <i data-lucide="plus-circle"></i>
                            Nuevo
                        </button>
                        <button class="tab-btn" data-tab="reabastecer">
                            <i data-lucide="refresh-cw"></i>
                            Stock
                        </button>
                        <button class="tab-btn" data-tab="precio">
                            <i data-lucide="tag"></i>
                            Precio
                        </button>
                    </div>

                    <div class="tabs-content">
                        <!-- TAB REGISTRAR -->
                        <div class="tab-pane active" id="tab-registrar">
                            <h3>Agregar Nuevo Producto</h3>
                            <form id="form-registrar" class="action-form">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="reg-codigo">Código Numérico</label>
                                        <input type="number" id="reg-codigo" min="1" placeholder="Ej. 104" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="reg-existencia">Stock Inicial</label>
                                        <input type="number" id="reg-existencia" min="0" value="10" required>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="reg-nombre">Nombre del Producto</label>
                                    <input type="text" id="reg-nombre" placeholder="Ej. Moka Helado" required>
                                </div>
                                <div class="form-group">
                                    <label for="reg-descripcion">Descripción</label>
                                    <input type="text" id="reg-descripcion" placeholder="Ej. Deliciosa combinación con espresso y chocolate...">
                                </div>
                                <div class="form-group">
                                    <label for="reg-category">Categoría</label>
                                    <select id="reg-category"><option value="">Sin categoría</option></select>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="reg-precio">Precio Unitario ($)</label>
                                        <input type="number" id="reg-precio" min="0.01" step="0.01" placeholder="Ej. 45.00" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="reg-imagen">Imagen URL (Opcional)</label>
                                        <input type="text" id="reg-imagen" placeholder="Ej. /assets/cappuccino_art.png">
                                    </div>
                                </div>
                                <button type="submit" class="btn-primary">
                                    <i data-lucide="save"></i> Guardar Producto
                                </button>
                            </form>
                        </div>

                        <!-- TAB REABASTECER -->
                        <div class="tab-pane" id="tab-reabastecer">
                            <h3>Reabastecer Stock</h3>
                            <form id="form-reabastecer" class="action-form">
                                <div class="form-group">
                                    <label for="reabastecer-producto">Seleccionar Producto</label>
                                    <select id="reabastecer-producto" required>
                                        <option value="" disabled selected>Seleccione un producto...</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="reabastecer-cantidad">Cantidad a Añadir</label>
                                    <input type="number" id="reabastecer-cantidad" value="5" required>
                                </div>
                                <div class="form-group">
                                    <label for="reabastecer-motivo">Motivo</label>
                                    <input type="text" id="reabastecer-motivo" value="Reabastecimiento" required>
                                </div>
                                <button type="submit" class="btn-primary">
                                    <i data-lucide="plus"></i> Añadir Stock
                                </button>
                            </form>
                        </div>

                        <!-- TAB PRECIO -->
                        <div class="tab-pane" id="tab-precio">
                            <h3>Actualizar Precio</h3>
                            <form id="form-precio" class="action-form">
                                <div class="form-group">
                                    <label for="precio-producto">Seleccionar Producto</label>
                                    <select id="precio-producto" required>
                                        <option value="" disabled selected>Seleccione un producto...</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="precio-nuevo">Nuevo Precio ($)</label>
                                    <input type="number" id="precio-nuevo" min="0.01" step="0.01" placeholder="Ej. 42.00" required>
                                </div>
                                <button type="submit" class="btn-primary">
                                    <i data-lucide="edit-3"></i> Actualizar Precio
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="actions-container glass-card" style="margin-top: 20px; padding: 20px;">
                    <h3>Categorías</h3>
                    <form id="form-categoria" class="action-form">
                        <div class="form-group"><label for="category-name">Nombre</label><input id="category-name" required></div>
                        <div class="form-group"><label for="category-icon">Icono</label><input id="category-icon"></div>
                        <button type="submit" class="btn-primary">Agregar categoría</button>
                    </form>
                    <div id="categories-list" style="margin-top: 12px;"></div>
                </div>

                <!-- TERMINAL LOG -->
                <div class="terminal-container glass-card">
                    <div class="terminal-header">
                        <div class="terminal-buttons">
                            <span class="t-btn red"></span>
                            <span class="t-btn yellow"></span>
                            <span class="t-btn green"></span>
                        </div>
                        <span class="terminal-title">Consola de Log Administrativo (Laravel)</span>
                        <button class="clear-log-btn" id="clear-terminal" title="Limpiar Consola">
                            <i data-lucide="trash-2"></i>
                        </button>
                    </div>
                    <div class="terminal-body" id="terminal-body">
                        <div class="log-line system-msg">> Consola de administración en vivo conectada a Laravel.</div>
                    </div>
                </div>

            </aside>

        </div>
    </div>

    <!-- MODAL: EDITAR PRODUCTO EXISTENTE -->
    <div class="modal-overlay" id="edit-product-modal">
        <div class="modal-card glass-card">
            <div class="modal-header">
                <h3><i data-lucide="edit"></i> Editar Producto</h3>
                <button type="button" class="btn-close-modal" id="close-edit-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form id="form-editar" class="action-form">
                    <input type="hidden" id="edit-codigo">
                    <div class="form-group">
                        <label for="edit-nombre">Nombre del Producto</label>
                        <input type="text" id="edit-nombre" required>
                    </div>
                    <div class="form-group">
                        <label for="edit-descripcion">Descripción</label>
                        <input type="text" id="edit-descripcion">
                    </div>
                    <div class="form-group">
                        <label for="edit-category">Categoría</label>
                        <select id="edit-category"><option value="">Sin categoría</option></select>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit-precio">Precio Unitario ($)</label>
                            <input type="number" id="edit-precio" min="0.01" step="0.01" required>
                        </div>
                        <div class="form-group">
                            <label for="edit-existencia">Existencia en Stock</label>
                            <input type="number" id="edit-existencia" disabled>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="edit-imagen">Imagen URL (Opcional)</label>
                        <input type="text" id="edit-imagen">
                    </div>
                    <button type="submit" class="btn-primary">
                        <i data-lucide="save"></i> Guardar Cambios
                    </button>
                    <button type="button" class="btn-secondary" id="delete-product">Eliminar producto</button>
                </form>
            </div>
        </div>
    </div>

    @if(auth()->user()->hasRole('Administrador'))
    <!-- MODAL: CREAR O EDITAR EMPLEADO -->
    <div class="modal-overlay" id="employee-modal">
        <div class="modal-card glass-card">
            <div class="modal-header">
                <h3 id="employee-modal-title"><i data-lucide="user-cog"></i> Empleado</h3>
                <button type="button" class="btn-close-modal" id="close-employee-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form id="employee-form" class="action-form">
                    <input type="hidden" id="employee-id">
                    <div class="form-group"><label for="employee-name">Nombre</label><input id="employee-name" maxlength="255" required></div>
                    <div class="form-group"><label for="employee-email">Correo</label><input type="email" id="employee-email" maxlength="255" required></div>
                    <div class="form-group"><label for="employee-role">Rol</label><select id="employee-role" required></select></div>
                    <div class="form-group">
                        <label for="employee-password">Contraseña <span id="employee-password-help">(mínimo 8 caracteres)</span></label>
                        <input type="password" id="employee-password" minlength="8" autocomplete="new-password">
                    </div>
                    <button type="submit" class="btn-primary"><i data-lucide="save"></i> Guardar empleado</button>
                </form>
            </div>
        </div>
    </div>
    @endif

    <!-- MODAL: EDITAR RESERVACIÓN -->
    <div class="modal-overlay" id="edit-reservation-modal">
        <div class="modal-card glass-card">
            <div class="modal-header">
                <h3><i data-lucide="calendar-clock"></i> Editar Reservación</h3>
                <button type="button" class="btn-close-modal" id="close-reservation-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form id="form-reservation" class="action-form">
                    <input type="hidden" id="reservation-id">
                    <div class="form-row">
                        <div class="form-group"><label for="reservation-date">Fecha</label><input type="date" id="reservation-date" required></div>
                        <div class="form-group"><label for="reservation-time">Hora</label><input type="time" id="reservation-time" required></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label for="reservation-people">Personas</label><input type="number" id="reservation-people" min="1" required></div>
                        <div class="form-group"><label for="reservation-table">Mesa</label><select id="reservation-table" required></select></div>
                    </div>
                    <div class="form-group">
                        <label for="reservation-status">Estado</label>
                        <select id="reservation-status" required>
                            <option value="pendiente">Pendiente</option>
                            <option value="confirmada">Confirmada</option>
                            <option value="ocupada">Ocupada</option>
                            <option value="finalizada">Finalizada</option>
                            <option value="cancelada">Cancelada</option>
                        </select>
                    </div>
                    <button type="submit" class="btn-primary"><i data-lucide="save"></i> Guardar Reservación</button>
                </form>
            </div>
        </div>
    </div>

    <script src="/js/empleado.js"></script>
</body>
</html>
