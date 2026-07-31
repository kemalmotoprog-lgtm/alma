// ---------- Edición inline ----------
async function actualizarProducto(id, payload) {
    const data = await api('api/productos.php', 'PUT', { id, ...payload });
    if (data.ok) {
        toast('Guardado');
    } else {
        toast(data.error || 'Error al guardar');
    }
}

async function eliminarProducto(id, btn) {
    if (!confirm('¿Eliminar este producto del inventario?')) return;
    const data = await api('api/productos.php?id=' + id, 'DELETE');
    if (data.ok) {
        btn.closest('.producto-card').remove();
        toast('Producto eliminado');
    } else {
        toast('No se pudo eliminar');
    }
}

// ---------- Modal nuevo producto ----------
function abrirModalProducto() {
    document.getElementById('modalProducto').classList.add('open');
}
function cerrarModalProducto() {
    document.getElementById('modalProducto').classList.remove('open');
    document.getElementById('prodNombre').value = '';
    document.getElementById('prodCodigo').value = '';
    document.getElementById('prodMarca').value = '';
    document.getElementById('prodCampana').innerHTML = '<option value="">— Elige una marca primero —</option>';
    document.getElementById('prodPrecio').value = '';
    document.getElementById('prodStock').value = '';
}

async function cargarCampanasDeMarca() {
    const marcaId = document.getElementById('prodMarca').value;
    const sel = document.getElementById('prodCampana');
    sel.innerHTML = '<option value="">Cargando...</option>';
    if (!marcaId) { sel.innerHTML = '<option value="">— Elige una marca primero —</option>'; return; }

    const data = await api('api/campanas.php?marca_id=' + marcaId);
    if (data.ok && data.campanas.length) {
        sel.innerHTML = '<option value="">Sin campaña específica</option>' +
            data.campanas.map(c => `<option value="${c.id}">Campaña ${c.numero} / ${c.anio}</option>`).join('');
    } else {
        sel.innerHTML = '<option value="">Esta marca no tiene campañas aún</option>';
    }
}

async function crearProducto() {
    const nombre = document.getElementById('prodNombre').value.trim();
    const codigo = document.getElementById('prodCodigo').value.trim();
    const marcaId = document.getElementById('prodMarca').value;
    const campanaId = document.getElementById('prodCampana').value;
    const precio = document.getElementById('prodPrecio').value || 0;
    const stock = document.getElementById('prodStock').value || 0;

    if (!nombre) { toast('Falta el nombre'); return; }
    if (!marcaId) { toast('Selecciona una marca'); return; }

    const r = await api('api/productos.php', 'POST', {
        nombre, codigo, marca_id: marcaId, campana_id: campanaId || null,
        precio_sugerido: precio, stock
    });

    if (r.ok) {
        toast('Producto agregado');
        location.reload();
    } else {
        toast(r.error || 'No se pudo guardar');
    }
}

// ---------- Exportar a Telegram ----------
async function enviarTelegramInventario() {
    toast('Enviando a Telegram...');
    const params = new URLSearchParams(window.location.search);
    const marcaId = params.get('marca_id') || '';
    const r = await api('export_telegram_inventario.php' + (marcaId ? '?marca_id=' + marcaId : ''), 'POST');
    if (r.ok) {
        toast('Inventario enviado a Telegram ✓');
    } else {
        toast(r.error || 'No se pudo enviar');
    }
}
