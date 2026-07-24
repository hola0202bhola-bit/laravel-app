document.addEventListener('DOMContentLoaded', () => {
    let catalog = [];
    let salesHistory = [];
    let allOrders = [];
    let reservations = [];
    let diningTables = [];
    let categories = [];
    let menus = [];
    let inventoryHistory = [];
    let employees = [];
    let employeeRoles = [];
    let reportData = null;
    let editingProduct = null;
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
    let chartDailySales = null;

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
    const toggleProductActiveButton = document.getElementById('toggle-product-active');
    const toggleProductAvailabilityButton = document.getElementById('toggle-product-availability');
    const formMenu = document.getElementById('form-menu');
    const menusList = document.getElementById('menus-list');
    const editReservationModal = document.getElementById('edit-reservation-modal');
    const closeReservationModal = document.getElementById('close-reservation-modal');
    const formReservation = document.getElementById('form-reservation');
    const reservationId = document.getElementById('reservation-id');
    const reservationDate = document.getElementById('reservation-date');
    const reservationTime = document.getElementById('reservation-time');
    const reservationPeople = document.getElementById('reservation-people');
    const reservationTable = document.getElementById('reservation-table');
    const reservationStatus = document.getElementById('reservation-status');
    const employeesTableBody = document.getElementById('employees-table-body');
    const newEmployeeButton = document.getElementById('new-employee-button');
    const employeeModal = document.getElementById('employee-modal');
    const closeEmployeeModal = document.getElementById('close-employee-modal');
    const employeeForm = document.getElementById('employee-form');
    const employeeId = document.getElementById('employee-id');
    const employeeName = document.getElementById('employee-name');
    const employeeEmail = document.getElementById('employee-email');
    const employeeRole = document.getElementById('employee-role');
    const employeePassword = document.getElementById('employee-password');
    const employeeModalTitle = document.getElementById('employee-modal-title');
    const employeePasswordHelp = document.getElementById('employee-password-help');
    const reportFilterForm = document.getElementById('report-filter-form');
    const reportStart = document.getElementById('report-start');
    const reportEnd = document.getElementById('report-end');
    const reportTotalSales = document.getElementById('report-total-sales');
    const reportOrderCount = document.getElementById('report-order-count');
    const reportAverageTicket = document.getElementById('report-average-ticket');
    const reportTopProducts = document.getElementById('report-top-products');
    const reportOrderStatus = document.getElementById('report-order-status');
    const reportLowInventory = document.getElementById('report-low-inventory');
    const reportEmptyMessage = document.getElementById('report-empty-message');
    const exportSalesButton = document.getElementById('export-sales');
    const exportOrdersButton = document.getElementById('export-orders');

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
            await Promise.all([fetchProducts(), fetchSales(), fetchOrders(), fetchReservations(), fetchTables(), fetchReport(), fetchCategories(), fetchMenus(), fetchInventoryHistory(), fetchEmployees()]);
            updateStats();
            renderCatalog();
            updateDropdowns();
            renderPendingOrders();
            renderSalesTable();
            renderReservationsTable();
            renderCategories();
            renderMenus();
            renderEmployeesTable();
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

    function reportQuery() {
        const params = new URLSearchParams();
        if (reportStart.value) params.set('fecha_inicio', reportStart.value);
        if (reportEnd.value) params.set('fecha_fin', reportEnd.value);
        return params.toString();
    }

    async function fetchReport() {
        const response = await adminFetch(`/reports?${reportQuery()}`);
        if (!response.ok) {
            const data = await response.json();
            throw new Error(data.errors ? Object.values(data.errors).flat()[0] : 'No se pudo generar el reporte.');
        }
        reportData = await response.json();
        reportStart.value = reportData.period.start;
        reportEnd.value = reportData.period.end;
        renderReport();
    }

    async function fetchCategories() {
        const res = await adminFetch('/categories?t=' + Date.now());
        categories = await res.json();
        updateCategorySelects();
    }

    async function fetchMenus() {
        const res = await adminFetch('/menus?t=' + Date.now());
        menus = await res.json();
    }

    async function fetchInventoryHistory() {
        const res = await adminFetch('/inventory?t=' + Date.now());
        inventoryHistory = await res.json();
        renderInventoryHistory();
    }

    async function fetchEmployees() {
        if (!employeesTableBody) return;
        const response = await adminFetch('/users?t=' + Date.now());
        if (!response.ok) throw new Error('No se pudo consultar empleados.');
        const data = await response.json();
        employees = data.users || [];
        employeeRoles = data.roles || [];
        updateEmployeeRoles();
    }

    function updateEmployeeRoles() {
        if (!employeeRole) return;
        const selected = employeeRole.value;
        employeeRole.innerHTML = '<option value="">Seleccione un rol...</option>';
        employeeRoles.forEach(role => employeeRole.add(new Option(role.nombre, role.id)));
        if (selected) employeeRole.value = selected;
    }

    function escapeHtml(value) {
        const element = document.createElement('div');
        element.textContent = value == null ? '' : String(value);
        return element.innerHTML;
    }

    function renderEmployeesTable() {
        if (!employeesTableBody) return;
        employeesTableBody.innerHTML = '';
        if (employees.length === 0) {
            employeesTableBody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:16px;">No hay empleados registrados.</td></tr>';
            return;
        }

        employees.forEach(employee => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td><strong>${escapeHtml(employee.name)}</strong></td>
                <td>${escapeHtml(employee.email)}</td>
                <td>${escapeHtml(employee.role?.nombre || 'Sin rol')}</td>
                <td><span class="stock-badge ${employee.is_active ? 'available' : 'out'}">${employee.is_active ? 'Activo' : 'Inactivo'}</span></td>
                <td>
                    <button type="button" class="btn-secondary" data-edit-employee>Editar</button>
                    <button type="button" class="btn-secondary" data-toggle-employee>${employee.is_active ? 'Desactivar' : 'Activar'}</button>
                </td>`;
            row.querySelector('[data-edit-employee]').addEventListener('click', () => openEmployeeModal(employee));
            row.querySelector('[data-toggle-employee]').addEventListener('click', () => toggleEmployee(employee));
            employeesTableBody.appendChild(row);
        });
    }

    function openEmployeeModal(employee = null) {
        employeeForm.reset();
        employeeId.value = employee?.id || '';
        employeeName.value = employee?.name || '';
        employeeEmail.value = employee?.email || '';
        employeeRole.value = employee?.role?.id || '';
        employeePassword.required = !employee;
        employeeModalTitle.textContent = employee ? 'Editar empleado' : 'Nuevo empleado';
        employeePasswordHelp.textContent = employee ? '(dejar vacía para conservarla)' : '(mínimo 8 caracteres)';
        employeeModal.classList.add('active');
    }

    async function responseError(response, fallback) {
        const data = await response.json();
        return data.errors ? Object.values(data.errors).flat()[0] : (data.message || data.error || fallback);
    }

    async function toggleEmployee(employee) {
        const action = employee.is_active ? 'desactivar' : 'activar';
        if (!window.confirm(`¿Confirmas que deseas ${action} la cuenta de ${employee.name}?`)) return;
        const response = await adminFetch(`/users/${employee.id}/status`, {
            method: 'PATCH',
            body: JSON.stringify({ is_active: !employee.is_active })
        });
        if (!response.ok) {
            logToTerminal(`Error: ${await responseError(response, 'No se pudo cambiar el estado.')}`);
            return;
        }
        logToTerminal(`Éxito: Cuenta ${employee.is_active ? 'desactivada' : 'activada'}.`);
        refreshAllData();
    }

    if (newEmployeeButton) newEmployeeButton.addEventListener('click', () => openEmployeeModal());
    if (closeEmployeeModal) closeEmployeeModal.addEventListener('click', () => employeeModal.classList.remove('active'));
    if (employeeForm) employeeForm.addEventListener('submit', async event => {
        event.preventDefault();
        const id = employeeId.value;
        const original = employees.find(employee => String(employee.id) === String(id));
        const roleChanged = original && String(original.role?.id || '') !== employeeRole.value;
        const passwordChanged = Boolean(employeePassword.value);

        if (roleChanged && !window.confirm('Cambiar el rol modifica los accesos de esta cuenta. ¿Continuar?')) return;
        if (passwordChanged && original && !window.confirm('Se invalidarán las sesiones de esta cuenta al cambiar la contraseña. ¿Continuar?')) return;

        if (!id) {
            const response = await adminFetch('/users', {
                method: 'POST',
                body: JSON.stringify({
                    name: employeeName.value,
                    email: employeeEmail.value,
                    role_id: Number(employeeRole.value),
                    password: employeePassword.value
                })
            });
            if (!response.ok) {
                logToTerminal(`Error: ${await responseError(response, 'No se pudo crear el empleado.')}`);
                return;
            }
        } else {
            const updateResponse = await adminFetch(`/users/${id}`, {
                method: 'PUT',
                body: JSON.stringify({ name: employeeName.value, email: employeeEmail.value })
            });
            if (!updateResponse.ok) {
                logToTerminal(`Error: ${await responseError(updateResponse, 'No se pudo editar el empleado.')}`);
                return;
            }
            if (roleChanged) {
                const roleResponse = await adminFetch(`/users/${id}/role`, {
                    method: 'PATCH', body: JSON.stringify({ role_id: Number(employeeRole.value) })
                });
                if (!roleResponse.ok) {
                    logToTerminal(`Error: ${await responseError(roleResponse, 'No se pudo asignar el rol.')}`);
                    return;
                }
            }
            if (passwordChanged) {
                const passwordResponse = await adminFetch(`/users/${id}/password`, {
                    method: 'PATCH', body: JSON.stringify({ password: employeePassword.value })
                });
                if (!passwordResponse.ok) {
                    logToTerminal(`Error: ${await responseError(passwordResponse, 'No se pudo cambiar la contraseña.')}`);
                    return;
                }
            }
        }

        logToTerminal(`Éxito: Empleado ${id ? 'actualizado' : 'creado'}.`);
        employeeModal.classList.remove('active');
        refreshAllData();
    });

    function renderReport() {
        if (!reportData) return;
        reportTotalSales.textContent = `$${reportData.summary.total_sales}`;
        reportOrderCount.textContent = reportData.summary.order_count;
        reportAverageTicket.textContent = `$${reportData.summary.average_ticket}`;
        reportEmptyMessage.style.display = reportData.daily_sales.length === 0 && reportData.summary.order_count === 0 ? 'block' : 'none';

        reportTopProducts.innerHTML = reportData.top_products.length
            ? reportData.top_products.map(product => `<tr><td>${escapeHtml(product.nombre)}</td><td>${product.cantidad}</td><td>$${product.total}</td></tr>`).join('')
            : '<tr><td colspan="3">Sin productos vendidos en este periodo.</td></tr>';
        reportOrderStatus.innerHTML = reportData.orders_by_status.length
            ? reportData.orders_by_status.map(item => `<tr><td>${escapeHtml(item.status)}</td><td>${item.count}</td></tr>`).join('')
            : '<tr><td colspan="2">Sin pedidos en este periodo.</td></tr>';
        reportLowInventory.innerHTML = reportData.low_inventory.length
            ? reportData.low_inventory.map(product => `<tr><td>${product.codigo}</td><td>${escapeHtml(product.nombre)}</td><td>${product.existencia}</td></tr>`).join('')
            : '<tr><td colspan="3">No hay productos con poco inventario.</td></tr>';

        const canvas = document.getElementById('chart-daily-sales');
        if (typeof Chart !== 'undefined' && canvas) {
            if (chartDailySales) chartDailySales.destroy();
            chartDailySales = new Chart(canvas, {
                type: 'line',
                data: {
                    labels: reportData.daily_sales.map(item => item.date),
                    datasets: [{
                        label: 'Ventas ($)',
                        data: reportData.daily_sales.map(item => item.total),
                        borderColor: '#d97706',
                        backgroundColor: 'rgba(217, 119, 6, 0.2)',
                        fill: true,
                        tension: 0.3
                    }]
                },
                options: { responsive: true, maintainAspectRatio: false }
            });
        }
    }

    reportFilterForm.addEventListener('submit', async event => {
        event.preventDefault();
        try {
            await fetchReport();
            logToTerminal('Éxito: Reporte actualizado.');
        } catch (error) {
            logToTerminal(`Error: ${error.message}`);
        }
    });

    async function downloadReport(type) {
        const response = await adminFetch(`/reports/exports/${type}?${reportQuery()}`);
        if (!response.ok) {
            logToTerminal(`Error: ${await responseError(response, 'No se pudo exportar el reporte.')}`);
            return;
        }
        const blobUrl = URL.createObjectURL(await response.blob());
        const disposition = response.headers.get('Content-Disposition') || '';
        const filename = disposition.match(/filename="([^"]+)"/)?.[1] || `${type}.csv`;
        const link = document.createElement('a');
        link.href = blobUrl;
        link.download = filename;
        link.click();
        URL.revokeObjectURL(blobUrl);
        logToTerminal(`Éxito: Exportación ${filename} generada.`);
    }

    exportSalesButton.addEventListener('click', () => downloadReport('sales'));
    exportOrdersButton.addEventListener('click', () => downloadReport('orders'));

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
            const isActive = prod.is_active !== false;
            const isAvailable = prod.is_available !== false;

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
                    <div style="display:flex;gap:6px;flex-wrap:wrap;margin:8px 0;">
                        <span class="stock-badge ${isActive ? 'available' : 'out'}">${isActive ? 'Activo' : 'Inactivo'}</span>
                        <span class="stock-badge ${isAvailable ? 'available' : 'low'}">${isAvailable ? 'Disponible' : 'Suspendido'}</span>
                    </div>
                    <div class="product-info"><h3>${pNombre}</h3></div>
                    <div class="product-footer">
                        <span class="product-price">$${pPrecio.toFixed(2)}</span>
                        <span class="product-stock-text">Stock: <strong>${pExistencia}</strong></span>
                    </div>
                </div>
            `;

            card.addEventListener('click', () => {
                openEditProductModal({
                    Codigo: pCodigo,
                    Nombre: pNombre,
                    Precio: pPrecio,
                    Existencia: pExistencia,
                    Imagen: pImagen,
                    Descripcion: prod.descripcion || prod.Descripcion,
                    CategoryId: prod.category_id,
                    IsActive: isActive,
                    IsAvailable: isAvailable
                });
            });

            productsGrid.appendChild(card);
        });

        lucide.createIcons();
    }

    function openEditProductModal(product) {
        editingProduct = product;
        editCodigo.value = product.Codigo;
        editNombre.value = product.Nombre;
        editDescripcion.value = product.Descripcion || '';
        editPrecio.value = product.Precio;
        editExistencia.value = product.Existencia;
        editImagen.value = product.Imagen || '';
        editCategory.value = product.CategoryId || '';
        toggleProductActiveButton.innerText = product.IsActive ? 'Desactivar producto' : 'Reactivar producto';
        toggleProductAvailabilityButton.innerText = product.IsAvailable ? 'Suspender venta' : 'Reanudar venta';
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
            categories.forEach(category => select.add(new Option(
                `${category.nombre}${category.is_active ? '' : ' (inactiva)'}`,
                category.id
            )));
            select.value = current;
        });
    }

    function renderCategories() {
        categoriesList.innerHTML = '';
        categories.forEach(category => {
            const row = document.createElement('div');
            row.style.cssText = 'display:flex;justify-content:space-between;gap:8px;margin:6px 0;';
            row.innerHTML = `
                <span>${category.icono || ''} ${category.nombre} (${category.products_count})
                    <strong>${category.is_active ? 'Activa' : 'Inactiva'}</strong>
                </span>
                <span><button data-edit>Editar</button> <button data-status>${category.is_active ? 'Desactivar' : 'Reactivar'}</button></span>
            `;
            row.querySelector('[data-edit]').addEventListener('click', async () => {
                const nombre = window.prompt('Nuevo nombre', category.nombre);
                if (!nombre) return;
                await adminFetch(`/categories/${category.id}`, { method: 'PUT', body: JSON.stringify({ nombre, icono: category.icono }) });
                refreshAllData();
            });
            row.querySelector('[data-status]').addEventListener('click', async () => {
                const action = category.is_active ? 'desactivar' : 'reactivar';
                if (!window.confirm(`¿${action} la categoría ${category.nombre}? Los productos no se eliminarán.`)) return;
                await adminFetch(`/categories/${category.id}/status`, {
                    method: 'PATCH',
                    body: JSON.stringify({ is_active: !category.is_active })
                });
                refreshAllData();
            });
            categoriesList.appendChild(row);
        });
    }

    function renderMenus() {
        menusList.innerHTML = '';

        if (menus.length === 0) {
            menusList.innerHTML = '<p>No hay menús configurados.</p>';
            return;
        }

        menus.forEach(menu => {
            const productCodes = new Set((menu.products || []).map(product => product.codigo));
            const availableProducts = catalog.filter(product => !productCodes.has(product.codigo));
            const row = document.createElement('div');
            row.style.cssText = 'border-top:1px solid rgba(255,255,255,.12);padding:12px 0;';
            row.innerHTML = `
                <div style="display:flex;justify-content:space-between;gap:8px;">
                    <span><strong>${escapeHtml(menu.name)}</strong> — ${menu.is_active ? 'Activo' : 'Inactivo'}</span>
                    <span><button data-edit>Editar</button> <button data-status>${menu.is_active ? 'Desactivar' : 'Activar'}</button></span>
                </div>
                <p style="color:var(--text-muted);">${escapeHtml(menu.description || 'Sin descripción')}</p>
                <div data-products style="display:flex;gap:6px;flex-wrap:wrap;margin:8px 0;"></div>
                <div style="display:flex;gap:6px;">
                    <select data-product-select style="flex:1;"></select>
                    <button data-add-product ${availableProducts.length ? '' : 'disabled'}>Agregar</button>
                </div>
            `;

            const productList = row.querySelector('[data-products]');
            if ((menu.products || []).length === 0) {
                productList.innerHTML = '<span>Sin productos.</span>';
            } else {
                menu.products.forEach(product => {
                    const chip = document.createElement('span');
                    chip.innerHTML = `${escapeHtml(product.nombre)} <button type="button" title="Retirar del menú">&times;</button>`;
                    chip.querySelector('button').addEventListener('click', async () => {
                        await adminFetch(`/menus/${menu.id}/products/${product.codigo}`, { method: 'DELETE' });
                        refreshAllData();
                    });
                    productList.appendChild(chip);
                });
            }

            const productSelect = row.querySelector('[data-product-select]');
            availableProducts.forEach(product => productSelect.add(new Option(
                `[${product.codigo}] ${product.nombre}`,
                product.codigo
            )));

            row.querySelector('[data-add-product]').addEventListener('click', async () => {
                if (!productSelect.value) return;
                await adminFetch(`/menus/${menu.id}/products/${productSelect.value}`, { method: 'PUT' });
                refreshAllData();
            });

            row.querySelector('[data-edit]').addEventListener('click', async () => {
                const name = window.prompt('Nombre del menú', menu.name);
                if (!name) return;
                const description = window.prompt('Descripción', menu.description || '') ?? menu.description;
                await adminFetch(`/menus/${menu.id}`, {
                    method: 'PUT',
                    body: JSON.stringify({ name, description })
                });
                refreshAllData();
            });

            row.querySelector('[data-status]').addEventListener('click', async () => {
                await adminFetch(`/menus/${menu.id}/status`, {
                    method: 'PATCH',
                    body: JSON.stringify({ is_active: !menu.is_active })
                });
                refreshAllData();
            });

            menusList.appendChild(row);
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

    formMenu.addEventListener('submit', async (event) => {
        event.preventDefault();
        const name = document.getElementById('menu-name').value.trim();
        const description = document.getElementById('menu-description').value.trim() || null;
        const response = await adminFetch('/menus', {
            method: 'POST',
            body: JSON.stringify({ name, description })
        });
        if (response.ok) {
            logToTerminal(`Éxito: Menú ${name} creado.`);
            formMenu.reset();
            refreshAllData();
        }
    });

    toggleProductActiveButton.addEventListener('click', async () => {
        if (!editingProduct) return;
        const nextState = !editingProduct.IsActive;
        const action = nextState ? 'reactivar' : 'desactivar';
        if (!window.confirm(`¿${action} el producto ${editingProduct.Codigo}? No se eliminará su historial.`)) return;
        const response = await adminFetch(`/products/${editingProduct.Codigo}/status`, {
            method: 'PATCH',
            body: JSON.stringify({ is_active: nextState })
        });
        if (response.ok) {
            logToTerminal(`Éxito: Producto ${nextState ? 'reactivado' : 'desactivado'}.`);
            editProductModal.classList.remove('active');
            refreshAllData();
        } else {
            const data = await response.json();
            logToTerminal(`Error: ${data.message || 'No se pudo cambiar la vigencia.'}`);
        }
    });

    toggleProductAvailabilityButton.addEventListener('click', async () => {
        if (!editingProduct) return;
        const nextState = !editingProduct.IsAvailable;
        const response = await adminFetch(`/products/${editingProduct.Codigo}/availability`, {
            method: 'PATCH',
            body: JSON.stringify({ is_available: nextState })
        });
        if (response.ok) {
            logToTerminal(`Éxito: Venta ${nextState ? 'reanudada' : 'suspendida'} temporalmente.`);
            editProductModal.classList.remove('active');
            refreshAllData();
        } else {
            const data = await response.json();
            logToTerminal(`Error: ${data.message || 'No se pudo cambiar la disponibilidad.'}`);
        }
    });

    searchInput.addEventListener('input', renderCatalog);
    clearTerminalBtn.addEventListener('click', () => {
        terminalBody.innerHTML = '';
        logToTerminal('Logs administrativos limpiados.');
    });

    refreshAllData();
});
