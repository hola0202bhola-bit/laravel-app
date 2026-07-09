document.addEventListener('DOMContentLoaded', () => {
    let catalog = [];
    let cart = [];
    let currentProduct = null;
    let selectedQuantity = 1;

    let activeTag = '';
    let activeAllergen = '';
    let activeSort = 'codigo';

    // DOM References
    const productsGrid = document.getElementById('products-grid');
    const searchInput = document.getElementById('search-input');
    const terminalBody = document.getElementById('terminal-body');

    // Filters DOM
    const allergenFilter = document.getElementById('allergen-filter');
    const sortFilter = document.getElementById('sort-filter');

    // Cart Elements
    const cartEmptyMsg = document.getElementById('cart-empty-msg');
    const cartListContainer = document.getElementById('cart-list-container');
    const cartItemsWrapper = document.getElementById('cart-items');
    const cartTotalAmount = document.getElementById('cart-total-amount');
    const clearCartBtn = document.getElementById('clear-cart-btn');
    const checkoutCartBtn = document.getElementById('checkout-cart-btn');

    // Modal Customizer
    const customizerModal = document.getElementById('customizer-modal');
    const closeCustomizer = document.getElementById('close-customizer');
    const custProductName = document.getElementById('cust-product-name');
    const custProductImg = document.getElementById('cust-product-img');
    const custProductDesc = document.getElementById('cust-product-desc');
    const custBasePrice = document.getElementById('cust-base-price');
    const custTotalBtn = document.getElementById('cust-total-btn');
    const extrasGrid = document.getElementById('extras-grid');
    const extrasSection = document.getElementById('extras-section');
    const tableNumberGroup = document.getElementById('table-number-group');
    const tableInput = document.getElementById('table-input');
    const qtyVal = document.getElementById('qty-val');
    const qtyMinus = document.getElementById('qty-minus');
    const qtyPlus = document.getElementById('qty-plus');
    const addCustomizedToCartBtn = document.getElementById('add-customized-to-cart');

    // Modal Custom Builder
    const customBuilderModal = document.getElementById('custom-builder-modal');
    const openBuilderBtn = document.getElementById('open-builder-btn');
    const closeBuilder = document.getElementById('close-builder');
    const builderCreationName = document.getElementById('builder-creation-name');
    const builderBaseSelect = document.getElementById('builder-base-select');
    const builderSweetSelect = document.getElementById('builder-sweet-select');
    const builderMilkSelect = document.getElementById('builder-milk-select');
    const builderTempSelect = document.getElementById('builder-temp-select');
    const builderTotalPrice = document.getElementById('builder-total-price');
    const addBuilderToCartBtn = document.getElementById('add-builder-to-cart');

    // Modal Reservation
    const reservationModal = document.getElementById('reservation-modal');
    const openReservationBtn = document.getElementById('open-reservation-btn');
    const closeReservation = document.getElementById('close-reservation');
    const formReservar = document.getElementById('form-reservar');
    const resMesaSelect = document.getElementById('res-mesa');

    // Modal Checkout & Ticket
    const checkoutModal = document.getElementById('checkout-modal');
    const closeCheckout = document.getElementById('close-checkout');
    const confirmPaymentBtn = document.getElementById('confirm-payment-btn');
    const printTicketBtn = document.getElementById('print-ticket-btn');

    lucide.createIcons();

    // SSE Connection
    const evtSource = new EventSource('/api/events');
    evtSource.addEventListener('productos_actualizados', () => fetchProducts());

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

    // API Calls
    async function fetchProducts() {
        try {
            const params = new URLSearchParams({
                tag: activeTag,
                exclude_allergen: activeAllergen,
                sort: activeSort,
                t: Date.now()
            });

            const res = await fetch(`/api/productos?${params}`);
            catalog = await res.json();
            renderCatalog();
        } catch (e) {
            logToTerminal('Error al cargar catálogo.');
        }
    }

    async function fetchTablesForReservation() {
        try {
            const res = await fetch('/api/mesas');
            const tables = await res.json();
            resMesaSelect.innerHTML = '';
            tables.forEach(t => {
                const optText = `${t.numero} (${t.ubicacion} - Capacidad: ${t.capacidad} pers.)`;
                resMesaSelect.add(new Option(optText, t.id));
            });
        } catch (e) {}
    }

    // Filter Chips Events
    document.querySelectorAll('.filter-chip').forEach(chip => {
        chip.addEventListener('click', () => {
            document.querySelectorAll('.filter-chip').forEach(c => c.classList.remove('active'));
            chip.classList.add('active');
            activeTag = chip.getAttribute('data-tag');
            fetchProducts();
        });
    });

    allergenFilter.addEventListener('change', () => {
        activeAllergen = allergenFilter.value;
        fetchProducts();
    });

    sortFilter.addEventListener('change', () => {
        activeSort = sortFilter.value;
        fetchProducts();
    });

    // Custom Builder Events
    openBuilderBtn.addEventListener('click', () => {
        calculateBuilderTotal();
        customBuilderModal.classList.add('active');
    });

    closeBuilder.addEventListener('click', () => customBuilderModal.classList.remove('active'));

    [builderBaseSelect, builderSweetSelect, builderMilkSelect, builderTempSelect].forEach(sel => {
        sel.addEventListener('change', calculateBuilderTotal);
    });

    function calculateBuilderTotal() {
        const basePrice = parseFloat(builderBaseSelect.options[builderBaseSelect.selectedIndex].getAttribute('data-precio') || 0);
        const milkPrice = parseFloat(builderMilkSelect.options[builderMilkSelect.selectedIndex].getAttribute('data-precio') || 0);
        const tempPrice = parseFloat(builderTempSelect.options[builderTempSelect.selectedIndex].getAttribute('data-precio') || 0);

        const total = basePrice + milkPrice + tempPrice;
        builderTotalPrice.innerText = `$${total.toFixed(2)}`;
        return total;
    }

    addBuilderToCartBtn.addEventListener('click', () => {
        const total = calculateBuilderTotal();
        const name = builderCreationName.value.trim() || 'Bebida Personalizada';
        const base = builderBaseSelect.value;
        const sweet = builderSweetSelect.value;
        const milk = builderMilkSelect.value;
        const temp = builderTempSelect.value;

        cart.push({
            id: Date.now(),
            codigo: 999,
            nombre: name,
            tamano: 'Custom',
            precioBase: total,
            extras: [
                { nombre: `Base: ${base}`, precio: 0 },
                { nombre: sweet, precio: 0 },
                { nombre: milk, precio: 0 },
                { nombre: temp, precio: 0 }
            ],
            precioFinalUnitario: total,
            cantidad: 1,
            subtotal: total
        });

        logToTerminal(`Añadido al carrito: ${name}`);
        updateCartUI();
        customBuilderModal.classList.remove('active');
    });

    // Reservation Events
    openReservationBtn.addEventListener('click', () => {
        fetchTablesForReservation();
        document.getElementById('res-fecha').value = new Date().toISOString().split('T')[0];
        reservationModal.classList.add('active');
    });

    closeReservation.addEventListener('click', () => reservationModal.classList.remove('active'));

    formReservar.addEventListener('submit', async (e) => {
        e.preventDefault();
        const payload = {
            cliente_nombre: document.getElementById('res-nombre').value.trim(),
            cliente_telefono: document.getElementById('res-telefono').value.trim(),
            fecha: document.getElementById('res-fecha').value,
            hora: document.getElementById('res-hora').value,
            personas: parseInt(document.getElementById('res-personas').value),
            dining_table_id: parseInt(resMesaSelect.value),
            notas: document.getElementById('res-notas').value.trim()
        };

        try {
            const res = await fetch('/api/reservaciones/crear', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            const data = await res.json();
            if (res.ok) {
                logToTerminal(data.message);
                reservationModal.classList.remove('active');
                formReservar.reset();
            } else {
                logToTerminal(data.error || 'Error al reservar.');
            }
        } catch (err) {
            logToTerminal('Error de conexión al reservar.');
        }
    });

    // Render Catalog
    function renderCatalog() {
        const filter = searchInput.value.toLowerCase().trim();
        productsGrid.innerHTML = '';

        const filteredProducts = catalog.filter(prod => {
            const pNombre = prod.nombre || prod.Nombre || '';
            return pNombre.toLowerCase().includes(filter);
        });

        if (filteredProducts.length === 0) {
            productsGrid.innerHTML = `
                <div class="glass-card" style="grid-column: 1 / -1; padding: 40px; text-align: center; color: var(--text-muted);">
                    <i data-lucide="package-search" style="width: 48px; height: 48px; margin-bottom: 12px; opacity: 0.5;"></i>
                    <p>No se encontraron platillos con los filtros seleccionados.</p>
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
                if (pExistencia <= 0) {
                    logToTerminal(`Error: No hay stock de '${pNombre}'.`);
                    return;
                }
                openCustomizerModal({
                    Codigo: pCodigo, Nombre: pNombre, Precio: pPrecio, Existencia: pExistencia,
                    Imagen: pImagen, Descripcion: prod.descripcion || prod.Descripcion,
                    IngredientesExtras: prod.extras || prod.IngredientesExtras || []
                });
            });

            productsGrid.appendChild(card);
        });

        lucide.createIcons();
    }

    function openCustomizerModal(product) {
        currentProduct = product;
        selectedQuantity = 1;
        qtyVal.innerText = '1';

        custProductName.innerText = product.Nombre;
        custBasePrice.innerText = `$${product.Precio.toFixed(2)}`;
        custProductDesc.innerText = product.Descripcion || 'Sin descripción disponible.';

        if (product.Imagen) {
            custProductImg.src = product.Imagen;
            custProductImg.style.display = 'block';
        } else {
            custProductImg.style.display = 'none';
        }

        document.querySelectorAll('.size-chip').forEach(chip => {
            const input = chip.querySelector('input');
            if (input.value === 'Chico') {
                input.checked = true; chip.classList.add('active');
            } else {
                input.checked = false; chip.classList.remove('active');
            }
        });

        extrasGrid.innerHTML = '';
        const extras = product.IngredientesExtras || [];
        if (extras.length === 0) {
            extrasSection.style.display = 'none';
        } else {
            extrasSection.style.display = 'block';
            extras.forEach(ext => {
                const label = document.createElement('label');
                label.className = 'extra-card-label';
                label.innerHTML = `<span><input type="checkbox" value="${ext.id}" data-nombre="${ext.nombre}" data-precio="${ext.precio}"> ${ext.nombre}</span><strong>+$${parseFloat(ext.precio).toFixed(2)}</strong>`;
                label.querySelector('input').addEventListener('change', calculateCustomizerTotal);
                extrasGrid.appendChild(label);
            });
        }

        calculateCustomizerTotal();
        customizerModal.classList.add('active');
    }

    function calculateCustomizerTotal() {
        if (!currentProduct) return;
        let unitPrice = currentProduct.Precio;
        const selectedSize = document.querySelector('input[name="size-option"]:checked').value;
        if (selectedSize === 'Mediano') unitPrice += 5.00;
        if (selectedSize === 'Grande') unitPrice += 10.00;

        let extrasTotal = 0;
        document.querySelectorAll('.extra-card-label input:checked').forEach(cb => {
            extrasTotal += parseFloat(cb.getAttribute('data-precio'));
        });

        const total = (unitPrice + extrasTotal) * selectedQuantity;
        custTotalBtn.innerText = `$${total.toFixed(2)}`;
    }

    document.querySelectorAll('.size-chip').forEach(chip => {
        chip.addEventListener('click', () => {
            document.querySelectorAll('.size-chip').forEach(c => c.classList.remove('active'));
            chip.classList.add('active'); chip.querySelector('input').checked = true;
            calculateCustomizerTotal();
        });
    });

    document.querySelectorAll('.dest-chip').forEach(chip => {
        chip.addEventListener('click', () => {
            document.querySelectorAll('.dest-chip').forEach(c => c.classList.remove('active'));
            chip.classList.add('active');
            const val = chip.querySelector('input').value;
            chip.querySelector('input').checked = true;
            tableNumberGroup.style.display = val === 'mesa' ? 'block' : 'none';
        });
    });

    document.querySelectorAll('.pay-chip').forEach(chip => {
        chip.addEventListener('click', () => {
            document.querySelectorAll('.pay-chip').forEach(c => c.classList.remove('active'));
            chip.classList.add('active'); chip.querySelector('input').checked = true;
            updateTicketDisplay();
        });
    });

    qtyMinus.addEventListener('click', () => {
        if (selectedQuantity > 1) {
            selectedQuantity--; qtyVal.innerText = selectedQuantity; calculateCustomizerTotal();
        }
    });

    qtyPlus.addEventListener('click', () => {
        if (selectedQuantity < currentProduct.Existencia) {
            selectedQuantity++; qtyVal.innerText = selectedQuantity; calculateCustomizerTotal();
        }
    });

    addCustomizedToCartBtn.addEventListener('click', () => {
        const selectedSize = document.querySelector('input[name="size-option"]:checked').value;
        const selectedExtras = [];
        document.querySelectorAll('.extra-card-label input:checked').forEach(cb => {
            selectedExtras.push({ id: cb.value, nombre: cb.getAttribute('data-nombre'), precio: parseFloat(cb.getAttribute('data-precio')) });
        });

        let unitPrice = currentProduct.Precio;
        if (selectedSize === 'Mediano') unitPrice += 5.00;
        if (selectedSize === 'Grande') unitPrice += 10.00;

        const extrasCost = selectedExtras.reduce((sum, e) => sum + e.precio, 0);
        const finalUnitPrice = unitPrice + extrasCost;

        cart.push({
            id: Date.now(), codigo: currentProduct.Codigo, nombre: currentProduct.Nombre,
            tamano: selectedSize, precioBase: currentProduct.Precio, extras: selectedExtras,
            precioFinalUnitario: finalUnitPrice, cantidad: selectedQuantity, subtotal: finalUnitPrice * selectedQuantity
        });

        logToTerminal(`Añadido: ${currentProduct.Nombre} (${selectedSize}) x${selectedQuantity}`);
        updateCartUI();
        customizerModal.classList.remove('active');
    });

    closeCustomizer.addEventListener('click', () => customizerModal.classList.remove('active'));

    function updateCartUI() {
        if (cart.length === 0) {
            cartEmptyMsg.style.display = 'flex'; cartListContainer.style.display = 'none'; return;
        }
        cartEmptyMsg.style.display = 'none'; cartListContainer.style.display = 'flex'; cartItemsWrapper.innerHTML = '';
        let total = 0;
        cart.forEach((item, index) => {
            total += item.subtotal;
            const extrasText = item.extras.length > 0 ? ` + (${item.extras.map(e => e.nombre).join(', ')})` : '';
            const div = document.createElement('div');
            div.className = 'cart-item';
            div.innerHTML = `
                <div class="cart-item-info">
                    <span class="cart-item-name">${item.nombre} <small>(${item.tamano})</small></span>
                    <span class="cart-item-details">Cantidad: <strong>x${item.cantidad}</strong> ${extrasText}</span>
                </div>
                <span class="cart-item-price">$${item.subtotal.toFixed(2)}</span>
                <button type="button" class="btn-item-delete" title="Eliminar"><i data-lucide="trash-2"></i></button>
            `;
            div.querySelector('.btn-item-delete').addEventListener('click', () => { cart.splice(index, 1); updateCartUI(); });
            cartItemsWrapper.appendChild(div);
        });
        cartTotalAmount.innerText = `$${total.toFixed(2)}`;
        lucide.createIcons();
    }

    clearCartBtn.addEventListener('click', () => { cart = []; updateCartUI(); logToTerminal('Carrito vaciado.'); });
    checkoutCartBtn.addEventListener('click', () => { if (cart.length === 0) return; updateTicketDisplay(); checkoutModal.classList.add('active'); });
    closeCheckout.addEventListener('click', () => checkoutModal.classList.remove('active'));

    function updateTicketDisplay() {
        const ticketDateStr = document.getElementById('ticket-date-str');
        const ticketOrderType = document.getElementById('ticket-order-type');
        const ticketPayMethod = document.getElementById('ticket-pay-method');
        const ticketItemsList = document.getElementById('ticket-items-list');
        const ticketSubtotal = document.getElementById('ticket-subtotal');
        const ticketTax = document.getElementById('ticket-tax');
        const ticketTotal = document.getElementById('ticket-total');

        ticketDateStr.innerText = new Date().toLocaleString();
        const destVal = document.querySelector('input[name="dest-option"]:checked').value;
        ticketOrderType.innerText = destVal === 'mesa' ? `Mesa: ${tableInput.value || 'Mesa 1'}` : (destVal === 'delivery' ? 'Tipo: Delivery (Rappi)' : 'Tipo: Para Llevar');

        const payVal = document.querySelector('input[name="pay-option"]:checked').value;
        ticketPayMethod.innerText = `Pago: ${payVal.toUpperCase()}`;

        ticketItemsList.innerHTML = '';
        let grandTotal = 0;
        cart.forEach(item => {
            grandTotal += item.subtotal;
            const itemDiv = document.createElement('div');
            itemDiv.className = 't-item-wrapper';
            const extrasSub = item.extras.length > 0 ? `<div class="t-item-sub">+ ${item.extras.map(e => e.nombre).join(', ')}</div>` : '';
            itemDiv.innerHTML = `<div class="t-item-row"><span class="t-item-name">${item.nombre} (${item.tamano}) x${item.cantidad}</span><span>$${item.subtotal.toFixed(2)}</span></div>${extrasSub}`;
            ticketItemsList.appendChild(itemDiv);
        });

        const subtotal = grandTotal / 1.16;
        ticketSubtotal.innerText = `$${subtotal.toFixed(2)}`;
        ticketTax.innerText = `$${(grandTotal - subtotal).toFixed(2)}`;
        ticketTotal.innerText = `$${grandTotal.toFixed(2)}`;
    }

    confirmPaymentBtn.addEventListener('click', async () => {
        const selectedDestination = document.querySelector('input[name="dest-option"]:checked').value;
        const selectedPaymentMethod = document.querySelector('input[name="pay-option"]:checked').value;
        
        const payload = {
            tipoPedido: selectedDestination,
            numeroMesa: selectedDestination === 'mesa' ? tableInput.value : null,
            metodoPago: selectedPaymentMethod,
            items: cart
        };

        try {
            const response = await fetch('/api/pedidos/crear', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            const data = await response.json();
            if (response.ok) {
                logToTerminal(data.message);
                if (data.pedido && data.pedido.codigoDelivery) {
                    logToTerminal(`CÓDIGO REPARTIDOR (RAPPI): ${data.pedido.codigoDelivery}`);
                }
                cart = [];
                updateCartUI();
                checkoutModal.classList.remove('active');
            } else {
                logToTerminal(data.error || 'Error al enviar pedido.');
            }
        } catch (err) { logToTerminal('Error de conexión al enviar pedido.'); }
    });

    printTicketBtn.addEventListener('click', () => window.print());
    searchInput.addEventListener('input', renderCatalog);

    fetchProducts();
});
