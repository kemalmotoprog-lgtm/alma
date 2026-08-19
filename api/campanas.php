<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';

$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $marcaId = (int)($_GET['marca_id'] ?? 0);
    if (!$marcaId) jsonOut(['ok' => false, 'error' => 'Falta marca_id'], 400);
    $stmt = $pdo->prepare("SELECT * FROM campanas WHERE marca_id = ? ORDER BY anio DESC, numero DESC");
    $stmt->execute([$marcaId]);
    jsonOut(['ok' => true, 'campanas' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($method === 'POST') {
    $in = jsonInput();
    $marcaId = (int)($in['marca_id'] ?? 0);
    $numero = (int)($in['numero'] ?? 0);
    $anio = (int)($in['anio'] ?? date('Y'));
    $inicio = trim($in['fecha_inicio'] ?? '') ?: null;
    $fin = trim($in['fecha_fin'] ?? '') ?: null;

    if (!$marcaId || !$numero) jsonOut(['ok' => false, 'error' => 'Faltan datos'], 400);

    try {
        $stmt = $pdo->prepare("INSERT INTO campanas (marca_id, numero, anio, fecha_inicio, fecha_fin) VALUES (?,?,?,?,?)");
        $stmt->execute([$marcaId, $numero, $anio, $inicio, $fin]);
        jsonOut(['ok' => true, 'id' => $pdo->lastInsertId()]);
    } catch (PDOException $e) {
        jsonOut(['ok' => false, 'error' => 'Esa campaña ya existe'], 400);
    }
}

if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonOut(['ok' => false, 'error' => 'Falta id'], 400);
    // El borrado en cascada (definido en el esquema) se lleva encargos y pagos de esta campaña
    $pdo->prepare("DELETE FROM campanas WHERE id = ?")->execute([$id]);
    jsonOut(['ok' => true]);
}

jsonOut(['ok' => false, 'error' => 'Método no soportado'], 405);
