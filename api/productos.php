<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';

$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $marcaId = (int)($_GET['marca_id'] ?? 0);
    $soloActivos = !isset($_GET['all']); // por defecto solo activos (para selects de encargo)

    $sql = "SELECT p.*, m.nombre AS marca_nombre, m.color AS marca_color,
                   c.numero AS campana_numero, c.anio AS campana_anio
            FROM productos p
            JOIN marcas m ON m.id = p.marca_id
            LEFT JOIN campanas c ON c.id = p.campana_id
            WHERE 1=1";
    $params = [];
    if ($marcaId) { $sql .= " AND p.marca_id = ?"; $params[] = $marcaId; }
    if ($soloActivos) { $sql .= " AND p.activo = 1"; }
    $sql .= " ORDER BY m.id, p.nombre";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    jsonOut(['ok' => true, 'productos' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($method === 'POST') {
    $in = jsonInput();
    $marcaId = (int)($in['marca_id'] ?? 0);
    $campanaId = !empty($in['campana_id']) ? (int)$in['campana_id'] : null;
    $nombre = trim($in['nombre'] ?? '');
    $precio = (float)($in['precio_sugerido'] ?? 0);
    $stock = (int)($in['stock'] ?? 0);
    if (!$marcaId || $nombre === '') jsonOut(['ok' => false, 'error' => 'Faltan datos'], 400);

    $stmt = $pdo->prepare("INSERT INTO productos (marca_id, campana_id, nombre, precio_sugerido, stock) VALUES (?,?,?,?,?)");
    $stmt->execute([$marcaId, $campanaId, $nombre, $precio, $stock]);
    jsonOut(['ok' => true, 'id' => $pdo->lastInsertId()]);
}

if ($method === 'PUT') {
    $in = jsonInput();
    $id = (int)($in['id'] ?? 0);
    if (!$id) jsonOut(['ok' => false, 'error' => 'Falta id'], 400);

    $fields = [];
    $vals = [];
    if (array_key_exists('nombre', $in)) { $fields[] = 'nombre = ?'; $vals[] = trim($in['nombre']); }
    if (array_key_exists('marca_id', $in)) { $fields[] = 'marca_id = ?'; $vals[] = (int)$in['marca_id']; }
    if (array_key_exists('campana_id', $in)) { $fields[] = 'campana_id = ?'; $vals[] = $in['campana_id'] !== '' && $in['campana_id'] !== null ? (int)$in['campana_id'] : null; }
    if (array_key_exists('precio_sugerido', $in)) { $fields[] = 'precio_sugerido = ?'; $vals[] = (float)$in['precio_sugerido']; }
    if (array_key_exists('stock', $in)) { $fields[] = 'stock = ?'; $vals[] = (int)$in['stock']; }
    if (array_key_exists('activo', $in)) { $fields[] = 'activo = ?'; $vals[] = (int)$in['activo']; }
    if (empty($fields)) jsonOut(['ok' => false, 'error' => 'Nada que actualizar'], 400);

    $vals[] = $id;
    $pdo->prepare("UPDATE productos SET " . implode(', ', $fields) . " WHERE id = ?")->execute($vals);
    jsonOut(['ok' => true]);
}

if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonOut(['ok' => false, 'error' => 'Falta id'], 400);
    // Borrado real: el inventario es de Alma, a diferencia de encargos no necesita conservarse por historial
    $pdo->prepare("DELETE FROM productos WHERE id = ?")->execute([$id]);
    jsonOut(['ok' => true]);
}

jsonOut(['ok' => false, 'error' => 'Método no soportado'], 405);
