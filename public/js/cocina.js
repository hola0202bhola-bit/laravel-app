document.addEventListener('DOMContentLoaded', () => {
    let orders = [];

    const cardsPendiente = document.getElementById('cards-pendiente');
    const cardsPreparacion = document.getElementById('cards-preparacion');
    const cardsListo = document.getElementById('cards-listo');

    const countPendiente = document.getElementById('count-pendiente');
    const countPreparacion = document.getElementById('count-preparacion');
    const countListo = document.getElementById('count-listo');

    lucide.createIcons();

    // SSE Connection
    const evtSource = new EventSource('/api/events');
    evtSource.addEventListener('nuevo_pedido', () => fetchKitchenOrders());
    evtSource.addEventListener('pedido_estado_cambiado', () => fetchKitchenOrders());

    async function fetchKitchenOrders() {
        try {
            const res = await fetch('/api/cocina/pedidos?t=' + Date.now());
            orders = await res.json();
            renderKanbanBoard();
        } catch (e) {
            console.error('Error fetching kitchen orders', e);
        }
    }

    async function changeOrderStatus(orderId, nextState) {
        try {
            await fetch('/api/cocina/estado', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ order_id: orderId, estado: nextState })
            });
            fetchKitchenOrders();
        } catch (e) {
            console.error('Error updating order state', e);
        }
    }

    function renderKanbanBoard() {
        cardsPendiente.innerHTML = '';
        cardsPreparacion.innerHTML = '';
        cardsListo.innerHTML = '';

        const pendientes = orders.filter(o => o.estado === 'pendiente');
        const preparaciones = orders.filter(o => o.estado === 'en_preparacion');
        const listos = orders.filter(o => o.estado === 'listo' || o.estado === 'entregado');

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

            itemsHTML += `
                <div>
                    <div class="kds-item-row">
                        <span>${item.nombre} (${item.tamano})</span>
                        <span>x${item.cantidad}</span>
                    </div>
                    ${extrasText}
                </div>
            `;
        });

        let actionBtnHTML = '';
        if (colType === 'pending') {
            actionBtnHTML = `
                <button type="button" class="btn-kds-action btn-kds-start">
                    <i data-lucide="play"></i> Iniciar Preparación
                </button>
            `;
        } else if (colType === 'prep') {
            actionBtnHTML = `
                <button type="button" class="btn-kds-action btn-kds-finish">
                    <i data-lucide="check-circle-2"></i> Marcar Listo
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
            <div class="kds-items-list">
                ${itemsHTML}
            </div>
            <div class="kds-card-actions">
                ${actionBtnHTML}
            </div>
        `;

        if (colType === 'pending') {
            card.querySelector('.btn-kds-start').addEventListener('click', () => changeOrderStatus(order.id, 'en_preparacion'));
        } else if (colType === 'prep') {
            card.querySelector('.btn-kds-finish').addEventListener('click', () => changeOrderStatus(order.id, 'listo'));
        }

        return card;
    }

    fetchKitchenOrders();
    setInterval(fetchKitchenOrders, 5000);
});
