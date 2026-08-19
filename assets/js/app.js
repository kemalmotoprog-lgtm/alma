// ---------- Utilidades ----------
function toast(msg, type = 'success') {
    const t = document.getElementById('toast');
    if (!t) return;
    const iconos = { success: '✓', error: '✕', info: 'ℹ' };

    clearTimeout(t._timer);
    t.classList.remove('show');
    void t.offsetWidth; // fuerza a reiniciar la animación si se llama varias veces seguidas
    t.className = 'toast-' + type;
    t.innerHTML = `<span class="toast-icon">${iconos[type] || '✓'}</span><span>${msg}</span>`;
    t.classList.add('show');

    if (navigator.vibrate) navigator.vibrate(type === 'error' ? [40, 60, 40] : 20);

    t._timer = setTimeout(() => t.classList.remove('show'), 2200);
}

function money(n) {
    return '$' + Number(n).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// Fecha de HOY según el reloj local del celular/navegador (toISOString() usaría UTC y desfasa el día)
function fechaLocalHoy() {
    const d = new Date();
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const dia = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${dia}`;
}

async function api(url, method = 'GET', body = null) {
    const opts = { method, headers: {} };
    if (body) {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(body);
    }
    try {
        const res = await fetch(url, opts);
        return await res.json();
    } catch (e) {
        return { ok: false, error: 'Sin conexión. Revisa tu internet e intenta de nuevo.' };
    }
}

// ---------- Encargos ----------
function refrescarTotalesEncargo(card, encargo) {
    if (!encargo) return;
    card.dataset.precio = encargo.precio;
    const pagadoEl = card.querySelector('.encargo-totales .mono:first-child');
    const saldoEl = card.querySelector('.encargo-totales .saldo');
    if (pagadoEl) pagadoEl.textContent = 'Pagado: ' + money(encargo.pagado);
    if (saldoEl) {
        if (encargo.liquidado) {
            saldoEl.textContent = '✓ Liquidado';
            saldoEl.classList.remove('pendiente');
            saldoEl.classList.add('liquidado');
        } else {
            saldoEl.textContent = 'Saldo: ' + money(encargo.saldo);
            saldoEl.classList.remove('liquidado');
            saldoEl.classList.add('pendiente');
        }
    }
}

async function actualizarEncargo(id, payload) {
    const data = await api('api/encargos.php', 'PUT', { id, ...payload });
    if (data.ok) {
        toast('Guardado');
        const card = document.querySelector(`.encargo-card[data-encargo-id="${id}"]`);
        if (card) refrescarTotalesEncargo(card, data.encargo);
    } else {
        toast(data.error || 'Error al guardar', 'error');
    }
}

function toggleEstado(id, el) {
    const nuevo = el.classList.contains('entregado') ? 'por_entregar' : 'entregado';
    el.classList.remove('entregado', 'por_entregar');
    el.classList.add(nuevo);
    el.textContent = nuevo === 'entregado' ? 'Entregado' : 'Por entregar';
    actualizarEncargo(id, { estado: nuevo });
}

async function eliminarEncargo(id, btn) {
    if (!confirm('¿Eliminar este encargo y sus pagos?')) return;
    const data = await api('api/encargos.php?id=' + id, 'DELETE');
    if (data.ok) {
        const card = btn.closest('.encargo-card');
        const block = card.closest('.clienta-block');
        card.remove();
        if (block && !block.querySelector('.encargo-card')) block.remove();
        toast('Encargo eliminado');
    } else {
        toast('No se pudo eliminar', 'error');
    }
}

// ---------- Pagos ----------
async function agregarPago(encargoId) {
    const hoy = fechaLocalHoy();
    const data = await api('api/pagos.php', 'POST', { encargo_id: encargoId, monto: 0, fecha: hoy });
    if (!data.ok) { toast('No se pudo agregar el pago', 'error'); return; }

    const lista = document.getElementById('pagos-' + encargoId);
    const row = document.createElement('div');
    row.className = 'pago-row';
    row.dataset.pagoId = data.id;
    row.innerHTML = `
        <span class="mono">$</span>
        <input type="number" step="0.01" value="" onchange="actualizarPago(${data.id}, this)">
        <input type="date" value="${hoy}" onchange="actualizarPagoFecha(${data.id}, this)">
        <button class="icon-btn" onclick="eliminarPago(${data.id}, this)">✕</button>
    `;
    lista.appendChild(row);
    row.querySelector('input[type=number]').focus();

    const card = document.querySelector(`.encargo-card[data-encargo-id="${encargoId}"]`);
    if (card) refrescarTotalesEncargo(card, data.encargo);
}

async function actualizarPago(id, input) {
    const data = await api('api/pagos.php', 'PUT', { id, monto: input.value, fecha: input.closest('.pago-row').querySelector('input[type=date]').value });
    if (data.ok) {
        toast('Pago actualizado');
        const card = input.closest('.encargo-card');
        if (card) refrescarTotalesEncargo(card, data.encargo);
    } else {
        toast('Error al guardar el pago', 'error');
    }
}

async function actualizarPagoFecha(id, input) {
    const monto = input.closest('.pago-row').querySelector('input[type=number]').value;
    const data = await api('api/pagos.php', 'PUT', { id, monto, fecha: input.value });
    if (data.ok) {
        const card = input.closest('.encargo-card');
        if (card) refrescarTotalesEncargo(card, data.encargo);
    }
}

async function eliminarPago(id, btn) {
    const data = await api('api/pagos.php?id=' + id, 'DELETE');
    if (data.ok) {
        const row = btn.closest('.pago-row');
        const card = btn.closest('.encargo-card');
        row.remove();
        if (card && data.encargo) refrescarTotalesEncargo(card, data.encargo);
        toast('Pago eliminado');
    } else {
        toast('No se pudo eliminar el pago', 'error');
    }
}

// ---------- Modal nuevo encargo ----------
let clientaSeleccionada = null;
let buscarTimer = null;

function abrirModalEncargo() {
    document.getElementById('modalEncargo').classList.add('open');
}
function cerrarModalEncargo() {
    document.getElementById('modalEncargo').classList.remove('open');
    document.getElementById('clientaInput').value = '';
    document.getElementById('clientaIdSel').value = '';
    document.getElementById('clientaSugerencias').innerHTML = '';
    document.getElementById('aliasRow').style.display = 'none';
    document.getElementById('descInput').value = '';
    document.getElementById('precioInput').value = '';
    const prod = document.getElementById('productoSel');
    if (prod) prod.value = '';
    clientaSeleccionada = null;
}

function buscarClientas(q) {
    document.getElementById('clientaIdSel').value = '';
    clearTimeout(buscarTimer);
    const cont = document.getElementById('clientaSugerencias');
    const aliasRow = document.getElementById('aliasRow');
    if (q.trim().length < 2) { cont.innerHTML = ''; aliasRow.style.display = 'none'; return; }

    buscarTimer = setTimeout(async () => {
        const data = await api('api/clientas.php?q=' + encodeURIComponent(q));
        cont.innerHTML = '';
        if (data.ok && data.clientas.length) {
            data.clientas.forEach(c => {
                const div = document.createElement('div');
                div.className = 'btn-secondary';
                div.style.textAlign = 'left';
                div.style.padding = '9px 12px';
                div.style.cursor = 'pointer';
                div.textContent = c.nombre + (c.alias ? ' · "' + c.alias + '"' : '');
                div.onclick = () => seleccionarClienta(c);
                cont.appendChild(div);
            });
        }
        // Siempre ofrecer "crear nueva"
        const nueva = document.createElement('div');
        nueva.className = 'btn-secondary';
        nueva.style.textAlign = 'left';
        nueva.style.padding = '9px 12px';
        nueva.style.cursor = 'pointer';
        nueva.style.color = 'var(--accent)';
        nueva.textContent = '+ Crear clienta nueva "' + q + '"';
        nueva.onclick = () => {
            aliasRow.style.display = 'flex';
            clientaSeleccionada = { nueva: true, nombre: q };
            document.getElementById('clientaIdSel').value = '';
            cont.innerHTML = '';
        };
        cont.appendChild(nueva);
    }, 250);
}

function seleccionarClienta(c) {
    clientaSeleccionada = c;
    document.getElementById('clientaInput').value = c.nombre;
    document.getElementById('clientaIdSel').value = c.id;
    document.getElementById('clientaSugerencias').innerHTML = '';
    document.getElementById('aliasRow').style.display = 'none';
}

function autocompletarPrecio() {
    const sel = document.getElementById('productoSel');
    const opt = sel.options[sel.selectedIndex];
    const precio = opt ? opt.dataset.precio : '';
    if (precio) document.getElementById('precioInput').value = precio;
    if (!document.getElementById('descInput').value && opt && opt.value) {
        document.getElementById('descInput').value = opt.textContent.split(' (')[0];
    }
}

async function crearEncargo() {
    let clientaId = document.getElementById('clientaIdSel').value;

    if (!clientaId && clientaSeleccionada && clientaSeleccionada.nueva) {
        const alias = document.getElementById('clientaAlias').value.trim();
        const r = await api('api/clientas.php', 'POST', { nombre: clientaSeleccionada.nombre, alias });
        if (!r.ok) { toast('No se pudo crear la clienta', 'error'); return; }
        clientaId = r.id;
    }

    if (!clientaId) { toast('Selecciona o crea una clienta', 'error'); return; }

    const productoSel = document.getElementById('productoSel');
    const productoId = productoSel && productoSel.value ? productoSel.value : null;
    const descripcion = document.getElementById('descInput').value.trim();
    const precio = document.getElementById('precioInput').value || 0;
    const estado = document.getElementById('estadoInput').value;

    const r = await api('api/encargos.php', 'POST', {
        campana_id: CAMPANA_ID, clienta_id: clientaId, producto_id: productoId,
        descripcion, precio, estado
    });

    if (r.ok) {
        toast('Encargo agregado');
        setTimeout(() => location.reload(), 700);
    } else {
        toast(r.error || 'No se pudo guardar', 'error');
    }
}

// ---------- Exportar a Telegram ----------
async function enviarTelegram() {
    toast('Enviando a Telegram...', 'info');
    const r = await api('export_telegram.php?campana_id=' + CAMPANA_ID, 'POST');
    if (r.ok) {
        toast('Reporte enviado a Telegram');
    } else {
        toast(r.error || 'No se pudo enviar', 'error');
    }
}
