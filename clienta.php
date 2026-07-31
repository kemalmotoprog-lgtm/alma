<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

$pdo = getDB();
$clientaId = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM clientas WHERE id = ?");
$stmt->execute([$clientaId]);
$clienta = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$clienta) { http_response_code(404); die('Clienta no encontrada'); }

$stmt = $pdo->prepare("
    SELECT e.*, c.numero AS campana_numero, c.anio AS campana_anio,
           m.nombre AS marca_nombre, m.color AS marca_color
    FROM encargos e
    JOIN campanas c ON c.id = e.campana_id
    JOIN marcas m ON m.id = c.marca_id
    WHERE e.clienta_id = ?
    ORDER BY e.fecha DESC, e.creado_en DESC
");
$stmt->execute([$clientaId]);
$encargos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pagosPorEncargo = [];
if (!empty($encargos)) {
    $ids = array_column($encargos, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM pagos WHERE encargo_id IN ($in) ORDER BY fecha ASC, id ASC");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $pagosPorEncargo[$p['encargo_id']][] = $p;
    }
}

function calc($encargo, $pagos) {
    $pagado = array_sum(array_column($pagos, 'monto'));
    $saldo = round($encargo['precio'] - $pagado, 2);
    return [$pagado, $saldo, $saldo <= 0.004];
}

$totalComprado = array_sum(array_column($encargos, 'precio'));
$totalPagado = 0;
foreach ($encargos as $e) $totalPagado += array_sum(array_column($pagosPorEncargo[$e['id']] ?? [], 'monto'));
$saldoTotal = round($totalComprado - $totalPagado, 2);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title><?= htmlspecialchars($clienta['nombre']) ?> · Catálogo</title>
<link rel="stylesheet" href="assets/css/style.css">
<style>
:root{ --accent: #4fd1c5; }
.marca-tag { font-family: var(--font-mono); font-size: 10.5px; color: var(--m-color); white-space: nowrap; }
.encargo-card { position:relative; }
.encargo-card::before { content:""; position:absolute; left:0; top:0; bottom:0; width:4px; background: var(--m-color, var(--text-faint)); border-radius: var(--radius) 0 0 var(--radius); }
</style>
</head>
<body>

<div class="topbar">
    <a class="back" href="clientas.php">←</a>
    <h1><?= htmlspecialchars($clienta['nombre']) ?></h1>
    <?php if (!empty($clienta['alias'])): ?><span class="tag">"<?= htmlspecialchars($clienta['alias']) ?>"</span><?php endif; ?>
</div>

<div class="stat-grid">
    <div class="stat-card">
        <div class="lbl">Comprado</div>
        <div class="val"><?= money($totalComprado) ?></div>
    </div>
    <div class="stat-card">
        <div class="lbl">Pagado</div>
        <div class="val" style="color:var(--green)"><?= money($totalPagado) ?></div>
    </div>
    <div class="stat-card">
        <div class="lbl">Saldo</div>
        <div class="val" style="color:<?= $saldoTotal > 0.004 ? 'var(--amber)' : 'var(--green)' ?>">
            <?= $saldoTotal > 0.004 ? money($saldoTotal) : '✓ $0.00' ?>
        </div>
    </div>
</div>

<div class="container" style="padding-top:0;">
    <?php if (empty($encargos)): ?>
        <div class="empty-state">Esta clienta todavía no tiene encargos.</div>
    <?php else: foreach ($encargos as $enc):
        $pagos = $pagosPorEncargo[$enc['id']] ?? [];
        [$pagado, $saldo, $liquidado] = calc($enc, $pagos);
    ?>
    <div class="encargo-card" style="--m-color: <?= $enc['marca_color'] ?>" data-encargo-id="<?= $enc['id'] ?>" data-precio="<?= $enc['precio'] ?>">
        <div class="encargo-top">
            <div class="encargo-desc">
                <input type="text" value="<?= htmlspecialchars($enc['descripcion']) ?>" placeholder="Descripción del pedido"
                       onchange="actualizarEncargo(<?= $enc['id'] ?>, {descripcion: this.value})">
            </div>
            <div class="estado-pill <?= $enc['estado'] ?>" onclick="toggleEstado(<?= $enc['id'] ?>, this)">
                <?= $enc['estado'] === 'entregado' ? 'Entregado' : 'Por entregar' ?>
            </div>
        </div>

        <div class="marca-tag" style="--m-color:<?= $enc['marca_color'] ?>">
            <?= htmlspecialchars($enc['marca_nombre']) ?> · C<?= $enc['campana_numero'] ?>/<?= $enc['campana_anio'] ?>
        </div>

        <div class="encargo-meta-row">
            <div class="field">
                <span class="lbl">Precio</span>
                <input type="number" step="0.01" value="<?= $enc['precio'] ?>"
                       onchange="actualizarEncargo(<?= $enc['id'] ?>, {precio: this.value})">
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
    <?php endforeach; endif; ?>
</div>

<div class="bottom-bar">
    <a class="btn-secondary" href="export_pdf_clienta.php?id=<?= $clientaId ?>" target="_blank">↓ PDF</a>
    <button class="btn-primary" style="background:#4fd1c5" onclick="enviarTelegramClienta()">↗ Enviar estado de cuenta</button>
</div>

<div id="toast"></div>
<script src="assets/js/app.js"></script>
<script>
async function enviarTelegramClienta() {
    toast('Enviando a Telegram...');
    const r = await api('export_telegram_clienta.php?id=<?= $clientaId ?>', 'POST');
    if (r.ok) {
        toast('Estado de cuenta enviado ✓');
    } else {
        toast(r.error || 'No se pudo enviar');
    }
}
</script>
</body>
</html>
