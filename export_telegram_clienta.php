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
$clientaId = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM clientas WHERE id = ?");
$stmt->execute([$clientaId]);
$clienta = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$clienta) jsonOut(['ok' => false, 'error' => 'Clienta no encontrada'], 404);

$stmt = $pdo->prepare("
    SELECT e.*, c.numero AS campana_numero, c.anio AS campana_anio, m.nombre AS marca_nombre
    FROM encargos e JOIN campanas c ON c.id = e.campana_id JOIN marcas m ON m.id = c.marca_id
    WHERE e.clienta_id = ? ORDER BY e.fecha DESC
");
$stmt->execute([$clientaId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalComprado = 0; $totalPagado = 0;
$pendientes = [];
foreach ($rows as $r) {
    $stmt2 = $pdo->prepare("SELECT COALESCE(SUM(monto),0) FROM pagos WHERE encargo_id = ?");
    $stmt2->execute([$r['id']]);
    $pagado = (float)$stmt2->fetchColumn();
    $saldo = round($r['precio'] - $pagado, 2);
    $totalComprado += $r['precio'];
    $totalPagado += $pagado;
    if ($saldo > 0.004) {
        $pendientes[] = "{$r['marca_nombre']} C{$r['campana_numero']} · " . ($r['descripcion'] ?: 'sin descripción') . " → " . money($saldo);
    }
}
$saldoTotal = round($totalComprado - $totalPagado, 2);

$nombreTxt = $clienta['nombre'] . ($clienta['alias'] ? ' ("' . $clienta['alias'] . '")' : '');
$texto = "🧾 *Estado de cuenta · {$nombreTxt}*\n\n";
$texto .= "💰 Total comprado: " . money($totalComprado) . "\n";
$texto .= "✅ Total pagado: " . money($totalPagado) . "\n";
$texto .= "⏳ Saldo: " . money(max($saldoTotal, 0)) . "\n";

if (!empty($pendientes)) {
    $texto .= "\n*Pendientes:*\n";
    foreach (array_slice($pendientes, 0, 25) as $p) $texto .= "• {$p}\n";
} elseif (!empty($rows)) {
    $texto .= "\n✅ Está al corriente con todos sus pedidos.";
}

if (empty($rows)) $texto = "🧾 *Estado de cuenta · {$nombreTxt}*\n\nEsta clienta todavía no tiene encargos.";

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
