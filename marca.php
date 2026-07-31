<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

$pdo = getDB();
$slug = $_GET['slug'] ?? '';
$stmt = $pdo->prepare("SELECT * FROM marcas WHERE slug = ?");
$stmt->execute([$slug]);
$marca = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$marca) { http_response_code(404); die('Marca no encontrada'); }

$stmt = $pdo->prepare("SELECT * FROM campanas WHERE marca_id = ? ORDER BY anio DESC, numero DESC");
$stmt->execute([$marca['id']]);
$campanas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// stats por campaña
$campStats = [];
foreach ($campanas as $c) {
    $stmt = $pdo->prepare("
        SELECT e.precio, COALESCE((SELECT SUM(monto) FROM pagos WHERE encargo_id = e.id),0) AS pagado
        FROM encargos e WHERE e.campana_id = ?
    ");
    $stmt->execute([$c['id']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $porCobrar = 0;
    foreach ($rows as $r) {
        $saldo = $r['precio'] - $r['pagado'];
        if ($saldo > 0.004) $porCobrar += $saldo;
    }
    $campStats[$c['id']] = ['pedidos' => count($rows), 'porCobrar' => $porCobrar];
}

$anioActual = (int)date('Y');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title><?= htmlspecialchars($marca['nombre']) ?> · Catálogo</title>
<link rel="stylesheet" href="assets/css/style.css">
<style>:root{ --accent: <?= $marca['color'] ?>; }</style>
</head>
<body>

<div class="topbar">
    <a class="back" href="index.php">←</a>
    <h1><?= htmlspecialchars($marca['nombre']) ?></h1>
    <span class="tag" style="color:<?= $marca['color'] ?>"><?= count($campanas) ?> campañas</span>
</div>

<div class="container" style="padding-top:8px;">
    <?php if (empty($campanas)): ?>
        <div class="empty-state">Aún no hay campañas para <?= htmlspecialchars($marca['nombre']) ?>.<br>Agrega la primera abajo.</div>
    <?php else: ?>
        <div class="campana-grid">
        <?php foreach ($campanas as $c):
            $s = $campStats[$c['id']];
        ?>
            <div class="campana-card" style="--accent:<?= $marca['color'] ?>">
                <button class="campana-del" onclick="eliminarCampana(<?= $c['id'] ?>, <?= $s['pedidos'] ?>, this)" title="Eliminar campaña">✕</button>
                <a href="campana.php?id=<?= $c['id'] ?>" style="display:block;">
                    <div class="num" style="color:<?= $marca['color'] ?>">C<?= $c['numero'] ?></div>
                    <div class="rango"><?= $c['anio'] ?> · <?= $s['pedidos'] ?> pedido<?= $s['pedidos']==1?'':'s' ?></div>
                    <div class="mini-stat <?= $s['porCobrar'] <= 0.004 ? 'ok' : '' ?>">
                        <?= $s['porCobrar'] <= 0.004 ? 'Al corriente' : money($s['porCobrar']) . ' pend.' ?>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="bottom-bar">
    <button class="btn-primary" style="background:<?= $marca['color'] ?>" onclick="document.getElementById('modalNuevaCampana').classList.add('open')">+ Nueva campaña</button>
</div>

<!-- Modal nueva campaña -->
<div class="modal-overlay" id="modalNuevaCampana">
    <div class="modal">
        <h3>Nueva campaña · <?= htmlspecialchars($marca['nombre']) ?></h3>
        <div class="form-row">
            <label>Número de campaña</label>
            <input type="number" id="numCampana" placeholder="Ej. 13" min="1" max="20">
        </div>
        <div class="form-row">
            <label>Año</label>
            <input type="number" id="anioCampana" value="<?= $anioActual ?>">
        </div>
        <div class="form-row">
            <label>Fecha inicio (opcional)</label>
            <input type="date" id="inicioCampana">
        </div>
        <div class="form-row">
            <label>Fecha fin (opcional)</label>
            <input type="date" id="finCampana">
        </div>
        <div class="form-actions">
            <button class="btn-secondary" onclick="document.getElementById('modalNuevaCampana').classList.remove('open')">Cancelar</button>
            <button class="btn-primary" style="background:<?= $marca['color'] ?>" onclick="crearCampana()">Crear</button>
        </div>
    </div>
</div>

<div id="toast"></div>
<script>
const MARCA_ID = <?= (int)$marca['id'] ?>;

function toast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 1800);
}

async function crearCampana() {
    const numero = document.getElementById('numCampana').value;
    const anio = document.getElementById('anioCampana').value;
    const fecha_inicio = document.getElementById('inicioCampana').value;
    const fecha_fin = document.getElementById('finCampana').value;
    if (!numero || !anio) { toast('Falta el número o el año'); return; }

    const res = await fetch('api/campanas.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ marca_id: MARCA_ID, numero, anio, fecha_inicio, fecha_fin })
    });
    const data = await res.json();
    if (data.ok) {
        location.reload();
    } else {
        toast(data.error || 'No se pudo crear');
    }
}

async function eliminarCampana(id, pedidos, btn) {
    const aviso = pedidos > 0
        ? `Esta campaña tiene ${pedidos} pedido${pedidos === 1 ? '' : 's'} registrado${pedidos === 1 ? '' : 's'}. Si la eliminas, se borran también sus pedidos y pagos. ¿Continuar?`
        : '¿Eliminar esta campaña?';
    if (!confirm(aviso)) return;

    const res = await fetch('api/campanas.php?id=' + id, { method: 'DELETE' });
    const data = await res.json();
    if (data.ok) {
        btn.closest('.campana-card').remove();
        toast('Campaña eliminada');
    } else {
        toast(data.error || 'No se pudo eliminar');
    }
}
</script>
</body>
</html>
