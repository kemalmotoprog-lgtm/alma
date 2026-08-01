<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

$pdo = getDB();
$marcas = $pdo->query("SELECT * FROM marcas ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

// Calcula totales por marca (pedidos, por cobrar, ganado)
$stats = [];
$globalTotal = ['pedidos' => 0, 'porCobrar' => 0, 'ganado' => 0];

foreach ($marcas as $m) {
    $stmt = $pdo->prepare("
        SELECT e.id, e.precio, COALESCE((SELECT SUM(monto) FROM pagos WHERE encargo_id = e.id), 0) AS pagado
        FROM encargos e
        JOIN campanas c ON c.id = e.campana_id
        WHERE c.marca_id = ?
    ");
    $stmt->execute([$m['id']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $pedidos = count($rows);
    $porCobrar = 0; $ganado = 0;
    foreach ($rows as $r) {
        $saldo = $r['precio'] - $r['pagado'];
        if ($saldo > 0.004) $porCobrar += $saldo;
        $ganado += $r['pagado'];
    }
    $stats[$m['id']] = ['pedidos' => $pedidos, 'porCobrar' => $porCobrar, 'ganado' => $ganado];
    $globalTotal['pedidos'] += $pedidos;
    $globalTotal['porCobrar'] += $porCobrar;
    $globalTotal['ganado'] += $ganado;
}

$inv = $pdo->query("SELECT COUNT(*) AS n, COALESCE(SUM(CASE WHEN stock > 0 THEN stock ELSE 0 END),0) AS stock FROM productos WHERE activo = 1")->fetch(PDO::FETCH_ASSOC);

$clientasStmt = $pdo->query("
    SELECT cl.id,
           COALESCE(SUM(e.precio), 0) AS comprado,
           COALESCE(SUM((SELECT COALESCE(SUM(monto),0) FROM pagos WHERE encargo_id = e.id)), 0) AS pagado
    FROM clientas cl
    JOIN encargos e ON e.clienta_id = cl.id
    GROUP BY cl.id
");
$clientasRows = $clientasStmt->fetchAll(PDO::FETCH_ASSOC);
$deudaTotal = 0; $clientasConDeuda = 0;
foreach ($clientasRows as $c) {
    $saldo = $c['comprado'] - $c['pagado'];
    if ($saldo > 0.004) { $deudaTotal += $saldo; $clientasConDeuda++; }
}
$cobradoHoy = (float)$pdo->query("SELECT COALESCE(SUM(monto),0) FROM pagos WHERE fecha = date('now')")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<link rel="manifest" href="manifest.json">
<link rel="icon" href="/favicon.png" type="image/png">

<meta name="theme-color" content="#121212">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title>Promotora · <?= htmlspecialchars(NOMBRE_DUENA) ?></title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<div class="greeting" style="display:flex; justify-content:space-between; align-items:flex-start;">
    <div>
        <div class="hola">Bienvenida Promotora de Belleza</div>
        <h1>Hola, <?= htmlspecialchars(NOMBRE_DUENA) ?></h1>
    </div>
    <a href="ajustes.php" style="color:var(--text-faint); font-size:20px; padding:6px; margin-top:2px;">⚙</a>
</div>

<?php
// Para volver a mostrar el widget "Global" en el inicio, cambia esto a true.
$mostrarWidgetGlobal = false;
?>

<div class="widget-grid">
    <?php if ($mostrarWidgetGlobal): ?>
    <a class="widget widget-global" style="--accent: var(--c-global)" href="global.php">
        <div class="badge" style="--accent: var(--c-global)">∑</div>
        <div class="brand-name">Global</div>
        <div class="brand-sub">Todas las marcas</div>
        <div class="brand-figure">
            <span class="lbl">Ganado total</span>
            <span class="mono"><?= money($globalTotal['ganado']) ?></span>
        </div>
    </a>
    <?php endif; ?>

    <?php foreach ($marcas as $m):
        $s = $stats[$m['id']];
        $inicial = safe_mb_substr($m['nombre'], 0, 1);
    ?>
    <a class="widget" style="--accent: <?= $m['color'] ?>" href="marca.php?slug=<?= urlencode($m['slug']) ?>">
        <div class="badge" style="--accent: <?= $m['color'] ?>"><?= htmlspecialchars($inicial) ?></div>
        <div class="brand-name"><?= htmlspecialchars($m['nombre']) ?></div>
        <div class="brand-sub"><?= $s['pedidos'] ?> pedido<?= $s['pedidos'] == 1 ? '' : 's' ?></div>
        <div class="brand-figure">
            <span class="lbl">Por cobrar</span>
            <span class="mono"><?= money($s['porCobrar']) ?></span>
        </div>
    </a>
    <?php endforeach; ?>

    <a class="widget widget-global" style="--accent:#e0a83e" href="inventario.php">
        <div class="badge" style="--accent:#e0a83e">📦</div>
        <div class="brand-name">Inventario</div>
        <div class="brand-sub"><?= $inv['n'] ?> producto<?= $inv['n'] == 1 ? '' : 's' ?></div>
        <div class="brand-figure">
            <span class="lbl">Piezas en stock</span>
            <span class="mono"><?= $inv['stock'] ?></span>
        </div>
    </a>

    <a class="widget widget-global" style="--accent:#4fd1c5" href="clientas.php">
        <div class="badge" style="--accent:#4fd1c5">👤</div>
        <div class="brand-name">Clientas</div>
        <div class="brand-sub"><?= $clientasConDeuda ?> con saldo pendiente</div>
        <div class="brand-figure">
            <span class="lbl">Deuda total</span>
            <span class="mono"><?= money($deudaTotal) ?></span>
        </div>
    </a>

    <a class="widget widget-global" style="--accent:#6ea8ff" href="reportes.php">
        <div class="badge" style="--accent:#6ea8ff">📊</div>
        <div class="brand-name">Reportes</div>
        <div class="brand-sub">Cobranza por fecha y marca</div>
        <div class="brand-figure">
            <span class="lbl">Cobrado hoy</span>
            <span class="mono"><?= money($cobradoHoy) ?></span>
        </div>
    </a>
</div>

<div style="padding: 0 16px 30px; color: var(--text-faint); font-size: 12px; text-align:center;">
    Toca una marca para ver sus campañas
</div>

</body>
</html>
