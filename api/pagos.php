<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';

$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $in = jsonInput();
    $encargoId = (int)($in['encargo_id'] ?? 0);
    $monto = (float)($in['monto'] ?? 0);
    $fecha = trim($in['fecha'] ?? '') ?: date('Y-m-d');
    if (!$encargoId) jsonOut(['ok' => false, 'error' => 'Falta encargo_id'], 400);

    $stmt = $pdo->prepare("INSERT INTO pagos (encargo_id, monto, fecha) VALUES (?,?,?)");
    $stmt->execute([$encargoId, $monto, $fecha]);

    jsonOut(['ok' => true, 'id' => $pdo->lastInsertId(), 'encargo' => encargoConPagos($pdo, $encargoId)]);
}

if ($method === 'PUT') {
    $in = jsonInput();
    $id = (int)($in['id'] ?? 0);
    if (!$id) jsonOut(['ok' => false, 'error' => 'Falta id'], 400);

    $stmt = $pdo->prepare("SELECT encargo_id FROM pagos WHERE id = ?");
    $stmt->execute([$id]);
    $encargoId = $stmt->fetchColumn();
    if (!$encargoId) jsonOut(['ok' => false, 'error' => 'Pago no existe'], 404);

    $stmt = $pdo->prepare("UPDATE pagos SET monto = ?, fecha = ? WHERE id = ?");
    $stmt->execute([(float)($in['monto'] ?? 0), trim($in['fecha'] ?? '') ?: date('Y-m-d'), $id]);

    jsonOut(['ok' => true, 'encargo' => encargoConPagos($pdo, (int)$encargoId)]);
}

if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonOut(['ok' => false, 'error' => 'Falta id'], 400);

    $stmt = $pdo->prepare("SELECT encargo_id FROM pagos WHERE id = ?");
    $stmt->execute([$id]);
    $encargoId = $stmt->fetchColumn();

    $pdo->prepare("DELETE FROM pagos WHERE id = ?")->execute([$id]);

    jsonOut(['ok' => true, 'encargo' => $encargoId ? encargoConPagos($pdo, (int)$encargoId) : null]);
}

jsonOut(['ok' => false, 'error' => 'Método no soportado'], 405);
