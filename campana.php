<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

$pdo = getDB();
$campanaId = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT c.*, m.nombre AS marca_nombre, m.color AS marca_color, m.slug AS marca_slug
                        FROM campanas c JOIN marcas m ON m.id = c.marca_id WHERE c.id = ?");
$stmt->execute([$campanaId]);
$campana = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$campana) { http_response_code(404); die('Campaña no encontrada'); }

// Encargos de esta campaña, agrupados por clienta
$stmt = $pdo->prepare("SELECT e.*, cl.nombre AS clienta_nombre, cl.alias AS clienta_alias
                        FROM encargos e JOIN clientas cl ON cl.id = e.clienta_id
                        WHERE e.campana_id = ?
                        ORDER BY cl.nombre ASC, e.creado_en ASC");
$stmt->execute([$campanaId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$porClienta = [];
foreach ($rows as $r) {
    $porClienta[$r['clienta_id']]['nombre'] = $r['clienta_nombre'];
    $porClienta[$r['clienta_id']]['alias'] = $r['clienta_alias'];
    $porClienta[$r['clienta_id']]['encargos'][] = $r;
}

// pagos por encargo
$pagosPorEncargo = [];
if (!empty($rows)) {
    $ids = array_column($rows, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM pagos WHERE encargo_id IN ($in) ORDER BY fecha ASC, id ASC");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $pagosPorEncargo[$p['encargo_id']][] = $p;
    }
}

$productos = $pdo->prepare("SELECT * FROM productos WHERE marca_id = ? AND activo = 1 ORDER BY nombre");
$productos->execute([$campana['marca_id']]);
$productos = $productos->fetchAll(PDO::FETCH_ASSOC);

function calc($encargo, $pagos) {
    $pagado = array_sum(array_column($pagos, 'monto'));
    $saldo = round($encargo['precio'] - $pagado, 2);
    return [$pagado, $saldo, $saldo <= 0.004];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title><?= nombreCampana($campana) ?> · <?= htmlspecialchars($campana['marca_nombre']) ?></title>
<link rel="stylesheet" href="assets/css/style.css">
<style>:root{ --accent: <?= $campana['marca_color'] ?>; }</style>
</head>
<body>

<div class="topbar">
    <a class="back" href="marca.php?slug=<?= urlencode($campana['marca_slug']) ?>">←</a>
    <h1><?= htmlspecialchars($campana['marca_nombre']) ?> · C<?= $campana['numero'] ?></h1>
    <span class="tag"><?= $campana['anio'] ?></span>
</div>

<div class="container" id="listado" style="padding-top:10px;">
    <?php if (empty($porClienta)): ?>
        <div class="empty-state">Todavía no hay encargos en esta campaña.<br>Agrega el primero abajo.</div>
    <?php else: foreach ($porClienta as $clientaId => $data): ?>
        <div class="clienta-block">
            <div class="clienta-header">
                <div class="avatar"><?= htmlspecialchars(safe_mb_strtoupper(safe_mb_substr($data['nombre'],0,1))) ?></div>
                <div>
                    <div class="nombre"><?= htmlspecialchars($data['nombre']) ?></div>
                    <?php if (!empty($data['alias'])): ?><div class="alias">"<?= htmlspecialchars($data['alias']) ?>"</div><?php endif; ?>
                </div>
            </div>

            <?php foreach ($data['encargos'] as $enc):
                $pagos = $pagosPorEncargo[$enc['id']] ?? [];
                [$pagado, $saldo, $liquidado] = calc($enc, $pagos);
            ?>
            <div class="encargo-card" data-encargo-id="<?= $enc['id'] ?>" data-precio="<?= $enc['precio'] ?>">
                <div class="encargo-top">
                    <div class="encargo-desc">
                        <input type="text" value="<?= htmlspecialchars($enc['descripcion']) ?>" placeholder="Descripción del pedido"
                               onchange="actualizarEncargo(<?= $enc['id'] ?>, {descripcion: this.value})">
                    </div>
                    <div class="estado-pill <?= $enc['estado'] ?>" onclick="toggleEstado(<?= $enc['id'] ?>, this)">
                        <?= $enc['estado'] === 'entregado' ? 'Entregado' : 'Por entregar' ?>
                    </div>
                </div>

                <div class="encargo-meta-row">
                    <div class="field">
                        <span class="lbl">Precio</span>
                        <input type="number" step="0.01" value="<?= $enc['precio'] ?>"
                               onchange="actualizarEncargo(<?= $enc['id'] ?>, {precio: this.value}, this)">
                    </div>
                    <div class="field">
                        <span class="lbl">Fecha</span>
                        <input type="date" value="<?= htmlspecialchars($enc['fecha']) ?>"
                               onchange="actualizarEncargo(<?= $enc['id'] ?>, {fecha: this.value})">
                    </div>
                </div>

                <hr class="divider-thin">

                <div class="pagos-list" id="pagos-<?= $enc['id'] ?>">
                    <?php foreach ($pagos as $p): ?>
                    <div class="pago-row" data-pago-id="<?= $p['id'] ?>">
                        <span class="mono">$</span>
                        <input type="number" step="0.01" value="<?= $p['monto'] ?>" onchange="actualizarPago(<?= $p['id'] ?>, this)">
                        <input type="date" value="<?= htmlspecialchars($p['fecha']) ?>" onchange="actualizarPagoFecha(<?= $p['id'] ?>, this)">
                        <button class="icon-btn" onclick="eliminarPago(<?= $p['id'] ?>, this)">✕</button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button class="btn-add-pago" onclick="agregarPago(<?= $enc['id'] ?>)">+ Agregar pago</button>

                <div class="encargo-totales">
                    <span class="mono">Pagado: <?= money($pagado) ?></span>
                    <span class="mono saldo <?= $liquidado ? 'liquidado' : 'pendiente' ?>">
                        <?= $liquidado ? '✓ Liquidado' : 'Saldo: ' . money($saldo) ?>
                    </span>
                </div>

                <div class="encargo-actions">
                    <button class="btn-danger-ghost" onclick="eliminarEncargo(<?= $enc['id'] ?>, this)">Eliminar encargo</button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; endif; ?>
</div>

<div class="bottom-bar">
    <a class="btn-secondary" href="export_pdf.php?campana_id=<?= $campanaId ?>" target="_blank">↓ PDF</a>
    <button class="btn-secondary" onclick="enviarTelegram()">↗ Telegram</button>
    <button class="btn-primary" style="background:<?= $campana['marca_color'] ?>" onclick="abrirModalEncargo()">+ Encargo</button>
</div>

<!-- Modal nuevo encargo -->
<div class="modal-overlay" id="modalEncargo">
    <div class="modal">
        <h3>Nuevo encargo</h3>

        <div class="form-row">
            <label>Clienta</label>
            <input type="text" id="clientaInput" placeholder="Escribe el nombre..." autocomplete="off" oninput="buscarClientas(this.value)">
            <div id="clientaSugerencias" style="display:flex;flex-direction:column;gap:4px;"></div>
            <input type="hidden" id="clientaIdSel" value="">
        </div>
        <div class="form-row" id="aliasRow" style="display:none;">
            <label>Alias (opcional)</label>
            <input type="text" id="clientaAlias" placeholder="Como le dicen...">
        </div>

        <div class="form-row">
            <label>Producto (del inventario <?= htmlspecialchars($campana['marca_nombre']) ?>)</label>
            <select id="productoSel" class="plain" onchange="autocompletarPrecio()">
                <option value="">— Escribir manualmente —</option>
                <?php foreach ($productos as $p): ?>
                    <option value="<?= $p['id'] ?>" data-precio="<?= $p['precio_sugerido'] ?>" data-stock="<?= $p['stock'] ?>">
                        <?= htmlspecialchars($p['nombre']) ?> (<?= money($p['precio_sugerido']) ?><?= $p['stock']>0 ? ', stock '.$p['stock'] : '' ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-row">
            <label>Descripción</label>
            <input type="text" id="descInput" placeholder="Ej. Perfume Kaiak 100ml">
        </div>

        <div class="form-row">
            <label>Precio</label>
            <input type="number" id="precioInput" step="0.01" placeholder="0.00">
        </div>

        <div class="form-row">
            <label>Estado</label>
            <select id="estadoInput" class="plain">
                <option value="por_entregar">Por entregar</option>
                <option value="entregado">Entregado</option>
            </select>
        </div>

        <div class="form-actions">
            <button class="btn-secondary" onclick="cerrarModalEncargo()">Cancelar</button>
            <button class="btn-primary" style="background:<?= $campana['marca_color'] ?>" onclick="crearEncargo()">Guardar</button>
        </div>
    </div>
</div>

<div id="toast"></div>
<script src="assets/js/app.js"></script>
<script>
const CAMPANA_ID = <?= $campanaId ?>;
</script>
</body>
</html>
