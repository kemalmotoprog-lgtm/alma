<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';

$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $q = trim($_GET['q'] ?? '');
    if ($q !== '') {
        $stmt = $pdo->prepare("SELECT * FROM clientas WHERE nombre LIKE ? OR alias LIKE ? ORDER BY nombre LIMIT 20");
        $like = '%' . $q . '%';
        $stmt->execute([$like, $like]);
    } else {
        $stmt = $pdo->query("SELECT * FROM clientas ORDER BY nombre LIMIT 100");
    }
    jsonOut(['ok' => true, 'clientas' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($method === 'POST') {
    $in = jsonInput();
    $nombre = trim($in['nombre'] ?? '');
    $alias = trim($in['alias'] ?? '') ?: null;
    $telefono = trim($in['telefono'] ?? '') ?: null;
    if ($nombre === '') jsonOut(['ok' => false, 'error' => 'Falta el nombre'], 400);

    $stmt = $pdo->prepare("INSERT INTO clientas (nombre, alias, telefono) VALUES (?,?,?)");
    $stmt->execute([$nombre, $alias, $telefono]);
    jsonOut(['ok' => true, 'id' => $pdo->lastInsertId()]);
}

if ($method === 'PUT') {
    $in = jsonInput();
    $id = (int)($in['id'] ?? 0);
    if (!$id) jsonOut(['ok' => false, 'error' => 'Falta id'], 400);
    $stmt = $pdo->prepare("UPDATE clientas SET nombre=?, alias=?, telefono=? WHERE id=?");
    $stmt->execute([trim($in['nombre'] ?? ''), trim($in['alias'] ?? '') ?: null, trim($in['telefono'] ?? '') ?: null, $id]);
    jsonOut(['ok' => true]);
}

jsonOut(['ok' => false, 'error' => 'Método no soportado'], 405);
