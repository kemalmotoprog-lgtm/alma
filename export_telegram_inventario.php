<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['ok' => false, 'error' => 'Método no soportado'], 405);
}
if (TELEGRAM_BOT_TOKEN === 'TU_BOT_TOKEN_AQUI' || TELEGRAM_CHAT_ID === 'TU_CHAT_ID_AQUI') {
    jsonOut(['ok' => false, 'error' => 'Configura tu bot de Telegram en config.php'], 400);
}

$pdo = getDB();
$filtroMarca = (int)($_GET['marca_id'] ?? 0);

$sql = "SELECT p.*, m.nombre AS marca_nombre, c.numero AS campana_numero, c.anio AS campana_anio
        FROM productos p JOIN marcas m ON m.id = p.marca_id
        LEFT JOIN campanas c ON c.id = p.campana_id
        WHERE p.activo = 1";
$params = [];
if ($filtroMarca) { $sql .= " AND p.marca_id = ?"; $params[] = $filtroMarca; }
$sql .= " ORDER BY m.id, p.nombre";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$porMarca = [];
foreach ($rows as $r) $porMarca[$r['marca_nombre']][] = $r;

$totalValor = 0; $totalStock = 0;
$texto = "📦 *Inventario · Alma Delia*\n\n";

foreach ($porMarca as $marcaNombre => $items) {
    $texto .= "*{$marcaNombre}*\n";
    foreach ($items as $p) {
        $valor = $p['precio_sugerido'] * max($p['stock'], 0);
        $totalValor += $valor;
        $totalStock += $p['stock'];
        $campanaTxt = $p['campana_numero'] ? " (C{$p['campana_numero']}/{$p['campana_anio']})" : '';
        $codigoTxt = $p['codigo'] ? " [{$p['codigo']}]" : '';
        $texto .= "• {$p['nombre']}{$codigoTxt}{$campanaTxt} — stock {$p['stock']}, " . money($p['precio_sugerido']) . "\n";
    }
    $texto .= "\n";
}

$texto .= "📊 Piezas en stock: {$totalStock}\n";
$texto .= "💰 Valor total: " . money($totalValor) . "\n";

if (empty($rows)) $texto = "📦 *Inventario · Alma Delia*\n\nNo hay productos registrados todavía.";

function telegramCall(string $method, array $params) {
    $url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/' . $method;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) return ['ok' => false, 'description' => $err];
    return json_decode($response, true);
}

$res = telegramCall('sendMessage', [
    'chat_id' => TELEGRAM_CHAT_ID,
    'text' => $texto,
    'parse_mode' => 'Markdown'
]);

if (empty($res['ok'])) {
    jsonOut(['ok' => false, 'error' => $res['description'] ?? 'Telegram rechazó el mensaje'], 500);
}

jsonOut(['ok' => true]);
