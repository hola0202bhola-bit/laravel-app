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
                <div class="live-status-badge">
                    <span class="status-dot pulsing"></span>
                    <span>Sistema de Cocina Activo</span>
                </div>
            </div>
        </header>

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
