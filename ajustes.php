<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

$pdo = getDB();
$tamano = file_exists(DB_PATH) ? filesize(DB_PATH) : 0;
$tamanoTxt = $tamano > 1048576 ? round($tamano / 1048576, 2) . ' MB' : round($tamano / 1024, 1) . ' KB';

$nClientas = (int)$pdo->query("SELECT COUNT(*) FROM clientas")->fetchColumn();
$nEncargos = (int)$pdo->query("SELECT COUNT(*) FROM encargos")->fetchColumn();
$nPagos = (int)$pdo->query("SELECT COUNT(*) FROM pagos")->fetchColumn();
$nProductos = (int)$pdo->query("SELECT COUNT(*) FROM productos WHERE activo = 1")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title>Ajustes · Catálogo</title>
<link rel="stylesheet" href="assets/css/style.css">
<style>:root{ --accent: var(--text-dim); }</style>
</head>
<body>

<div class="topbar">
    <a class="back" href="index.php">←</a>
    <h1>Ajustes</h1>
</div>

<div class="container">
    <div class="section-title"><span>Base de datos</span></div>
    <div class="widget" style="margin-bottom:16px;">
        <div class="brand-sub" style="margin-bottom:10px;">
            <?= $nClientas ?> clientas · <?= $nEncargos ?> encargos · <?= $nPagos ?> pagos · <?= $nProductos ?> productos en inventario
        </div>
        <div class="brand-sub" style="margin-bottom:14px;">Tamaño actual: <span class="mono"><?= $tamanoTxt ?></span></div>
        <a class="btn-primary" style="display:block; text-align:center; background:var(--text-dim); color:#0d0e12;" href="backup_db.php">
            ↓ Descargar respaldo de la base de datos
        </a>
    </div>

    <div class="section-title"><span>Sobre el respaldo</span></div>
    <div class="widget" style="--accent: var(--text-faint);">
        <p style="font-size:13px; color:var(--text-dim); line-height:1.5; margin:0;">
            Descarga un archivo <code>.sqlite</code> con todo lo registrado hasta este momento
            (clientas, encargos, pagos e inventario). Guárdalo en un lugar seguro
            (Google Drive, correo, etc.) antes de mover el proyecto de servidor.
            Para restaurarlo, solo reemplaza el archivo en <code>data/catalogo.sqlite</code>
            en el nuevo servidor.
        </p>
    </div>
</div>

</body>
</html>
