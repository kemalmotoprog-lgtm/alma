<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

$pdo = getDB();
$campanaId = (int)($_GET['campana_id'] ?? $_POST['campana_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['ok' => false, 'error' => 'Método no soportado'], 405);
}

if (TELEGRAM_BOT_TOKEN === 'TU_BOT_TOKEN_AQUI' || TELEGRAM_CHAT_ID === 'TU_CHAT_ID_AQUI') {
    jsonOut(['ok' => false, 'error' => 'Configura tu bot de Telegram en config.php'], 400);
}

$stmt = $pdo->prepare("SELECT c.*, m.nombre AS marca_nombre FROM campanas c JOIN marcas m ON m.id = c.marca_id WHERE c.id = ?");
$stmt->execute([$campanaId]);
$campana = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$campana) jsonOut(['ok' => false, 'error' => 'Campaña no encontrada'], 404);

$stmt = $pdo->prepare("
    SELECT e.id, e.precio, e.estado, cl.nombre AS clienta_nombre,
           COALESCE((SELECT SUM(monto) FROM pagos WHERE encargo_id = e.id),0) AS pagado
    FROM encargos e JOIN clientas cl ON cl.id = e.clienta_id
    WHERE e.campana_id = ?
");
$stmt->execute([$campanaId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalPedidos = count($rows);
$totalPrecio = array_sum(array_column($rows, 'precio'));
$totalPagado = array_sum(array_column($rows, 'pagado'));
$porCobrar = 0;
$pendientes = [];
foreach ($rows as $r) {
    $saldo = $r['precio'] - $r['pagado'];
    if ($saldo > 0.004) {
        $porCobrar += $saldo;
        $pendientes[] = $r['clienta_nombre'] . ': ' . money($saldo);
    }
}

$texto = "📋 *Reporte {$campana['marca_nombre']} · Campaña {$campana['numero']}/{$campana['anio']}*\n\n";
$texto .= "🧾 Pedidos: {$totalPedidos}\n";
$texto .= "💰 Total: " . money($totalPrecio) . "\n";
$texto .= "✅ Cobrado: " . money($totalPagado) . "\n";
$texto .= "⏳ Por cobrar: " . money($porCobrar) . "\n";

if (!empty($pendientes)) {
    $texto .= "\n*Clientas con saldo pendiente:*\n";
    foreach (array_slice($pendientes, 0, 25) as $p) {
        $texto .= "• {$p}\n";
    }
    if (count($pendientes) > 25) $texto .= '… y ' . (count($pendientes) - 25) . ' más\n';
}

function telegramCall(string $method, array $params, ?string $filePath = null, ?string $fileField = null) {
    $url = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/' . $method;
    if ($filePath && $fileField) {
        $params[$fileField] = new CURLFile($filePath);
    }
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
