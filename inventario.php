<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

$pdo = getDB();
$marcas = $pdo->query("SELECT * FROM marcas ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

$filtroMarca = (int)($_GET['marca_id'] ?? 0);

$sql = "SELECT p.*, m.nombre AS marca_nombre, m.color AS marca_color,
               c.numero AS campana_numero, c.anio AS campana_anio
        FROM productos p
        JOIN marcas m ON m.id = p.marca_id
        LEFT JOIN campanas c ON c.id = p.campana_id
        WHERE p.activo = 1";
$params = [];
if ($filtroMarca) { $sql .= " AND p.marca_id = ?"; $params[] = $filtroMarca; }
$sql .= " ORDER BY m.id, p.nombre";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$porMarca = [];
foreach ($productos as $p) {
    $porMarca[$p['marca_id']]['nombre'] = $p['marca_nombre'];
    $porMarca[$p['marca_id']]['color'] = $p['marca_color'];
    $porMarca[$p['marca_id']]['items'][] = $p;
}

$totalItems = count($productos);
$totalStock = array_sum(array_column($productos, 'stock'));
$valorInventario = 0;
foreach ($productos as $p) $valorInventario += $p['precio_sugerido'] * max($p['stock'], 0);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title>Inventario · Catálogo</title>
<link rel="stylesheet" href="assets/css/style.css">
<style>
:root{ --accent: #e0a83e; }
.marca-filtros { display:flex; gap:8px; overflow-x:auto; padding: 4px 16px 14px; }
.marca-filtros a {
    flex-shrink:0; font-family: var(--font-mono); font-size:12.5px;
    padding: 7px 13px; border-radius: 20px; border:1px solid var(--border); color: var(--text-dim);
    white-space:nowrap;
}
.marca-filtros a.active { color:#0d0e12; font-weight:600; border-color:transparent; }
.producto-card {
    background: var(--card); border:1px solid var(--border); border-radius: var(--radius);
    padding: 13px 14px; margin-bottom: 9px; position: relative;
}
.producto-card::before { content:""; position:absolute; left:0; top:0; bottom:0; width:4px; background: var(--m-color, var(--text-faint)); border-radius: var(--radius) 0 0 var(--radius); }
.producto-top { display:flex; justify-content:space-between; align-items:flex-start; gap:10px; }
.producto-nombre input {
    background:transparent; border:none; color:var(--text); font-size:14.5px; font-weight:500; font-family: var(--font-body);
    width:100%; padding:2px 0; border-bottom:1px dashed transparent;
}
.producto-nombre input:focus { outline:none; border-bottom:1px dashed var(--text-faint); }
.producto-marca-tag { font-family: var(--font-mono); font-size:10.5px; color: var(--m-color); white-space:nowrap; }
.producto-meta { display:flex; gap:12px; margin-top:9px; flex-wrap:wrap; }
.producto-meta .field { display:flex; flex-direction:column; gap:3px; }
.producto-meta .field .lbl { font-size:10px; color:var(--text-faint); text-transform:uppercase; letter-spacing:.05em; }
.producto-meta input, .producto-meta select {
    background: var(--bg-elevated); border:1px solid var(--border); color:var(--text);
    font-family: var(--font-mono); font-size:12.5px; border-radius:8px; padding:5px 8px; width:88px;
}
.producto-meta select { width: auto; font-family: var(--font-body); }
.producto-actions { display:flex; justify-content:flex-end; margin-top:9px; }
</style>
</head>
<body>

<div class="topbar">
    <a class="back" href="index.php">←</a>
    <h1>Inventario</h1>
    <span class="tag" style="color:#e0a83e"><?= $totalItems ?> productos</span>
</div>

<div class="stat-grid" style="padding-top:14px;">
    <div class="stat-card">
        <div class="lbl">Productos</div>
        <div class="val"><?= $totalItems ?></div>
    </div>
    <div class="stat-card">
        <div class="lbl">Piezas en stock</div>
        <div class="val"><?= $totalStock ?></div>
    </div>
    <div class="stat-card">
        <div class="lbl">Valor inventario</div>
        <div class="val" style="color:var(--green)"><?= money($valorInventario) ?></div>
    </div>
</div>

<div class="marca-filtros">
    <a href="inventario.php" class="<?= !$filtroMarca ? 'active' : '' ?>" style="<?= !$filtroMarca ? 'background:#e0a83e' : '' ?>">Todas</a>
    <?php foreach ($marcas as $m): ?>
        <a href="inventario.php?marca_id=<?= $m['id'] ?>"
           class="<?= $filtroMarca === (int)$m['id'] ? 'active' : '' ?>"
           style="<?= $filtroMarca === (int)$m['id'] ? 'background:'.$m['color'] : '' ?>">
            <?= htmlspecialchars($m['nombre']) ?>
        </a>
    <?php endforeach; ?>
</div>

<div class="container" style="padding-top:0;">
    <?php if (empty($productos)): ?>
        <div class="empty-state">Todavía no hay productos en el inventario.<br>Agrega el primero abajo.</div>
    <?php else: foreach ($porMarca as $marcaId => $data): ?>
        <div class="section-title"><span><?= htmlspecialchars($data['nombre']) ?></span><span><?= count($data['items']) ?></span></div>
        <?php foreach ($data['items'] as $p): ?>
        <div class="producto-card" style="--m-color: <?= $data['color'] ?>" data-producto-id="<?= $p['id'] ?>">
            <div class="producto-top">
                <div class="producto-nombre">
                    <input type="text" value="<?= htmlspecialchars($p['nombre']) ?>"
                           onchange="actualizarProducto(<?= $p['id'] ?>, {nombre: this.value})">
                </div>
                <span class="producto-marca-tag" style="--m-color:<?= $data['color'] ?>">
                    <?= $p['campana_numero'] ? 'C' . $p['campana_numero'] . '/' . $p['campana_anio'] : 'sin campaña' ?>
                </span>
            </div>
            <div class="producto-meta">
                <div class="field">
                    <span class="lbl">Código</span>
                    <input type="text" value="<?= htmlspecialchars($p['codigo'] ?? '') ?>" placeholder="Ej. FM12345" style="width:100px; text-transform:uppercase;"
                           onchange="actualizarProducto(<?= $p['id'] ?>, {codigo: this.value})">
                </div>
                <div class="field">
                    <span class="lbl">Precio</span>
                    <input type="number" step="0.01" value="<?= $p['precio_sugerido'] ?>"
                           onchange="actualizarProducto(<?= $p['id'] ?>, {precio_sugerido: this.value})">
                </div>
                <div class="field">
                    <span class="lbl">Stock</span>
                    <input type="number" value="<?= $p['stock'] ?>"
                           onchange="actualizarProducto(<?= $p['id'] ?>, {stock: this.value})">
                </div>
            </div>
            <div class="producto-actions">
                <button class="btn-danger-ghost" onclick="eliminarProducto(<?= $p['id'] ?>, this)">Eliminar</button>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endforeach; endif; ?>
</div>

<div class="bottom-bar">
    <a class="btn-secondary" href="export_pdf_inventario.php<?= $filtroMarca ? '?marca_id='.$filtroMarca : '' ?>" target="_blank">↓ PDF</a>
    <button class="btn-secondary" onclick="enviarTelegramInventario()">↗ Telegram</button>
    <button class="btn-primary" style="background:#e0a83e" onclick="abrirModalProducto()">+ Producto</button>
</div>

<!-- Modal nuevo producto -->
<div class="modal-overlay" id="modalProducto">
    <div class="modal">
        <h3>Nuevo producto</h3>

        <div class="form-row">
            <label>Nombre</label>
            <input type="text" id="prodNombre" placeholder="Ej. Perfume Kaiak 100ml">
        </div>

        <div class="form-row">
            <label>Código (opcional)</label>
            <input type="text" id="prodCodigo" placeholder="Ej. FM12345" style="text-transform:uppercase;">
        </div>

        <div class="form-row">
            <label>Marca</label>
            <select id="prodMarca" onchange="cargarCampanasDeMarca()">
                <option value="">— Selecciona —</option>
                <?php foreach ($marcas as $m): ?>
                    <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-row">
            <label>Campaña</label>
            <select id="prodCampana">
                <option value="">— Elige una marca primero —</option>
            </select>
        </div>

        <div class="form-row">
            <label>Precio (opcional)</label>
            <input type="number" id="prodPrecio" step="0.01" placeholder="0.00">
        </div>

        <div class="form-row">
            <label>Stock (opcional, si tienes pieza física)</label>
            <input type="number" id="prodStock" placeholder="0">
        </div>

        <div class="form-actions">
            <button class="btn-secondary" onclick="cerrarModalProducto()">Cancelar</button>
            <button class="btn-primary" style="background:#e0a83e" onclick="crearProducto()">Guardar</button>
        </div>
    </div>
</div>

<div id="toast"></div>
<script src="assets/js/app.js"></script>
<script src="assets/js/inventario.js"></script>
</body>
</html>
