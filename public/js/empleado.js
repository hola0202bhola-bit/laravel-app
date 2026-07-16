document.addEventListener('DOMContentLoaded', () => {
    let catalog = [];
    let salesHistory = [];
    let allOrders = [];
    let reservations = [];
    let diningTables = [];
    let categories = [];
    let inventoryHistory = [];
    const adminToken = document.querySelector('meta[name="admin-api-token"]')?.content;

    async function adminFetch(path, options = {}) {
        const headers = new Headers(options.headers || {});
        headers.set('Accept', 'application/json');
        if (options.body) headers.set('Content-Type', 'application/json');
        headers.set('Authorization', `Bearer ${adminToken}`);
        const response = await fetch(`/api/admin${path}`, { ...options, headers });
        if (response.status === 401) window.location.assign('/empleado/login');
        return response;
    }

    // Chart instances
    let chartSalesTrend = null;
    let chartTopProducts = null;
    let chartPaymentMethods = null;

    // DOM References
    const productsGrid = document.getElementById('products-grid');
    const searchInput = document.getElementById('search-input');
    const terminalBody = document.getElementById('terminal-body');
    const clearTerminalBtn = document.getElementById('clear-terminal');

    const pendingOrdersGrid = document.getElementById('pending-orders-grid');
    const pendingCount = document.getElementById('pending-count');
    const reservationsTableBody = document.getElementById('reservations-table-body');

    const statTotalSales = document.getElementById('stat-total-sales');
    const statTotalProducts = document.getElementById('stat-total-products');
    const statLowStock = document.getElementById('stat-low-stock');

    const reabastecerSelect = document.getElementById('reabastecer-producto');
    const precioSelect = document.getElementById('precio-producto');
    const formRegistrar = document.getElementById('form-registrar');
    const formReabastecer = document.getElementById('form-reabastecer');
    const formPrecio = document.getElementById('form-precio');
    const salesHistoryBody = document.getElementById('sales-history-body');

    const editProductModal = document.getElementById('edit-product-modal');
    const closeEditModal = document.getElementById('close-edit-modal');
    const formEditar = document.getElementById('form-editar');
    const editCodigo = document.getElementById('edit-codigo');
    const editNombre = document.getElementById('edit-nombre');
    const editDescripcion = document.getElementById('edit-descripcion');
    const editPrecio = document.getElementById('edit-precio');
    const editExistencia = document.getElementById('edit-existencia');
    const editImagen = document.getElementById('edit-imagen');
    const editCategory = document.getElementById('edit-category');
    const registerCategory = document.getElementById('reg-category');
    const formCategory = document.getElementById('form-categoria');
    const categoriesList = document.getElementById('categories-list');
    const deleteProductButton = document.getElementById('delete-product');
    const editReservationModal = document.getElementById('edit-reservation-modal');
    const closeReservationModal = document.getElementById('close-reservation-modal');
    const formReservation = document.getElementById('form-reservation');
    const reservationId = document.getElementById('reservation-id');
    const reservationDate = document.getElementById('reservation-date');
    const reservationTime = document.getElementById('reservation-time');
    const reservationPeople = document.getElementById('reservation-people');
    const reservationTable = document.getElementById('reservation-table');
    const reservationStatus = document.getElementById('reservation-status');

    lucide.createIcons();

    function playNotificationChime() {
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(587.33, ctx.currentTime);
            osc.frequency.setValueAtTime(880, ctx.currentTime + 0.15);
            gain.gain.setValueAtTime(0.25, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.5);
            osc.connect(gain); gain.connect(ctx.destination);
            osc.start(); osc.stop(ctx.currentTime + 0.5);
        } catch (e) {}
    }

    function logToTerminal(text) {
        const line = document.createElement('div');
        line.classList.add('log-line');
        const timestamp = new Date().toLocaleTimeString();
        const prefix = `[${timestamp}] > `;
        
        if (text.startsWith('Éxito:')) line.classList.add('success-msg');
        else if (text.startsWith('Error:')) line.classList.add('error-msg');
        else line.classList.add('system-msg');
        
        line.innerText = prefix + text;
        terminalBody.appendChild(line);
        terminalBody.scrollTop = terminalBody.scrollHeight;
    }

    // SSE Connection
    const evtSource = new EventSource('/api/events');
    evtSource.addEventListener('nuevo_pedido', (e) => {
        playNotificationChime();
        logToTerminal(`¡ALERTA EN VIVO! Nuevo Pedido recibido.`);
        refreshAllData();
    });
    evtSource.addEventListener('productos_actualizados', () => refreshAllData());
    evtSource.addEventListener('pedido_estado_cambiado', () => refreshAllData());

    async function refreshAllData() {
        try {
            await Promise.all([fetchProducts(), fetchSales(), fetchOrders(), fetchReservations(), fetchTables(), fetchAnalytics(), fetchCategories(), fetchInventoryHistory()]);
            updateStats();
            renderCatalog();
            updateDropdowns();
            renderPendingOrders();
            renderSalesTable();
            renderReservationsTable();
            renderCategories();
        } catch (e) {
            logToTerminal('Error al conectar con el servidor.');
        }
    }

    async function fetchProducts() {
        const res = await adminFetch('/products?t=' + Date.now());
        catalog = await res.json();
    }

    async function fetchSales() {
        const res = await adminFetch('/sales?t=' + Date.now());
        salesHistory = await res.json();
    }

    async function fetchOrders() {
        const res = await adminFetch('/orders?t=' + Date.now());
        allOrders = await res.json();
    }

    async function fetchReservations() {
        try {
            const res = await adminFetch('/reservations?t=' + Date.now());
            reservations = await res.json();
        } catch (e) {}
    }

    async function fetchTables() {
        const res = await fetch('/api/mesas?t=' + Date.now(), { headers: { 'Accept': 'application/json' } });
        diningTables = await res.json();
        updateReservationTables();
    }

    async function fetchAnalytics() {
        try {
            const res = await adminFetch('/analytics?t=' + Date.now());
            const data = await res.json();
            renderCharts(data);
        } catch (e) {}
    }

    async function fetchCategories() {
        const res = await adminFetch('/categories?t=' + Date.now());
        categories = await res.json();
        updateCategorySelects();
    }

    async function fetchInventoryHistory() {
        const res = await adminFetch('/inventory?t=' + Date.now());
        inventoryHistory = await res.json();
        renderInventoryHistory();
    }

    function renderCharts(data) {
        if (typeof Chart === 'undefined') return;

        // Chart 1: Sales Trend
        const ctxTrend = document.getElementById('chart-sales-trend');
        if (ctxTrend) {
            const labels = (data.salesTrend || []).map(s => s.label);
            const values = (data.salesTrend || []).map(s => s.total);

            if (chartSalesTrend) chartSalesTrend.destroy();
            chartSalesTrend = new Chart(ctxTrend, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Monto ($)',
                        data: values,
                        borderColor: '#d97706',
                        backgroundColor: 'rgba(217, 119, 6, 0.2)',
                        fill: true,
                        tension: 0.3
                    }]
                },
                options: { responsive: true, maintainAspectRatio: false }
            });
        }

        // Chart 2: Top Products
        const ctxTop = document.getElementById('chart-top-products');
        if (ctxTop) {
            if (chartTopProducts) chartTopProducts.destroy();
            chartTopProducts = new Chart(ctxTop, {
                type: 'doughnut',
                data: {
                    labels: data.topProducts?.labels || [],
                    datasets: [{
                        data: data.topProducts?.data || [],
                        backgroundColor: ['#d97706', '#10b981', '#6366f1', '#f59e0b', '#06b6d4']
                    }]
                },
                options: { responsive: true, maintainAspectRatio: false }
            });
        }

        // Chart 3: Payment Methods
        const ctxPay = document.getElementById('chart-payment-methods');
        if (ctxPay) {
            const payKeys = Object.keys(data.paymentBreakdown || {});
            const payVals = Object.values(data.paymentBreakdown || {});

            if (chartPaymentMethods) chartPaymentMethods.destroy();
            chartPaymentMethods = new Chart(ctxPay, {
                type: 'bar',
                data: {
                    labels: payKeys,
                    datasets: [{
                        label: 'Ingresos ($)',
                        data: payVals,
                        backgroundColor: ['#10b981', '#6366f1', '#f59e0b']
                    }]
                },
                options: { responsive: true, maintainAspectRatio: false }
            });
        }
    }

    async function setOrderStatus(id, estado) {
        try {
            const res = await fetch('/api/pedidos/estado', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': `Bearer ${adminToken}` },
                body: JSON.stringify({ id, estado })
            });

            const data = await res.json();
            if (res.ok) {
                logToTerminal(data.message);
                refreshAllData();
            } else {
                if (res.status === 401 || res.status === 403) {
                    logToTerminal('Error: La sesión administrativa no está autorizada.');
                } else {
                    logToTerminal(data.error || 'Error al cambiar estado.');
                }
            }
        } catch (e) {
            logToTerminal('Error de conexión.');
        }
    }

    function renderPendingOrders() {
        const pending = allOrders.filter(o => o.estado === 'pendiente');
        pendingCount.innerText = pending.length;
        pendingOrdersGrid.innerHTML = '';

        if (pending.length === 0) {
            pendingOrdersGrid.innerHTML = `
                <div style="grid-column: 1 / -1; padding: 24px; text-align: center; color: var(--text-muted);">
                    <i data-lucide="check-circle" style="width: 36px; height: 36px; margin-bottom: 8px; opacity: 0.4;"></i>
                    <p>No hay pedidos pendientes en este momento.</p>
                </div>
            `;
            lucide.createIcons();
            return;
        }

        pending.forEach(order => {
            const card = document.createElement('div');
            card.className = 'order-card';

            const tipo = order.tipo_pedido || order.tipoPedido || 'llevar';
            const mesa = order.numero_mesa || order.numeroMesa;
            const deliveryCode = order.codigo_delivery || order.codigoDelivery;

            let destClass = tipo === 'mesa' ? 'mesa' : (tipo === 'delivery' ? 'delivery' : 'llevar');
            let destLabel = tipo === 'mesa' ? (mesa || 'Mesa 1') : (tipo === 'delivery' ? `Delivery (${deliveryCode || '#RAPPI'})` : 'Para Llevar');

            let itemsHTML = '';
            (order.items || []).forEach(item => {
                const extrasText = item.extras && item.extras.length > 0 ? `<div class="order-item-extras">+ ${item.extras.map(e => e.nombre).join(', ')}</div>` : '';
                itemsHTML += `<div><div class="order-item-row"><span>${item.nombre} (${item.tamano}) x${item.cantidad}</span><span>$${parseFloat(item.subtotal).toFixed(2)}</span></div>${extrasText}</div>`;
            });

            card.innerHTML = `
                <div class="order-card-header">
                    <span class="order-id">PEDIDO #${order.id}</span>
                    <span class="dest-badge ${destClass}">${destLabel}</span>
                </div>
                <div class="order-card-items">${itemsHTML}</div>
                <div class="order-card-footer">
                    <span class="order-total-val">$${parseFloat(order.total).toFixed(2)}</span>
                    <div class="order-actions">
                        <button type="button" class="btn-reject-order"><i data-lucide="x"></i> Negar</button>
                        <button type="button" class="btn-accept-order"><i data-lucide="check"></i> Confirmar</button>
                    </div>
                </div>
            `;

            card.querySelector('.btn-accept-order').addEventListener('click', () => setOrderStatus(order.id, 'confirmado'));
            card.querySelector('.btn-reject-order').addEventListener('click', () => setOrderStatus(order.id, 'rechazado'));
            pendingOrdersGrid.appendChild(card);
        });

        lucide.createIcons();
    }

    function renderReservationsTable() {
        reservationsTableBody.innerHTML = '';
        if (reservations.length === 0) {
            reservationsTableBody.innerHTML = `<tr><td colspan="8" style="text-align:center; color:var(--text-muted); padding:16px;">No hay reservaciones de mesa registradas.</td></tr>`;
            return;
        }

        reservations.forEach(r => {
            const tr = document.createElement('tr');
            const closed = ['cancelada', 'finalizada'].includes(r.estado);
            tr.innerHTML = `
                <td><strong>${r.folio}</strong></td>
                <td>${r.cliente_nombre}</td>
                <td>${r.cliente_telefono}</td>
                <td>${r.fecha} ${r.hora}</td>
                <td>${r.personas} pers.</td>
                <td><span class="dest-badge mesa">${r.table ? r.table.numero : 'Mesa'}</span></td>
                <td><span class="stock-badge ${closed ? 'out' : 'available'}">${r.estado}</span></td>
                <td>
                    <button type="button" data-edit-reservation>Editar</button>
                    <button type="button" data-cancel-reservation ${closed ? 'disabled' : ''}>Cancelar</button>
                </td>
            `;
            tr.querySelector('[data-edit-reservation]').addEventListener('click', () => openReservationModal(r));
            tr.querySelector('[data-cancel-reservation]').addEventListener('click', () => cancelReservation(r));
            reservationsTableBody.appendChild(tr);
        });
    }

    function updateReservationTables() {
        const current = reservationTable.value;
        reservationTable.innerHTML = '';
        diningTables.forEach(table => {
            reservationTable.add(new Option(`${table.numero} (${table.capacidad} personas)`, table.id));
        });
        if (current) reservationTable.value = current;
    }

    function openReservationModal(reservation) {
        reservationId.value = reservation.id;
        reservationDate.value = reservation.fecha;
        reservationTime.value = reservation.hora.slice(0, 5);
        reservationPeople.value = reservation.personas;
        reservationTable.value = reservation.dining_table_id;
        reservationStatus.value = reservation.estado;
        editReservationModal.classList.add('active');
    }

    async function cancelReservation(reservation) {
        if (!window.confirm(`¿Cancelar la reservación ${reservation.folio}?`)) return;
        const response = await adminFetch(`/reservations/${reservation.id}`, { method: 'DELETE' });
        const data = await response.json();
        if (response.ok) {
            logToTerminal(`Éxito: ${data.message}`);
            refreshAllData();
        } else {
            logToTerminal(`Error: ${data.message || 'No se pudo cancelar la reservación.'}`);
        }
    }

    closeReservationModal.addEventListener('click', () => editReservationModal.classList.remove('active'));

    formReservation.addEventListener('submit', async event => {
        event.preventDefault();
        const id = reservationId.value;
        const original = reservations.find(item => String(item.id) === String(id));
        const updateResponse = await adminFetch(`/reservations/${id}`, {
            method: 'PUT',
            body: JSON.stringify({
                fecha: reservationDate.value,
                hora: reservationTime.value,
                personas: parseInt(reservationPeople.value),
                dining_table_id: parseInt(reservationTable.value)
            })
        });
        const updated = await updateResponse.json();
        if (!updateResponse.ok) {
            const error = updated.errors ? Object.values(updated.errors).flat()[0] : updated.message;
            logToTerminal(`Error: ${error || 'No se pudo actualizar la reservación.'}`);
            return;
        }

        if (original && original.estado !== reservationStatus.value) {
            const statusResponse = await adminFetch(`/reservations/${id}/status`, {
                method: 'PATCH',
                body: JSON.stringify({ estado: reservationStatus.value })
            });
            const statusData = await statusResponse.json();
            if (!statusResponse.ok) {
                const error = statusData.errors ? Object.values(statusData.errors).flat()[0] : statusData.message;
                logToTerminal(`Error: ${error || 'No se pudo cambiar el estado.'}`);
                return;
            }
        }

        logToTerminal(`Éxito: Reservación ${updated.folio} actualizada.`);
        editReservationModal.classList.remove('active');
        refreshAllData();
    });

    function renderCatalog() {
        const filter = searchInput.value.toLowerCase().trim();
        productsGrid.innerHTML = '';

        const filteredProducts = catalog.filter(prod => {
            const pNombre = prod.nombre || prod.Nombre || '';
            const pCodigo = (prod.codigo || prod.Codigo || '').toString();
            return pNombre.toLowerCase().includes(filter) || pCodigo.includes(filter);
        });

        if (filteredProducts.length === 0) {
            productsGrid.innerHTML = `
                <div class="glass-card" style="grid-column: 1 / -1; padding: 40px; text-align: center; color: var(--text-muted);">
                    <i data-lucide="package-search" style="width: 48px; height: 48px; margin-bottom: 12px; opacity: 0.5;"></i>
                    <p>No se encontraron productos en el inventario.</p>
                </div>
            `;
            lucide.createIcons();
            return;
        }

        filteredProducts.forEach(prod => {
            const pNombre = prod.nombre || prod.Nombre;
            const pCodigo = prod.codigo || prod.Codigo;
            const pPrecio = parseFloat(prod.precio || prod.Precio);
            const pExistencia = parseInt(prod.existencia || prod.Existencia);
            const pImagen = prod.imagen || prod.Imagen;

            const card = document.createElement('div');
            card.className = 'product-card';
            card.style.cursor = 'pointer';

            let stockClass = pExistencia === 0 ? 'out' : (pExistencia < 4 ? 'low' : 'available');
            let stockLabel = pExistencia === 0 ? 'Agotado' : (pExistencia < 4 ? 'Bajo Stock' : 'Disponible');

            let imgHTML = pImagen ? `<img src="${pImagen}" alt="${pNombre}" class="product-card-img" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">` : '';
            const fallbackHTML = `<div class="product-img-placeholder" style="${pImagen ? 'display:none;' : 'display:flex;'}"><i data-lucide="coffee"></i><span>Sin imagen</span></div>`;

            card.innerHTML = `
                <div class="product-img-container">${imgHTML}${fallbackHTML}</div>
                <div class="product-card-body">
                    <div class="product-header">
                        <span class="product-code">COD: ${pCodigo}</span>
                        <span class="stock-badge ${stockClass}">${stockLabel}</span>
                    </div>
                    <div class="product-info"><h3>${pNombre}</h3></div>
                    <div class="product-footer">
                        <span class="product-price">$${pPrecio.toFixed(2)}</span>
                        <span class="product-stock-text">Stock: <strong>${pExistencia}</strong></span>
                    </div>
                </div>
            `;

            card.addEventListener('click', () => {
                openEditProductModal({ Codigo: pCodigo, Nombre: pNombre, Precio: pPrecio, Existencia: pExistencia, Imagen: pImagen, Descripcion: prod.descripcion || prod.Descripcion, CategoryId: prod.category_id });
            });

            productsGrid.appendChild(card);
        });

        lucide.createIcons();
    }

    function openEditProductModal(product) {
        editCodigo.value = product.Codigo;
        editNombre.value = product.Nombre;
        editDescripcion.value = product.Descripcion || '';
        editPrecio.value = product.Precio;
        editExistencia.value = product.Existencia;
        editImagen.value = product.Imagen || '';
        editCategory.value = product.CategoryId || '';
        editProductModal.classList.add('active');
    }

    closeEditModal.addEventListener('click', () => editProductModal.classList.remove('active'));

    formEditar.addEventListener('submit', async (e) => {
        e.preventDefault();
        const codigo = parseInt(editCodigo.value);
        const payload = {
            nombre: editNombre.value.trim(),
            descripcion: editDescripcion.value.trim(),
            precio: editPrecio.value,
            category_id: editCategory.value || null,
            imagen: editImagen.value.trim()
        };

        try {
            const res = await adminFetch(`/products/${codigo}`, {
                method: 'PUT',
                body: JSON.stringify(payload)
            });

            const data = await res.json();
            if (res.ok) {
                logToTerminal(`Éxito: Producto ${data.nombre} actualizado.`);
                editProductModal.classList.remove('active');
                refreshAllData();
            } else {
                logToTerminal(data.error || 'Error al editar.');
            }
        } catch (e) { logToTerminal('Error de conexión al editar.'); }
    });

    function updateStats() {
        const totalAmount = salesHistory.reduce((sum, sale) => sum + parseFloat(sale.total), 0.0);
        statTotalSales.innerText = `$${totalAmount.toFixed(2)}`;
        statTotalProducts.innerText = catalog.length;
        statLowStock.innerText = catalog.filter(p => (p.existencia || p.Existencia) < 4).length;
    }

    function updateDropdowns() {
        const valReabastecer = reabastecerSelect.value;
        const valPrecio = precioSelect.value;
        reabastecerSelect.innerHTML = '<option value="" disabled selected>Seleccione un producto...</option>';
        precioSelect.innerHTML = '<option value="" disabled selected>Seleccione un producto...</option>';

        catalog.forEach(prod => {
            const pCodigo = prod.codigo || prod.Codigo;
            const pNombre = prod.nombre || prod.Nombre;
            const pExistencia = prod.existencia || prod.Existencia;
            const optText = `[${pCodigo}] ${pNombre} (Stock: ${pExistencia})`;
            reabastecerSelect.add(new Option(optText, pCodigo));
            precioSelect.add(new Option(optText, pCodigo));
        });

        if (valReabastecer && catalog.some(p => (p.codigo || p.Codigo) == valReabastecer)) reabastecerSelect.value = valReabastecer;
        if (valPrecio && catalog.some(p => (p.codigo || p.Codigo) == valPrecio)) precioSelect.value = valPrecio;
    }

    function renderSalesTable() {
        salesHistoryBody.innerHTML = '';
        if (salesHistory.length === 0) {
            salesHistoryBody.innerHTML = `<tr><td colspan="6" style="text-align:center; color:var(--text-muted); padding:24px;">No hay ventas confirmadas.</td></tr>`;
            return;
        }

        const sortedSales = [...salesHistory].reverse();

        sortedSales.forEach(sale => {
            const tr = document.createElement('tr');
            const dateObj = new Date(sale.created_at || sale.fecha || Date.now());
            const formattedTime = dateObj.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

            let itemsSummary = '';
            const itemsList = sale.items || [];
            if (itemsList.length > 0) {
                itemsSummary = itemsList.map(i => {
                    const ext = i.extras && i.extras.length > 0 ? ` (+${i.extras.map(e => e.nombre).join(',')})` : '';
                    return `${i.nombre} (${i.tamano}) x${i.cantidad}${ext}`;
                }).join(', ');
            } else {
                itemsSummary = sale.nombre || 'Venta';
            }

            const tipo = sale.tipo_pedido || sale.tipoPedido || 'llevar';
            let typeBadge = 'Llevar';
            if (tipo === 'mesa') typeBadge = sale.numero_mesa || sale.numeroMesa || 'Mesa';
            if (tipo === 'delivery') typeBadge = sale.codigo_delivery || sale.codigoDelivery || 'Delivery';

            tr.innerHTML = `
                <td>#${sale.id}</td>
                <td>${formattedTime}</td>
                <td><strong>${itemsSummary}</strong></td>
                <td><span class="dest-badge ${tipo}">${typeBadge}</span></td>
                <td>${(sale.metodo_pago || sale.metodoPago || 'efectivo').toUpperCase()}</td>
                <td>$${parseFloat(sale.total).toFixed(2)}</td>
            `;
            salesHistoryBody.appendChild(tr);
        });
    }

    function switchTab(tabId) {
        document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.toggle('active', btn.getAttribute('data-tab') === tabId));
        document.querySelectorAll('.tab-pane').forEach(pane => pane.classList.toggle('active', pane.id === `tab-${tabId}`));
    }

    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', () => switchTab(btn.getAttribute('data-tab')));
    });

    formRegistrar.addEventListener('submit', async (e) => {
        e.preventDefault();
        const payload = {
            codigo: parseInt(document.getElementById('reg-codigo').value),
            existencia: parseInt(document.getElementById('reg-existencia').value),
            nombre: document.getElementById('reg-nombre').value.trim(),
            descripcion: document.getElementById('reg-descripcion').value.trim(),
            precio: document.getElementById('reg-precio').value,
            category_id: registerCategory.value || null,
            imagen: document.getElementById('reg-imagen').value.trim()
        };

        try {
            const res = await adminFetch('/products', {
                method: 'POST',
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (res.ok) {
                logToTerminal(`Éxito: Producto ${data.nombre} creado.`);
                formRegistrar.reset();
                refreshAllData();
            } else { logToTerminal(data.error || 'Error.'); }
        } catch (err) { logToTerminal('Error de conexión.'); }
    });

    formReabastecer.addEventListener('submit', async (e) => {
        e.preventDefault();
        const codigo = parseInt(reabastecerSelect.value);
        const cantidad = parseInt(document.getElementById('reabastecer-cantidad').value);
        const motivo = document.getElementById('reabastecer-motivo').value.trim();

        try {
            const res = await adminFetch('/inventory/adjustments', {
                method: 'POST',
                body: JSON.stringify({ codigo, cantidad, motivo })
            });
            const data = await res.json();
            if (res.ok) {
                logToTerminal(`Éxito: Existencia ajustada a ${data.product.existencia}.`);
                formReabastecer.reset();
                refreshAllData();
            } else { logToTerminal(data.error || 'Error.'); }
        } catch (err) { logToTerminal('Error de conexión.'); }
    });

    formPrecio.addEventListener('submit', async (e) => {
        e.preventDefault();
        const codigo = parseInt(precioSelect.value);
        const nuevoPrecio = document.getElementById('precio-nuevo').value;

        try {
            const res = await adminFetch(`/products/${codigo}`, {
                method: 'PUT',
                body: JSON.stringify({ precio: nuevoPrecio })
            });
            const data = await res.json();
            if (res.ok) {
                logToTerminal(`Éxito: Precio de ${data.nombre} actualizado.`);
                formPrecio.reset();
                refreshAllData();
            } else { logToTerminal(data.error || 'Error.'); }
        } catch (err) { logToTerminal('Error de conexión.'); }
    });

    function updateCategorySelects() {
        [registerCategory, editCategory].forEach(select => {
            const current = select.value;
            select.innerHTML = '<option value="">Sin categoría</option>';
            categories.forEach(category => select.add(new Option(category.nombre, category.id)));
            select.value = current;
        });
    }

    function renderCategories() {
        categoriesList.innerHTML = '';
        categories.forEach(category => {
            const row = document.createElement('div');
            row.style.cssText = 'display:flex;justify-content:space-between;gap:8px;margin:6px 0;';
            row.innerHTML = `<span>${category.icono || ''} ${category.nombre} (${category.products_count})</span><span><button data-edit>Editar</button> <button data-delete>Eliminar</button></span>`;
            row.querySelector('[data-edit]').addEventListener('click', async () => {
                const nombre = window.prompt('Nuevo nombre', category.nombre);
                if (!nombre) return;
                await adminFetch(`/categories/${category.id}`, { method: 'PUT', body: JSON.stringify({ nombre, icono: category.icono }) });
                refreshAllData();
            });
            row.querySelector('[data-delete]').addEventListener('click', async () => {
                if (!window.confirm(`¿Eliminar la categoría ${category.nombre}?`)) return;
                await adminFetch(`/categories/${category.id}`, { method: 'DELETE' });
                refreshAllData();
            });
            categoriesList.appendChild(row);
        });
    }

    function renderInventoryHistory() {
        const body = document.getElementById('inventory-history-body');
        if (!body) return;
        body.innerHTML = inventoryHistory.length ? '' : '<tr><td colspan="5">Sin movimientos.</td></tr>';
        inventoryHistory.slice(0, 20).forEach(item => {
            const row = document.createElement('tr');
            row.innerHTML = `<td>${new Date(item.created_at).toLocaleString()}</td><td>${item.product?.nombre || item.product_codigo}</td><td>${item.tipo_movimiento}</td><td>${item.cantidad}</td><td>${item.motivo}</td>`;
            body.appendChild(row);
        });
    }

    formCategory.addEventListener('submit', async (event) => {
        event.preventDefault();
        const nombre = document.getElementById('category-name').value.trim();
        const icono = document.getElementById('category-icon').value.trim() || null;
        const response = await adminFetch('/categories', { method: 'POST', body: JSON.stringify({ nombre, icono }) });
        if (response.ok) {
            logToTerminal(`Éxito: Categoría ${nombre} creada.`);
            formCategory.reset();
            refreshAllData();
        }
    });

    deleteProductButton.addEventListener('click', async () => {
        const codigo = editCodigo.value;
        if (!window.confirm(`¿Eliminar el producto ${codigo}?`)) return;
        const response = await adminFetch(`/products/${codigo}`, { method: 'DELETE' });
        if (response.ok) {
            logToTerminal('Éxito: Producto eliminado.');
            editProductModal.classList.remove('active');
            refreshAllData();
        } else {
            const data = await response.json();
            logToTerminal(`Error: ${data.message || 'No se pudo eliminar.'}`);
        }
    });

    searchInput.addEventListener('input', renderCatalog);
    clearTerminalBtn.addEventListener('click', () => {
        terminalBody.innerHTML = '';
        logToTerminal('Logs administrativos limpiados.');
    });

    refreshAllData();
});
