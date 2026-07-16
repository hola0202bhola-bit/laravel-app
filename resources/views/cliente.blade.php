<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Café Sublime - Menú en Tiempo Real (Laravel)</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Fira+Code:wght@400;500;700&display=swap" rel="stylesheet">
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <link rel="stylesheet" href="/css/cliente.css">
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
                    <h1>Café Sublime - Menú</h1>
                    <p class="subtitle">Personalización, Reservaciones y Filtros Especiales</p>
                </div>
            </div>
            <div class="header-actions">
                <a href="/empleado" class="btn-secondary" style="text-decoration:none; border-color: rgba(245, 158, 11, 0.4); color: #f59e0b;">
                    <i data-lucide="lock"></i> Panel Empleado
                </a>
                <button type="button" class="btn-secondary" id="open-builder-btn" style="background:linear-gradient(135deg, #6366f1, #4f46e5); color:white; border:none;">
                    <i data-lucide="sparkles"></i> Crear Bebida Desde Cero
                </button>
                <button type="button" class="btn-secondary" id="open-reservation-btn">
                    <i data-lucide="calendar"></i> Reservar Mesa
                </button>
                <div class="live-status-badge">
                    <span class="status-dot pulsing"></span>
                    <span>En Vivo</span>
                </div>
            </div>
        </header>

        <!-- FILTERS & SORTING BAR -->
        <div class="filters-bar glass-card">
            <div class="filter-chips">
                <span class="filter-chip active" data-tag="">Todos</span>
                <span class="filter-chip" data-tag="Keto"><i data-lucide="flame"></i> Keto</span>
                <span class="filter-chip" data-tag="Vegetariano"><i data-lucide="leaf"></i> Vegetariano</span>
                <span class="filter-chip" data-tag="Sin Azúcar"><i data-lucide="shield"></i> Sin Azúcar</span>
            </div>

            <div class="filters-controls">
                <div class="form-group-inline">
                    <label><i data-lucide="alert-triangle"></i> Excluir Alérgeno:</label>
                    <select id="allergen-filter">
                        <option value="">Ninguno</option>
                        <option value="Gluten">Gluten</option>
                        <option value="Lactosa">Lactosa</option>
                        <option value="Nueces">Nueces</option>
                    </select>
                </div>
                <div class="form-group-inline">
                    <label><i data-lucide="arrow-down-up"></i> Ordenar por:</label>
                    <select id="sort-filter">
                        <option value="codigo">Por Código</option>
                        <option value="price_asc">Precio: Menor a Mayor</option>
                        <option value="price_desc">Precio: Mayor a Menor</option>
                        <option value="popularity">Popularidad (Más Vendidos)</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- MAIN LAYOUT -->
        <div class="main-layout">
            
            <!-- CATALOG & PRODUCTS SECTION -->
            <section class="catalog-section">
                <div class="section-header">
                    <div class="section-title">
                        <i data-lucide="grid"></i>
                        <h2>Nuestras Delicias</h2>
                    </div>
                    <div class="search-bar">
                        <i data-lucide="search"></i>
                        <input type="text" id="search-input" placeholder="Buscar platillo o bebida...">
                    </div>
                </div>
                
                <div class="products-grid" id="products-grid">
                    <!-- Products dynamically generated here -->
                </div>
            </section>

            <!-- SIDEBAR CART & TERMINAL -->
            <aside class="sidebar-section">
                
                <!-- SHOPPING CART CARD -->
                <div class="actions-container glass-card">
                    <div class="checkout-header">
                        <i data-lucide="shopping-cart"></i>
                        <h3>Tu Carrito de Compras</h3>
                    </div>
                    <div class="cart-content">
                        <!-- Empty State -->
                        <div id="cart-empty-msg" class="cart-empty-msg">
                            <i data-lucide="shopping-bag" class="empty-cart-icon"></i>
                            <p>Tu carrito está vacío</p>
                            <span class="empty-cart-tip">Haz clic en un platillo para personalizarlo</span>
                        </div>
                        
                        <!-- Cart Items List & Total -->
                        <div id="cart-list-container" class="cart-list-container" style="display: none;">
                            <div class="cart-items" id="cart-items">
                                <!-- Cart items generated here -->
                            </div>
                            <div class="cart-summary">
                                <div class="cart-total-row">
                                    <span>Total Pedido:</span>
                                    <span class="cart-total-amount" id="cart-total-amount">$0.00</span>
                                </div>
                                <div class="cart-actions-row">
                                    <button type="button" id="clear-cart-btn" class="btn-danger-outline">
                                        <i data-lucide="trash-2"></i> Vaciar
                                    </button>
                                    <button type="button" id="checkout-cart-btn" class="btn-primary flex-1">
                                        <i data-lucide="arrow-right-circle"></i> Continuar al Pago
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ESTADO DE MI PEDIDO (SECCIÓN VISIBLE) -->
                <div class="actions-container glass-card" id="tracking-panel" style="display: none; margin-bottom: 15px;">
                    <div class="checkout-header" style="justify-content: space-between; align-items: center; display: flex;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <i data-lucide="package" style="color: var(--primary);"></i>
                            <h3 style="margin: 0;">Estado de mi Pedido</h3>
                        </div>
                        <button type="button" id="close-tracking-btn" style="background: none; border: none; color: var(--text-muted); font-size: 1.25rem; cursor: pointer; padding: 0 5px;">&times;</button>
                    </div>
                    <div style="padding: 15px;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                            <span>Pedido ID: <strong id="tracking-order-id" style="color: white;">-</strong></span>
                            <span id="tracking-order-status" style="padding: 2px 8px; border-radius: 4px; font-weight: 700; font-size: 0.8rem; text-transform: uppercase;">-</span>
                        </div>
                        <div style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 12px;">
                            Última actualización: <span id="tracking-last-updated" style="color: white;">-</span>
                        </div>
                        <div class="progress-bar-container" style="background: rgba(255,255,255,0.05); height: 8px; border-radius: 4px; overflow: hidden; margin-bottom: 15px;">
                            <div id="tracking-progress-bar" style="background: var(--primary); width: 0%; height: 100%; transition: width 0.4s ease;"></div>
                        </div>
                        <div id="tracking-items-list" style="display: flex; flex-direction: column; gap: 8px; max-height: 150px; overflow-y: auto;">
                            <!-- Items status dynamic rendering -->
                        </div>
                        <div id="tracking-error-alert" style="display: none; background: rgba(239, 68, 68, 0.1); border: 1px solid var(--danger); color: var(--danger); padding: 8px; border-radius: 4px; margin-top: 10px; font-size: 0.8rem; text-align: center;">
                            <i data-lucide="wifi-off" style="width: 12px; height: 12px; display: inline-block; vertical-align: middle;"></i> Sin conexión — Reintentando...
                        </div>
                    </div>
                </div>

                <!-- TERMINAL LOG / ORDER RECEIPT DISPLAY -->
                <div class="terminal-container glass-card">
                    <div class="terminal-header">
                        <div class="terminal-buttons">
                            <span class="t-btn red"></span>
                            <span class="t-btn yellow"></span>
                            <span class="t-btn green"></span>
                        </div>
                        <span class="terminal-title">Estado del Pedido (Consola)</span>
                    </div>
                    <div class="terminal-body" id="terminal-body">
                        <div class="log-line system-msg">> Conectado a Laravel en tiempo real. Selecciona un producto para personalizar.</div>
                    </div>
                </div>

            </aside>

        </div>
    </div>

    <!-- MODAL 1: PERSONALIZACIÓN DE PRODUCTO EXISTENTE -->
    <div class="modal-overlay" id="customizer-modal">
        <div class="modal-card glass-card">
            <div class="modal-header">
                <h3 id="cust-product-name">Personalizar Producto</h3>
                <button type="button" class="btn-close-modal" id="close-customizer">&times;</button>
            </div>
            <div class="modal-body">
                <div class="cust-preview-row">
                    <img id="cust-product-img" src="" alt="Producto" class="cust-img">
                    <div>
                        <span class="cust-price-tag" id="cust-base-price">$0.00</span>
                        <p id="cust-product-desc" class="cust-desc">Descripción del platillo...</p>
                    </div>
                </div>

                <div class="cust-section">
                    <h4><i data-lucide="coffee"></i> 1. Selecciona el Tamaño</h4>
                    <div class="size-options">
                        <label class="size-chip active">
                            <input type="radio" name="size-option" value="Chico" checked>
                            <span>Chico (Estándar)</span>
                        </label>
                        <label class="size-chip">
                            <input type="radio" name="size-option" value="Mediano">
                            <span>Mediano (+$5.00)</span>
                        </label>
                        <label class="size-chip">
                            <input type="radio" name="size-option" value="Grande">
                            <span>Grande (+$10.00)</span>
                        </label>
                    </div>
                </div>

                <div class="cust-section" id="extras-section">
                    <h4><i data-lucide="plus-circle"></i> 2. Ingredientes Extras</h4>
                    <div class="extras-grid" id="extras-grid">
                        <!-- Extras checkboxes generated dynamically -->
                    </div>
                </div>

                <div class="cust-section">
                    <h4><i data-lucide="map-pin"></i> 3. ¿Dónde Entregar Pedido?</h4>
                    <div class="destination-options">
                        <label class="dest-chip active">
                            <input type="radio" name="dest-option" value="mesa" checked>
                            <span>Comer Aquí (Mesa)</span>
                        </label>
                        <label class="dest-chip">
                            <input type="radio" name="dest-option" value="llevar">
                            <span>Para Llevar</span>
                        </label>
                        <label class="dest-chip">
                            <input type="radio" name="dest-option" value="delivery">
                            <span>Delivery (Rappi / Uber)</span>
                        </label>
                    </div>
                    
                    <div class="form-group" id="table-number-group" style="margin-top: 12px;">
                        <label for="table-input">Número de Mesa</label>
                        <input type="text" id="table-input" value="Mesa 1" placeholder="Ej. Mesa 4">
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <div class="quantity-selector">
                    <button type="button" class="btn-qty" id="qty-minus">-</button>
                    <span id="qty-val">1</span>
                    <button type="button" class="btn-qty" id="qty-plus">+</button>
                </div>
                <button type="button" class="btn-primary flex-1" id="add-customized-to-cart">
                    <i data-lucide="shopping-bag"></i> Añadir al Carrito (<span id="cust-total-btn">$0.00</span>)
                </button>
            </div>
        </div>
    </div>

    <!-- MODAL 2: CREAR BEBIDA DESDE CERO (CUSTOM BUILDER) -->
    <div class="modal-overlay" id="custom-builder-modal">
        <div class="modal-card glass-card">
            <div class="modal-header">
                <h3><i data-lucide="sparkles"></i> Creador de Bebidas Desde Cero</h3>
                <button type="button" class="btn-close-modal" id="close-builder">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Nombre de Tu Creación</label>
                    <input type="text" id="builder-creation-name" value="Mi Bebida Especial" placeholder="Ej. Matcha Supremo con Avena">
                </div>

                <div class="cust-section">
                    <h4>1. Selecciona la Base</h4>
                    <select id="builder-base-select">
                        <option value="Doble Shot Espresso" data-precio="30.00">Doble Shot Espresso ($30.00)</option>
                        <option value="Té Matcha Ceremonial" data-precio="45.00">Té Matcha Ceremonial ($45.00)</option>
                        <option value="Infusión Manzanilla / Menta" data-precio="25.00">Infusión Herbal ($25.00)</option>
                        <option value="Base Chocolate Artesanal" data-precio="38.00">Base Chocolate Artesanal ($38.00)</option>
                    </select>
                </div>

                <div class="cust-section">
                    <h4>2. Nivel de Dulzor</h4>
                    <select id="builder-sweet-select">
                        <option value="Sin Azúcar (0%)">Sin Azúcar (0%)</option>
                        <option value="Dulce Medio (50%)">Dulce Medio (50%)</option>
                        <option value="Dulce Estándar (100%)">Dulce Estándar (100%)</option>
                    </select>
                </div>

                <div class="cust-section">
                    <h4>3. Tipo de Leche</h4>
                    <select id="builder-milk-select">
                        <option value="Leche Entera" data-precio="0.00">Leche Entera ($0.00)</option>
                        <option value="Leche de Almendras" data-precio="6.00">Leche de Almendras (+$6.00)</option>
                        <option value="Leche de Avena" data-precio="7.00">Leche de Avena (+$7.00)</option>
                    </select>
                </div>

                <div class="cust-section">
                    <h4>4. Temperatura & Estilo</h4>
                    <select id="builder-temp-select">
                        <option value="Servido Caliente" data-precio="0.00">Servido Caliente ($0.00)</option>
                        <option value="Servido con Hielo (Helado)" data-precio="3.00">Servido con Hielo (+$3.00)</option>
                        <option value="Estilo Frappé Licuado" data-precio="8.00">Estilo Frappé Licuado (+$8.00)</option>
                    </select>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-primary flex-1" id="add-builder-to-cart">
                    <i data-lucide="plus-circle"></i> Agregar Creación al Carrito (<span id="builder-total-price">$0.00</span>)
                </button>
            </div>
        </div>
    </div>

    <!-- MODAL 3: RESERVACIÓN DE MESAS -->
    <div class="modal-overlay" id="reservation-modal">
        <div class="modal-card glass-card">
            <div class="modal-header">
                <h3><i data-lucide="calendar"></i> Reservar Una Mesa</h3>
                <button type="button" class="btn-close-modal" id="close-reservation">&times;</button>
            </div>
            <div class="modal-body">
                <form id="form-reservar" class="action-form">
                    <div class="form-group">
                        <label for="res-nombre">Nombre Completo</label>
                        <input type="text" id="res-nombre" placeholder="Ej. Carlos Mendoza" required>
                    </div>
                    <div class="form-group">
                        <label for="res-telefono">Teléfono de Contacto</label>
                        <input type="tel" id="res-telefono" placeholder="Ej. 555-987-6543" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="res-fecha">Fecha</label>
                            <input type="date" id="res-fecha" required>
                        </div>
                        <div class="form-group">
                            <label for="res-hora">Hora</label>
                            <input type="time" id="res-hora" value="17:00" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="res-personas">Número de Personas</label>
                            <input type="number" id="res-personas" min="1" max="10" value="2" required>
                        </div>
                        <div class="form-group">
                            <label for="res-mesa">Seleccionar Mesa</label>
                            <select id="res-mesa" required>
                                <!-- Tables rendered dynamically -->
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="res-notas">Peticiones Especiales (Opcional)</label>
                        <input type="text" id="res-notas" placeholder="Ej. Mesa en terraza o cumpleaños...">
                    </div>
                    <button type="submit" class="btn-primary">
                        <i data-lucide="check-circle-2"></i> Confirmar Reservación
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL 4: MÉTODOS DE PAGO Y TICKET DIGITAL -->
    <div class="modal-overlay" id="checkout-modal">
        <div class="modal-card glass-card modal-ticket">
            <div class="modal-header">
                <h3><i data-lucide="credit-card"></i> Finalizar Pago y Ticket</h3>
                <button type="button" class="btn-close-modal" id="close-checkout">&times;</button>
            </div>
            <div class="modal-body">
                <div class="cust-section">
                    <h4>Selecciona Método de Pago</h4>
                    <div class="payment-options">
                        <label class="pay-chip active">
                            <input type="radio" name="pay-option" value="efectivo" checked>
                            <span><i data-lucide="banknote"></i> Efectivo</span>
                        </label>
                        <label class="pay-chip">
                            <input type="radio" name="pay-option" value="tarjeta">
                            <span><i data-lucide="credit-card"></i> Tarjeta</span>
                        </label>
                        <label class="pay-chip">
                            <input type="radio" name="pay-option" value="delivery">
                            <span><i data-lucide="smartphone"></i> App Delivery</span>
                        </label>
                    </div>
                </div>

                <div class="ticket-paper" id="ticket-paper">
                    <div class="ticket-header-brand">
                        <h2>CAFÉ SUBLIME</h2>
                        <p>Punto de Venta & Cafetería (Laravel)</p>
                        <p class="ticket-date" id="ticket-date-str">--/--/---- --:--</p>
                    </div>
                    <div class="ticket-divider"></div>
                    
                    <div class="ticket-meta-row">
                        <span id="ticket-order-type">Mesa: Mesa 1</span>
                        <span id="ticket-pay-method">Pago: Efectivo</span>
                    </div>
                    
                    <div class="ticket-divider"></div>
                    <div class="ticket-items-list" id="ticket-items-list">
                        <!-- Itemized list rendered here -->
                    </div>
                    <div class="ticket-divider"></div>
                    
                    <div class="ticket-totals">
                        <div class="t-row"><span>Subtotal:</span><span id="ticket-subtotal">$0.00</span></div>
                        <div class="t-row"><span>IVA (16% incl.):</span><span id="ticket-tax">$0.00</span></div>
                        <div class="t-row total"><span>TOTAL PAGADO:</span><span id="ticket-total">$0.00</span></div>
                    </div>
                    <div class="ticket-footer-text">
                        <p>¡Gracias por tu preferencia!</p>
                        <p class="ticket-barcode">||||||||||||||||||||||||||</p>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-secondary" id="print-ticket-btn">
                    <i data-lucide="printer"></i> Imprimir Ticket
                </button>
                <button type="button" class="btn-primary flex-1" id="confirm-payment-btn">
                    <i data-lucide="check-circle-2"></i> Procesar y Enviar Pedido
                </button>
            </div>
        </div>
    </div>

    <script src="/js/cliente.js"></script>
</body>
</html>
