<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';

$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $in = jsonInput();
    $campanaId = (int)($in['campana_id'] ?? 0);
    $clientaId = (int)($in['clienta_id'] ?? 0);
    $productoId = !empty($in['producto_id']) ? (int)$in['producto_id'] : null;
    $descripcion = trim($in['descripcion'] ?? '');
    $estado = ($in['estado'] ?? 'por_entregar') === 'entregado' ? 'entregado' : 'por_entregar';
    $precio = (float)($in['precio'] ?? 0);
    $fecha = trim($in['fecha'] ?? '') ?: date('Y-m-d');

    if (!$campanaId || !$clientaId) jsonOut(['ok' => false, 'error' => 'Faltan datos'], 400);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO encargos (campana_id, clienta_id, producto_id, descripcion, estado, precio, fecha) VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([$campanaId, $clientaId, $productoId, $descripcion, $estado, $precio, $fecha]);
        $id = $pdo->lastInsertId();

        // Descuenta inventario si aplica y hay stock físico
        if ($productoId) {
            $stmt = $pdo->prepare("SELECT stock FROM productos WHERE id = ?");
            $stmt->execute([$productoId]);
            $stock = (int)$stmt->fetchColumn();
            if ($stock > 0) {
                $pdo->prepare("UPDATE productos SET stock = stock - 1 WHERE id = ?")->execute([$productoId]);
            }
        }

        $pdo->commit();
        jsonOut(['ok' => true, 'id' => $id]);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonOut(['ok' => false, 'error' => 'No se pudo guardar'], 500);
    }
}

if ($method === 'PUT') {
    $in = jsonInput();
    $id = (int)($in['id'] ?? 0);
    if (!$id) jsonOut(['ok' => false, 'error' => 'Falta id'], 400);

    $fields = [];
    $vals = [];
    foreach (['descripcion' => 's', 'estado' => 's', 'precio' => 'f', 'fecha' => 's', 'producto_id' => 'i'] as $f => $type) {
        if (array_key_exists($f, $in)) {
            $fields[] = "$f = ?";
            $vals[] = $type === 'f' ? (float)$in[$f] : ($type === 'i' ? ($in[$f] !== null && $in[$f] !== '' ? (int)$in[$f] : null) : trim($in[$f]));
        }
    }
    if (empty($fields)) jsonOut(['ok' => false, 'error' => 'Nada que actualizar'], 400);

    $vals[] = $id;
    $stmt = $pdo->prepare("UPDATE encargos SET " . implode(', ', $fields) . " WHERE id = ?");
    $stmt->execute($vals);

    jsonOut(['ok' => true, 'encargo' => encargoConPagos($pdo, $id)]);
}

if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonOut(['ok' => false, 'error' => 'Falta id'], 400);
    $pdo->prepare("DELETE FROM encargos WHERE id = ?")->execute([$id]);
    jsonOut(['ok' => true]);
}

jsonOut(['ok' => false, 'error' => 'Método no soportado'], 405);
