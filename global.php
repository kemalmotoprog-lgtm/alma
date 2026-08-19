<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

$pdo = getDB();
$marcas = $pdo->query("SELECT * FROM marcas ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

$totalPedidos = 0; $totalPorCobrar = 0; $totalGanado = 0;
$porMarca = []; // para gráfica de dona: ganado por marca

foreach ($marcas as $m) {
    $stmt = $pdo->prepare("
        SELECT e.precio, COALESCE((SELECT SUM(monto) FROM pagos WHERE encargo_id = e.id),0) AS pagado
        FROM encargos e JOIN campanas c ON c.id = e.campana_id
        WHERE c.marca_id = ?
    ");
    $stmt->execute([$m['id']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $ganado = array_sum(array_column($rows, 'pagado'));
    $porCobrar = 0;
    foreach ($rows as $r) {
        $s = $r['precio'] - $r['pagado'];
        if ($s > 0.004) $porCobrar += $s;
    }
    $totalPedidos += count($rows);
    $totalPorCobrar += $porCobrar;
    $totalGanado += $ganado;
    $porMarca[] = ['nombre' => $m['nombre'], 'color' => $m['color'], 'ganado' => round($ganado, 2), 'porCobrar' => round($porCobrar, 2)];
}

// Ganado por campaña (todas las marcas), para gráfica de barras -- agrupado por numero de campaña
$stmt = $pdo->query("
    SELECT c.numero, c.anio, m.nombre AS marca, m.color,
           COALESCE(SUM(pg.monto), 0) AS ganado
    FROM campanas c
    JOIN marcas m ON m.id = c.marca_id
    LEFT JOIN encargos e ON e.campana_id = c.id
    LEFT JOIN pagos pg ON pg.encargo_id = e.id
    GROUP BY c.id
    ORDER BY c.anio ASC, c.numero ASC
");
$campData = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Agrupar por número de campaña para el eje X, con series por marca
$labels = [];
$series = []; // marca => [numero => ganado]
foreach ($campData as $row) {
    $lbl = 'C' . $row['numero'] . '/' . substr($row['anio'], -2);
    if (!in_array($lbl, $labels)) $labels[] = $lbl;
    $series[$row['marca']]['color'] = $row['color'];
    $series[$row['marca']]['data'][$lbl] = ($series[$row['marca']]['data'][$lbl] ?? 0) + (float)$row['ganado'];
}
sort($labels);

$datasets = [];
foreach ($series as $marcaNombre => $info) {
    $data = [];
    foreach ($labels as $lbl) $data[] = round($info['data'][$lbl] ?? 0, 2);
    $datasets[] = ['label' => $marcaNombre, 'color' => $info['color'], 'data' => $data];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title>Global · Catálogo</title>
<link rel="stylesheet" href="assets/css/style.css">
<style>:root{ --accent: var(--c-global); }</style>
</head>
<body>

<div class="topbar">
    <a class="back" href="index.php">←</a>
    <h1>Resumen global</h1>
    <span class="tag" style="color:var(--c-global)">todas las marcas</span>
</div>

<div class="stat-grid">
    <div class="stat-card">
        <div class="lbl">Pedidos</div>
        <div class="val"><?= $totalPedidos ?></div>
    </div>
    <div class="stat-card">
        <div class="lbl">Por cobrar</div>
        <div class="val" style="color:var(--amber)"><?= money($totalPorCobrar) ?></div>
    </div>
    <div class="stat-card">
        <div class="lbl">Ganado</div>
        <div class="val" style="color:var(--green)"><?= money($totalGanado) ?></div>
    </div>
</div>

<div class="chart-card">
    <h4>Ganado por marca</h4>
    <canvas id="chartDona" height="220"></canvas>
</div>

<div class="chart-card">
    <h4>Ganado por campaña</h4>
    <canvas id="chartBarras" height="240"></canvas>
</div>

<div class="container" style="padding-top:0;">
    <div class="section-title"><span>Detalle por marca</span></div>
    <?php foreach ($porMarca as $pm): ?>
    <div class="widget" style="--accent: <?= $pm['color'] ?>; margin-bottom:10px;">
        <div class="brand-name"><?= htmlspecialchars($pm['nombre']) ?></div>
        <div class="encargo-totales" style="border:none; padding-top:8px; margin-top:8px;">
            <span class="mono">Ganado: <?= money($pm['ganado']) ?></span>
            <span class="mono saldo <?= $pm['porCobrar'] <= 0.004 ? 'liquidado' : 'pendiente' ?>">
                <?= $pm['porCobrar'] <= 0.004 ? '✓ Al corriente' : 'Por cobrar: ' . money($pm['porCobrar']) ?>
            </span>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<script src="assets/js/vendor/chart.umd.min.js"></script>
<script>
Chart.defaults.color = '#9a9dab';
Chart.defaults.font.family = "'Inter', sans-serif";
Chart.defaults.borderColor = '#2a2e3a';

const porMarca = <?= json_encode($porMarca, JSON_UNESCAPED_UNICODE) ?>;
new Chart(document.getElementById('chartDona'), {
    type: 'doughnut',
    data: {
        labels: porMarca.map(m => m.nombre),
        datasets: [{
            data: porMarca.map(m => m.ganado),
            backgroundColor: porMarca.map(m => m.color),
            borderColor: '#171a21',
            borderWidth: 2
        }]
    },
    options: {
        plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, padding: 14 } } }
    }
});

const labels = <?= json_encode($labels) ?>;
const datasets = <?= json_encode($datasets, JSON_UNESCAPED_UNICODE) ?>;
new Chart(document.getElementById('chartBarras'), {
    type: 'bar',
    data: {
        labels: labels,
        datasets: datasets.map(d => ({
            label: d.label,
            data: d.data,
            backgroundColor: d.color,
            borderRadius: 4,
            maxBarThickness: 22
        }))
    },
    options: {
        scales: {
            x: { grid: { display: false } },
            y: { grid: { color: '#2a2e3a' }, beginAtZero: true }
        },
        plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, padding: 14 } } }
    }
});
</script>
</body>
</html>
