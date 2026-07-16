<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Café Sublime - Pantalla de Cocina (KDS)</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Fira+Code:wght@400;500;700&display=swap" rel="stylesheet">
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <link rel="stylesheet" href="/css/cocina.css">
</head>
<body>
    <div class="glass-bg"></div>
    <div class="app-container">
        
        <!-- HEADER -->
        <header class="app-header">
            <div class="header-brand">
                <div class="logo-container">
                    <i data-lucide="chef-hat" class="brand-icon"></i>
                </div>
                <div>
                    <h1>Pantalla de Cocina & Baristas (KDS)</h1>
                    <p class="subtitle">Control de Tiempos y Preparación de Pedidos en Vivo</p>
                </div>
            </div>
            <div class="header-actions">
                <a href="/empleado" class="btn-secondary" style="text-decoration:none; margin-right: 12px;">
                    <i data-lucide="arrow-left"></i> Volver a Admin
                </a>
                <button type="button" class="btn-secondary" id="kds-logout-btn" style="margin-right: 12px; display: none; border-color: rgba(239, 68, 68, 0.4); color: #ef4444;">
                    <i data-lucide="log-out"></i> Cerrar Sesión
                </button>
                <div class="live-status-badge">
                    <span class="status-dot pulsing"></span>
                    <span>Sistema de Cocina Activo</span>
                </div>
            </div>
        </header>

        <!-- LOGIN MODAL FOR KDS -->
        <div class="modal-overlay" id="kds-login-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.85); z-index: 1000; justify-content: center; align-items: center;">
            <div class="modal-card glass-card" style="width: 350px; padding: 25px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.1); background: #111827; color: white;">
                <div style="text-align: center; margin-bottom: 20px;">
                    <div style="background: rgba(245, 158, 11, 0.1); width: 64px; height: 64px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 10px auto;">
                        <i data-lucide="chef-hat" style="width: 32px; height: 32px; color: #f59e0b;"></i>
                    </div>
                    <h3 style="margin: 0; font-size: 1.25rem;">Autenticación KDS</h3>
                    <p style="margin: 5px 0 0 0; font-size: 0.8rem; color: var(--text-muted);">Acceso exclusivo para empleados autorizados</p>
                </div>
                <form id="kds-login-form">
                    <div class="form-group" style="margin-bottom: 15px; text-align: left;">
                        <label style="display: block; font-size: 0.85rem; margin-bottom: 5px; color: var(--text-muted);">Correo Electrónico</label>
                        <input type="email" id="kds-email" required style="width: 100%; padding: 8px; border-radius: 4px; border: 1px solid rgba(255,255,255,0.1); background: rgba(0,0,0,0.2); color: white; box-sizing: border-box;">
                    </div>
                    <div class="form-group" style="margin-bottom: 20px; text-align: left;">
                        <label style="display: block; font-size: 0.85rem; margin-bottom: 5px; color: var(--text-muted);">Contraseña</label>
                        <input type="password" id="kds-password" required style="width: 100%; padding: 8px; border-radius: 4px; border: 1px solid rgba(255,255,255,0.1); background: rgba(0,0,0,0.2); color: white; box-sizing: border-box;">
                    </div>
                    <div id="kds-login-error" style="display: none; color: #ef4444; font-size: 0.8rem; margin-bottom: 15px; text-align: center;"></div>
                    <button type="submit" class="btn-primary" style="width: 100%; padding: 10px; border-radius: 4px; border: none; background: #f59e0b; color: #111827; font-weight: 700; cursor: pointer;">Iniciar Sesión</button>
                </form>
            </div>
        </div>

        <!-- KANBAN BOARD -->
        <div class="kds-kanban-board">
            
            <!-- COLUMN 1: PENDIENTES -->
            <div class="kds-column glass-card">
                <div class="column-header pending">
                    <i data-lucide="clock"></i>
                    <h2>1. Pendientes</h2>
                    <span class="column-badge" id="count-pendiente">0</span>
                </div>
                <div class="column-cards" id="cards-pendiente">
                    <!-- Cards rendered here -->
                </div>
            </div>

            <!-- COLUMN 2: EN PREPARACIÓN -->
            <div class="kds-column glass-card">
                <div class="column-header prep">
                    <i data-lucide="flame"></i>
                    <h2>2. En Preparación</h2>
                    <span class="column-badge" id="count-preparacion">0</span>
                </div>
                <div class="column-cards" id="cards-preparacion">
                    <!-- Cards rendered here -->
                </div>
            </div>

            <!-- COLUMN 3: LISTO PARA ENTREGAR -->
            <div class="kds-column glass-card">
                <div class="column-header ready">
                    <i data-lucide="check-circle-2"></i>
                    <h2>3. Listos / Entregados</h2>
                    <span class="column-badge" id="count-listo">0</span>
                </div>
                <div class="column-cards" id="cards-listo">
                    <!-- Cards rendered here -->
                </div>
            </div>

        </div>

    </div>

    <script src="/js/cocina.js"></script>
</body>
</html>
