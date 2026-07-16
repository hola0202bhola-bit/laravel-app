document.addEventListener('DOMContentLoaded', () => {
    let orders = [];

    const cardsPendiente = document.getElementById('cards-pendiente');
    const cardsPreparacion = document.getElementById('cards-preparacion');
    const cardsListo = document.getElementById('cards-listo');

    const countPendiente = document.getElementById('count-pendiente');
    const countPreparacion = document.getElementById('count-preparacion');
    const countListo = document.getElementById('count-listo');

    const kdsLoginModal = document.getElementById('kds-login-modal');
    const kdsLoginForm = document.getElementById('kds-login-form');
    const kdsLogoutBtn = document.getElementById('kds-logout-btn');
    const kdsEmail = document.getElementById('kds-email');
    const kdsPassword = document.getElementById('kds-password');
    const kdsLoginError = document.getElementById('kds-login-error');

    lucide.createIcons();

    // Check auth session
    function checkAuth() {
        const token = sessionStorage.getItem('kds_token');
        if (!token) {
            showLoginModal();
        } else {
            if (kdsLoginModal) kdsLoginModal.style.display = 'none';
            if (kdsLogoutBtn) kdsLogoutBtn.style.display = 'inline-flex';
            fetchKitchenOrders();
        }
    }

    function showLoginModal() {
        if (kdsLoginModal) kdsLoginModal.style.display = 'flex';
        if (kdsLogoutBtn) kdsLogoutBtn.style.display = 'none';
        orders = [];
        renderKanbanBoard();
    }

    // Login Form Submit
    if (kdsLoginForm) {
        kdsLoginForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            if (kdsLoginError) kdsLoginError.style.display = 'none';

            try {
                const res = await fetch('/api/auth/login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ email: kdsEmail.value, password: kdsPassword.value })
                });

                const data = await res.json();
                if (!res.ok) {
                    throw new Error(data.error || 'Credenciales incorrectas.');
                }

                sessionStorage.setItem('kds_token', data.token);
                sessionStorage.setItem('kds_roles', JSON.stringify(data.user.roles));
                checkAuth();
            } catch (err) {
                if (kdsLoginError) {
                    kdsLoginError.innerText = err.message;
                    kdsLoginError.style.display = 'block';
                }
            }
        });
    }

    // Logout Action
    if (kdsLogoutBtn) {
        kdsLogoutBtn.addEventListener('click', async () => {
            const token = sessionStorage.getItem('kds_token');
            if (token) {
                try {
                    await fetch('/api/auth/logout', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Authorization': 'Bearer ' + token
                        }
                    });
                } catch (e) {}
            }
            sessionStorage.removeItem('kds_token');
            sessionStorage.removeItem('kds_roles');
            showLoginModal();
        });
    }

    async function fetchKitchenOrders() {
        const token = sessionStorage.getItem('kds_token');
        if (!token) return;

        try {
            const res = await fetch('/api/cocina/pedidos?t=' + Date.now(), {
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + token
                }
            });

            if (res.status === 401 || res.status === 403) {
                sessionStorage.removeItem('kds_token');
                showLoginModal();
                return;
            }

            orders = await res.json();
            renderKanbanBoard();
        } catch (e) {
            console.error('Error fetching kitchen orders', e);
        }
    }

    async function changeOrderStatus(orderId, nextState) {
        const token = sessionStorage.getItem('kds_token');
        if (!token) return;

        try {
            const res = await fetch('/api/cocina/estado', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + token
                },
                body: JSON.stringify({ order_id: orderId, estado: nextState })
            });

            if (res.status === 401 || res.status === 403) {
                sessionStorage.removeItem('kds_token');
                showLoginModal();
                return;
            }

            fetchKitchenOrders();
        } catch (e) {
            console.error('Error updating order state', e);
        }
    }

    async function changeItemStatus(orderId, itemId, nextState, motivo = null) {
        const token = sessionStorage.getItem('kds_token');
        if (!token) return;

        try {
            const payload = { order_id: orderId, item_id: itemId, estado: nextState };
            if (motivo) payload.motivo = motivo;

            const res = await fetch('/api/cocina/items/estado', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + token
                },
                body: JSON.stringify(payload)
            });

            if (res.status === 401 || res.status === 403) {
                sessionStorage.removeItem('kds_token');
                showLoginModal();
                return;
            }

            if (res.status === 422 || res.status === 409) {
                const data = await res.json();
                alert(data.error || data.message || 'Error al actualizar el estado del producto.');
            }

            fetchKitchenOrders();
        } catch (e) {
            console.error('Error updating item state', e);
        }
    }

    function renderKanbanBoard() {
        cardsPendiente.innerHTML = '';
        cardsPreparacion.innerHTML = '';
        cardsListo.innerHTML = '';

        // Filter Kanban columns based on orders.estado_preparacion
        const pendientes = orders.filter(o => o.estado_preparacion === 'pendiente');
        const preparaciones = orders.filter(o => o.estado_preparacion === 'en_preparacion');
        const listos = orders.filter(o => o.estado_preparacion === 'listo');

        countPendiente.innerText = pendientes.length;
        countPreparacion.innerText = preparaciones.length;
        countListo.innerText = listos.length;

        // Render Pendientes
        pendientes.forEach(order => {
            cardsPendiente.appendChild(createOrderCard(order, 'pending'));
        });

        // Render Preparacion
        preparaciones.forEach(order => {
            cardsPreparacion.appendChild(createOrderCard(order, 'prep'));
        });

        // Render Listos
        listos.forEach(order => {
            cardsListo.appendChild(createOrderCard(order, 'ready'));
        });

        lucide.createIcons();
    }

    function createOrderCard(order, colType) {
        const card = document.createElement('div');
        card.className = `kds-card ${colType}-card`;

        const minutesAgo = Math.floor((new Date() - new Date(order.created_at)) / 60000);

        let itemsHTML = '';
        const items = order.items || [];
        items.forEach(item => {
            const extrasText = item.extras && item.extras.length > 0
                ? `<div class="kds-item-extras">+ ${item.extras.map(e => e.nombre).join(', ')}</div>`
                : '';

            const statusVal = item.estado || 'pendiente';
            let badgeColor = '#ef4444'; // Red for pending/canceled
            if (statusVal === 'en_preparacion') badgeColor = '#f59e0b'; // Amber
            if (statusVal === 'listo') badgeColor = '#10b981'; // Green

            let actionsHTML = '';
            if (statusVal === 'pendiente') {
                actionsHTML = `
                    <div style="display:flex; gap:6px; align-items:center;">
                        <button type="button" class="btn-start-item" data-item-id="${item.id}" style="background-color:#f59e0b; color:#111827; border:none; padding:4px 8px; border-radius:4px; font-size:0.75rem; font-weight:700; cursor:pointer;" title="Iniciar"><i data-lucide="play" style="width:12px; height:12px;"></i></button>
                        <button type="button" class="btn-cancel-item" data-item-id="${item.id}" style="background-color:#ef4444; color:white; border:none; padding:4px 8px; border-radius:4px; font-size:0.75rem; font-weight:700; cursor:pointer;" title="Cancelar"><i data-lucide="x" style="width:12px; height:12px;"></i></button>
                    </div>
                `;
            } else if (statusVal === 'en_preparacion') {
                actionsHTML = `
                    <div style="display:flex; gap:6px; align-items:center;">
                        <button type="button" class="btn-finish-item" data-item-id="${item.id}" style="background-color:#10b981; color:white; border:none; padding:4px 8px; border-radius:4px; font-size:0.75rem; font-weight:700; cursor:pointer;" title="Listo"><i data-lucide="check" style="width:12px; height:12px;"></i></button>
                        <button type="button" class="btn-cancel-item" data-item-id="${item.id}" style="background-color:#ef4444; color:white; border:none; padding:4px 8px; border-radius:4px; font-size:0.75rem; font-weight:700; cursor:pointer;" title="Cancelar"><i data-lucide="x" style="width:12px; height:12px;"></i></button>
                    </div>
                `;
            } else if (statusVal === 'listo') {
                // Reversal check for listo -> en_preparacion (needs prompt reasoning)
                actionsHTML = `
                    <div style="display:flex; align-items:center; gap:5px;">
                        <span style="font-size:0.75rem; font-weight:700; padding:2px 6px; border-radius:4px; color:${badgeColor}; background:rgba(255,255,255,0.05); text-transform:uppercase;">${statusVal}</span>
                        <button type="button" class="btn-revert-item" data-item-id="${item.id}" style="background-color:rgba(255,255,255,0.05); color:#f59e0b; border:none; padding:4px 8px; border-radius:4px; font-size:0.75rem; cursor:pointer;" title="Revertir a Preparación"><i data-lucide="rotate-ccw" style="width:12px; height:12px;"></i></button>
                    </div>
                `;
            } else {
                actionsHTML = `<span style="font-size:0.75rem; font-weight:700; padding:2px 6px; border-radius:4px; color:${badgeColor}; background:rgba(255,255,255,0.05); text-transform:uppercase;">${statusVal}</span>`;
            }

            itemsHTML += `
                <div style="padding:8px 0; border-bottom:1px solid rgba(255,255,255,0.05);">
                    <div class="kds-item-row" style="display:flex; justify-content:space-between; align-items:center;">
                        <span style="font-weight:600; color:${statusVal === 'cancelado' ? 'var(--text-muted)' : 'white'}; text-decoration:${statusVal === 'cancelado' ? 'line-through' : 'none'};">
                            ${item.nombre} (${item.tamano}) x${item.cantidad}
                        </span>
                        ${actionsHTML}
                    </div>
                    ${extrasText}
                </div>
            `;
        });

        let actionBtnHTML = '';
        if (colType === 'pending') {
            actionBtnHTML = `
                <button type="button" class="btn-kds-action btn-kds-start">
                    <i data-lucide="play"></i> Iniciar Todo
                </button>
            `;
        } else if (colType === 'prep') {
            actionBtnHTML = `
                <button type="button" class="btn-kds-action btn-kds-finish">
                    <i data-lucide="check-circle-2"></i> Terminar Todo
                </button>
            `;
        } else {
            actionBtnHTML = `
                <div style="font-size:0.8rem; color:var(--success); font-weight:700; text-align:center; padding:6px;">
                    ✓ Pedido Completado
                </div>
            `;
        }

        const tipo = order.tipo_pedido || order.tipoPedido || 'llevar';
        const mesa = order.numero_mesa || order.numeroMesa;
        const deliveryCode = order.codigo_delivery || order.codigoDelivery;
        let destStr = 'Para Llevar';
        if (tipo === 'mesa') destStr = mesa || 'Mesa 1';
        if (tipo === 'delivery') destStr = `Delivery (${deliveryCode || '#RAPPI'})`;

        card.innerHTML = `
            <div class="kds-card-header">
                <span class="kds-order-num">PEDIDO #${order.id}</span>
                <span class="kds-time-elapsed">${minutesAgo} min</span>
            </div>
            <div style="font-size:0.8rem; color:var(--text-muted); font-weight:600;">
                Destino: <strong style="color:white;">${destStr}</strong>
            </div>
            <div class="kds-items-list" style="margin-top:10px;">
                ${itemsHTML}
            </div>
            <div class="kds-card-actions" style="margin-top:12px;">
                ${actionBtnHTML}
            </div>
        `;

        // Attach global status actions
        if (colType === 'pending') {
            card.querySelector('.btn-kds-start').addEventListener('click', () => changeOrderStatus(order.id, 'en_preparacion'));
        } else if (colType === 'prep') {
            card.querySelector('.btn-kds-finish').addEventListener('click', () => {
                if (confirm('¿Está seguro de que desea marcar todos los artículos del pedido como listos?')) {
                    changeOrderStatus(order.id, 'listo');
                }
            });
        }

        // Attach individual item action listeners
        card.querySelectorAll('.btn-start-item').forEach(btn => {
            btn.addEventListener('click', () => changeItemStatus(order.id, btn.getAttribute('data-item-id'), 'en_preparacion'));
        });
        card.querySelectorAll('.btn-finish-item').forEach(btn => {
            btn.addEventListener('click', () => changeItemStatus(order.id, btn.getAttribute('data-item-id'), 'listo'));
        });
        card.querySelectorAll('.btn-cancel-item').forEach(btn => {
            btn.addEventListener('click', () => changeItemStatus(order.id, btn.getAttribute('data-item-id'), 'cancelado'));
        });
        card.querySelectorAll('.btn-revert-item').forEach(btn => {
            btn.addEventListener('click', () => {
                const reason = prompt('Ingrese el motivo obligatorio para revertir el producto listo a preparación:');
                if (reason && reason.trim().length >= 5) {
                    changeItemStatus(order.id, btn.getAttribute('data-item-id'), 'en_preparacion', reason.trim());
                } else if (reason !== null) {
                    alert('El motivo debe tener al menos 5 caracteres.');
                }
            });
        });

        return card;
    }

    checkAuth();
    setInterval(fetchKitchenOrders, 5000);
});
