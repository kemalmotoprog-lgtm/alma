<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

$pdo = getDB();

// Trae cada clienta con su total comprado, pagado y saldo, cruzando todas las marcas
$stmt = $pdo->query("
    SELECT cl.id, cl.nombre, cl.alias,
           COUNT(e.id) AS pedidos,
           COALESCE(SUM(e.precio), 0) AS total_comprado,
           COALESCE(SUM((SELECT COALESCE(SUM(monto),0) FROM pagos WHERE encargo_id = e.id)), 0) AS total_pagado
    FROM clientas cl
    LEFT JOIN encargos e ON e.clienta_id = cl.id
    GROUP BY cl.id
    HAVING pedidos > 0
");
$clientas = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($clientas as &$c) {
    $c['saldo'] = round($c['total_comprado'] - $c['total_pagado'], 2);
}
unset($c);

// Ordena: primero quien más debe, al final quienes están al corriente
usort($clientas, fn($a, $b) => $b['saldo'] <=> $a['saldo']);

$totalDeuda = array_sum(array_map(fn($c) => max($c['saldo'], 0), $clientas));
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title>Clientas · Catálogo</title>
<link rel="stylesheet" href="assets/css/style.css">
<style>
:root{ --accent: #4fd1c5; }
.buscador { padding: 4px 16px 14px; }
.buscador input {
    width: 100%; background: var(--card); border: 1px solid var(--border); color: var(--text);
    border-radius: 12px; padding: 12px 14px; font-size: 14.5px; font-family: var(--font-body);
}
.clienta-row {
    display:flex; align-items:center; gap:12px; background: var(--card); border:1px solid var(--border);
    border-radius: var(--radius); padding: 13px 14px; margin-bottom: 9px;
}
.clienta-row .avatar { width:38px; height:38px; border-radius:50%; background:var(--border); display:flex; align-items:center; justify-content:center; font-family:var(--font-display); font-weight:600; color:var(--text-dim); flex-shrink:0; }
.clienta-row .info { flex:1; min-width:0; }
.clienta-row .nombre { font-family: var(--font-display); font-weight:600; font-size:14.5px; }
.clienta-row .alias { color:var(--text-faint); font-size:12px; }
.clienta-row .cifras { text-align:right; }
.clienta-row .saldo-val { font-family: var(--font-mono); font-weight:600; font-size:14px; }
.clienta-row .saldo-val.pendiente { color: var(--amber); }
.clienta-row .saldo-val.liquidado { color: var(--green); }
.clienta-row .pedidos-count { font-size:11px; color:var(--text-faint); margin-top:2px; }
</style>
</head>
<body>

<div class="topbar">
    <a class="back" href="index.php">←</a>
    <h1>Clientas</h1>
    <span class="tag" style="color:#4fd1c5"><?= count($clientas) ?></span>
</div>

<div class="stat-grid" style="grid-template-columns: 1fr 1fr; padding-top:14px;">
    <div class="stat-card">
        <div class="lbl">Clientas activas</div>
        <div class="val"><?= count($clientas) ?></div>
    </div>
    <div class="stat-card">
        <div class="lbl">Deuda total</div>
        <div class="val" style="color:var(--amber)"><?= money($totalDeuda) ?></div>
    </div>
</div>

<div class="buscador">
    <input type="text" id="buscador" placeholder="Buscar clienta por nombre o alias..." oninput="filtrar(this.value)">
</div>

<div class="container" id="listado" style="padding-top:0;">
    <?php if (empty($clientas)): ?>
        <div class="empty-state">Todavía no hay clientas con encargos registrados.</div>
    <?php else: foreach ($clientas as $c):
        $liquidado = $c['saldo'] <= 0.004;
    ?>
    <a class="clienta-row" href="clienta.php?id=<?= $c['id'] ?>"
       data-nombre="<?= htmlspecialchars(safe_mb_strtolower($c['nombre'] . ' ' . $c['alias'])) ?>">
        <div class="avatar"><?= htmlspecialchars(safe_mb_strtoupper(safe_mb_substr($c['nombre'],0,1))) ?></div>
        <div class="info">
            <div class="nombre"><?= htmlspecialchars($c['nombre']) ?></div>
            <?php if (!empty($c['alias'])): ?><div class="alias">"<?= htmlspecialchars($c['alias']) ?>"</div><?php endif; ?>
        </div>
        <div class="cifras">
            <div class="saldo-val <?= $liquidado ? 'liquidado' : 'pendiente' ?>">
                <?= $liquidado ? '✓ Al corriente' : money($c['saldo']) ?>
            </div>
            <div class="pedidos-count"><?= $c['pedidos'] ?> pedido<?= $c['pedidos']==1?'':'s' ?></div>
        </div>
    </a>
    <?php endforeach; endif; ?>
</div>

<script>
function filtrar(q) {
    q = q.toLowerCase().trim();
    document.querySelectorAll('.clienta-row').forEach(row => {
        row.style.display = row.dataset.nombre.includes(q) ? 'flex' : 'none';
    });
}
</script>
</body>
</html>
