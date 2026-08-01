<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

$pdo = getDB();
$marcas = $pdo->query("SELECT * FROM marcas ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

$hoy = date('Y-m-d');
$fechaInicio = trim($_GET['fecha_inicio'] ?? '') ?: $hoy;
$fechaFin = trim($_GET['fecha_fin'] ?? '') ?: $hoy;
if ($fechaFin < $fechaInicio) [$fechaInicio, $fechaFin] = [$fechaFin, $fechaInicio];

// Si no viene el parámetro marcas[] en la URL (primera visita), se seleccionan todas por defecto.
$marcasSel = isset($_GET['marcas']) ? array_map('intval', (array)$_GET['marcas']) : array_column($marcas, 'id');

$reporte = generarReporte($pdo, $fechaInicio, $fechaFin, $marcasSel);

$queryExport = http_build_query(['fecha_inicio' => $fechaInicio, 'fecha_fin' => $fechaFin]) .
    '&' . implode('&', array_map(fn($id) => 'marcas[]=' . $id, $marcasSel));
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title>Reportes · Catálogo</title>
<link rel="stylesheet" href="assets/css/style.css">
<style>
:root{ --accent: #6ea8ff; }
.filtro-card { background: var(--card); border:1px solid var(--border); border-radius: var(--radius); padding:16px; margin: 14px 16px; }
.filtro-fechas { display:flex; gap:10px; }
.filtro-fechas .form-row { flex:1; margin-bottom:0; }
.marca-checks { display:flex; flex-wrap:wrap; gap:8px; margin-top:12px; }
.check-pill { position:relative; }
.check-pill input { position:absolute; opacity:0; width:100%; height:100%; margin:0; cursor:pointer; }
.check-pill span {
    display:inline-flex; align-items:center; gap:6px;
    font-family: var(--font-mono); font-size:12.5px; padding:8px 13px; border-radius:20px;
    border:1px solid var(--border); color: var(--text-dim);
}
.check-pill input:checked + span { color:#0d0e12; font-weight:600; border-color:transparent; background: var(--p-color); }
.rango-atajos { display:flex; gap:8px; margin-top:12px; flex-wrap:wrap; }
.rango-atajos button {
    font-family: var(--font-mono); font-size:11.5px; color:var(--text-dim); background:var(--bg-elevated);
    border:1px solid var(--border); border-radius:20px; padding:6px 11px; cursor:pointer;
}
.desglose-row { display:flex; justify-content:space-between; align-items:center; padding:10px 0; border-bottom:1px solid var(--border); }
.desglose-row:last-child { border-bottom:none; }
.desglose-row .izq { display:flex; align-items:center; gap:9px; font-size:13.5px; }
.desglose-row .dot { width:9px; height:9px; border-radius:50%; background: var(--d-color); flex-shrink:0; }
.desglose-row .der { text-align:right; font-family:var(--font-mono); font-size:13.5px; }
.desglose-row .der .sub { color:var(--text-faint); font-size:11px; }
</style>
</head>
<body>

<div class="topbar">
    <a class="back" href="index.php">←</a>
    <h1>Reportes</h1>
    <span class="tag" style="color:#6ea8ff"><?= $fechaInicio === $fechaFin ? $fechaInicio : "$fechaInicio → $fechaFin" ?></span>
</div>

<form class="filtro-card" method="GET" id="filtroForm">
    <div class="filtro-fechas">
        <div class="form-row">
            <label>Desde</label>
            <input type="date" name="fecha_inicio" value="<?= htmlspecialchars($fechaInicio) ?>">
        </div>
        <div class="form-row">
            <label>Hasta</label>
            <input type="date" name="fecha_fin" value="<?= htmlspecialchars($fechaFin) ?>">
        </div>
    </div>

    <div class="rango-atajos">
        <button type="button" onclick="fijarRango(0,0)">Hoy</button>
        <button type="button" onclick="fijarRango(1,1)">Ayer</button>
        <button type="button" onclick="fijarRangoSemana()">Esta semana</button>
        <button type="button" onclick="fijarRangoMes()">Este mes</button>
    </div>

    <div class="marca-checks">
        <label class="check-pill">
            <input type="checkbox" id="checkTodas" onchange="toggleTodas(this)" <?= count($marcasSel) === count($marcas) ? 'checked' : '' ?>>
            <span style="--p-color:#6ea8ff">Todas</span>
        </label>
        <?php foreach ($marcas as $m): ?>
        <label class="check-pill">
            <input type="checkbox" name="marcas[]" value="<?= $m['id'] ?>" class="check-marca"
                   <?= in_array((int)$m['id'], $marcasSel) ? 'checked' : '' ?>>
            <span style="--p-color:<?= $m['color'] ?>"><?= htmlspecialchars($m['nombre']) ?></span>
        </label>
        <?php endforeach; ?>
    </div>

    <button type="submit" class="btn-primary" style="background:#6ea8ff; margin-top:14px;">Generar reporte</button>
</form>

<div class="stat-grid">
    <div class="stat-card">
        <div class="lbl">Cobrado</div>
        <div class="val" style="color:var(--green)"><?= money($reporte['totalCobrado']) ?></div>
    </div>
    <div class="stat-card">
        <div class="lbl">Vendido</div>
        <div class="val"><?= money($reporte['totalVendido']) ?></div>
    </div>
    <div class="stat-card">
        <div class="lbl">Quedó a deber</div>
        <div class="val" style="color:var(--amber)"><?= money($reporte['totalPendienteGenerado']) ?></div>
    </div>
</div>

<div class="container" style="padding-top:0;">
    <div class="section-title"><span>Cobrado por marca</span></div>
    <div class="chart-card" style="margin:0 0 16px;">
        <?php if (empty($reporte['porMarca'])): ?>
            <div class="empty-state" style="padding:16px;">Sin movimientos en este rango.</div>
        <?php else: foreach ($reporte['porMarca'] as $pm): ?>
            <div class="desglose-row">
                <div class="izq"><span class="dot" style="--d-color:<?= $pm['color'] ?>"></span><?= htmlspecialchars($pm['nombre']) ?></div>
                <div class="der"><?= money($pm['cobrado']) ?><div class="sub"><?= $pm['pedidos'] ?> pedido<?= $pm['pedidos']==1?'':'s' ?> · vendido <?= money($pm['vendido']) ?></div></div>
            </div>
        <?php endforeach; endif; ?>
    </div>

    <?php if ($fechaInicio !== $fechaFin && !empty($reporte['porDia'])): ?>
    <div class="section-title"><span>Cobrado por día</span></div>
    <div class="chart-card" style="margin:0 0 16px;">
        <?php foreach ($reporte['porDia'] as $dia => $monto): ?>
            <div class="desglose-row">
                <div class="izq"><?= date('d/m/Y', strtotime($dia)) ?></div>
                <div class="der"><?= money($monto) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($reporte['pagos'])): ?>
    <div class="section-title"><span>Todos los cobros (<?= count($reporte['pagos']) ?>)</span></div>
    <div class="chart-card" style="margin:0 0 16px;">
        <?php foreach ($reporte['pagos'] as $pg): ?>
            <div class="desglose-row">
                <div class="izq">
                    <span class="dot" style="--d-color:<?= $pg['marca_color'] ?>"></span>
                    <?= htmlspecialchars($pg['clienta_nombre']) ?>
                    <span style="color:var(--text-faint); font-size:11.5px;">· <?= date('d/m', strtotime($pg['fecha'])) ?></span>
                </div>
                <div class="der"><?= money($pg['monto']) ?></div>
            </div>
        <?php endforeach; ?>
        <div class="desglose-row" style="border-top:2px solid var(--border); margin-top:4px; padding-top:12px;">
            <div class="izq" style="font-weight:600;">Total</div>
            <div class="der" style="font-weight:600; color:var(--green);"><?= money($reporte['totalCobrado']) ?></div>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="bottom-bar">
    <a class="btn-secondary" href="export_pdf_reporte.php?<?= $queryExport ?>" target="_blank">↓ PDF</a>
    <button class="btn-primary" style="background:#6ea8ff" onclick="enviarTelegramReporte()">↗ Telegram</button>
</div>

<div id="toast"></div>
<script src="assets/js/app.js"></script>
<script>
function toggleTodas(checkbox) {
    document.querySelectorAll('.check-marca').forEach(c => c.checked = checkbox.checked);
}
document.querySelectorAll('.check-marca').forEach(c => c.addEventListener('change', () => {
    const todas = document.querySelectorAll('.check-marca').length === document.querySelectorAll('.check-marca:checked').length;
    document.getElementById('checkTodas').checked = todas;
}));

function setFechas(ini, fin) {
    document.querySelector('input[name=fecha_inicio]').value = ini;
    document.querySelector('input[name=fecha_fin]').value = fin;
}
function fmt(d) { return d.toISOString().slice(0,10); }

function fijarRango(diasIni, diasFin) {
    const hoy = new Date();
    const ini = new Date(hoy); ini.setDate(hoy.getDate() - diasIni);
    const fin = new Date(hoy); fin.setDate(hoy.getDate() - diasFin);
    setFechas(fmt(ini), fmt(fin));
}
function fijarRangoSemana() {
    const hoy = new Date();
    const diaSemana = (hoy.getDay() + 6) % 7; // lunes = 0
    const ini = new Date(hoy); ini.setDate(hoy.getDate() - diaSemana);
    setFechas(fmt(ini), fmt(hoy));
}
function fijarRangoMes() {
    const hoy = new Date();
    const ini = new Date(hoy.getFullYear(), hoy.getMonth(), 1);
    setFechas(fmt(ini), fmt(hoy));
}

async function enviarTelegramReporte() {
    toast('Enviando a Telegram...');
    const params = new URLSearchParams(window.location.search);
    const r = await api('export_telegram_reporte.php?' + params.toString(), 'POST');
    if (r.ok) {
        toast('Reporte enviado a Telegram ✓');
    } else {
        toast(r.error || 'No se pudo enviar');
    }
}
</script>
</body>
</html>
