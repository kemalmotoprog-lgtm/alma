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
$hoy = date('Y-m-d');
$fechaInicio = trim($_GET['fecha_inicio'] ?? '') ?: $hoy;
$fechaFin = trim($_GET['fecha_fin'] ?? '') ?: $hoy;
if ($fechaFin < $fechaInicio) [$fechaInicio, $fechaFin] = [$fechaFin, $fechaInicio];

$todasMarcas = $pdo->query("SELECT id FROM marcas")->fetchAll(PDO::FETCH_COLUMN);
$marcasSel = isset($_GET['marcas']) ? array_map('intval', (array)$_GET['marcas']) : $todasMarcas;

$reporte = generarReporte($pdo, $fechaInicio, $fechaFin, $marcasSel);
$rangoTxt = $fechaInicio === $fechaFin ? $fechaInicio : "{$fechaInicio} al {$fechaFin}";

$texto = "📊 *" . NOMBRE_NEGOCIO . "*\n";
$texto .= "*Reporte de cobranza · {$rangoTxt}*\n\n";
$texto .= "✅ Cobrado: " . money($reporte['totalCobrado']) . "\n";
$texto .= "💰 Vendido: " . money($reporte['totalVendido']) . "\n";
$texto .= "⏳ Quedó a deber: " . money($reporte['totalPendienteGenerado']) . "\n";

if (!empty($reporte['porMarca'])) {
    $texto .= "\n*Por marca:*\n";
    foreach ($reporte['porMarca'] as $pm) {
        $texto .= "• {$pm['nombre']}: " . money($pm['cobrado']) . " cobrado ({$pm['pedidos']} pedidos)\n";
    }
}

if (empty($reporte['pagos']) && empty($reporte['pedidos'])) {
    $texto = "📊 *" . NOMBRE_NEGOCIO . "*\n*Reporte de cobranza · {$rangoTxt}*\n\nSin movimientos en este rango.";
}

// Todos los cobros individuales, en mensajes aparte (Telegram limita ~4096 caracteres por mensaje)
$mensajesCobros = [];
if (!empty($reporte['pagos'])) {
    $bloque = "*Todos los cobros (" . count($reporte['pagos']) . "):*\n";
    foreach ($reporte['pagos'] as $pg) {
        $linea = "• " . date('d/m', strtotime($pg['fecha'])) . " — {$pg['clienta_nombre']} ({$pg['marca_nombre']}): " . money($pg['monto']) . "\n";
        if (strlen($bloque) + strlen($linea) > 3500) {
            $mensajesCobros[] = $bloque;
            $bloque = '';
        }
        $bloque .= $linea;
    }
    $bloque .= "\n*Total cobrado: " . money($reporte['totalCobrado']) . "*";
    $mensajesCobros[] = $bloque;
}

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

foreach ($mensajesCobros as $bloque) {
    $r = telegramCall('sendMessage', [
        'chat_id' => TELEGRAM_CHAT_ID,
        'text' => $bloque,
        'parse_mode' => 'Markdown'
    ]);
    if (empty($r['ok'])) {
        jsonOut(['ok' => false, 'error' => 'Se envió el resumen, pero falló el detalle de cobros: ' . ($r['description'] ?? '')], 500);
    }
}

jsonOut(['ok' => true]);
