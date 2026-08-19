<?php

// Respaldo por si config.php en el servidor todavía no tiene esta constante
// (pasa si no se reemplazó ese archivo al actualizar, algo que recomendamos
// para no perder credenciales ya configuradas de Telegram).
if (!defined('NOMBRE_NEGOCIO')) {
    define('NOMBRE_NEGOCIO', 'ALMA DELIA COSMETICS');
}

/**
 * Envoltorios seguros para funciones mbstring: si la extensión no está
 * instalada (pasa en algunos PHP de Kali/Debian por defecto), usamos un
 * respaldo basado en expresiones regulares con soporte UTF-8, que sí
 * viene incluido en PHP sin extensiones extra.
 */
function safe_mb_substr(string $str, int $start, ?int $length = null): string {
    if (function_exists('mb_substr')) {
        return mb_substr($str, $start, $length, 'UTF-8');
    }
    preg_match_all('/./us', $str, $m);
    $chars = $length === null ? array_slice($m[0], $start) : array_slice($m[0], $start, $length);
    return implode('', $chars);
}

function safe_mb_strtoupper(string $str): string {
    if (function_exists('mb_strtoupper')) {
        return mb_strtoupper($str, 'UTF-8');
    }
    static $map = null;
    if ($map === null) {
        $lower = explode(' ', 'á é í ó ú à è ì ò ù ä ë ï ö ü ñ');
        $upper = explode(' ', 'Á É Í Ó Ú À È Ì Ò Ù Ä Ë Ï Ö Ü Ñ');
        $map = array_combine($lower, $upper);
    }
    return strtr(strtoupper($str), $map);
}

function safe_mb_strtolower(string $str): string {
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($str, 'UTF-8');
    }
    static $map = null;
    if ($map === null) {
        $upper = explode(' ', 'Á É Í Ó Ú À È Ì Ò Ù Ä Ë Ï Ö Ü Ñ');
        $lower = explode(' ', 'á é í ó ú à è ì ò ù ä ë ï ö ü ñ');
        $map = array_combine($upper, $lower);
    }
    return strtr(strtolower($str), $map);
}

function money(float $n): string {
    return '$' . number_format($n, 2);
}

function jsonInput(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function jsonOut($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Suma de pagos de un encargo */
function totalPagado(PDO $pdo, int $encargoId): float {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(monto),0) FROM pagos WHERE encargo_id = ?");
    $stmt->execute([$encargoId]);
    return (float)$stmt->fetchColumn();
}

/** Trae un encargo con sus pagos y totales calculados */
function encargoConPagos(PDO $pdo, int $encargoId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM encargos WHERE id = ?");
    $stmt->execute([$encargoId]);
    $encargo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$encargo) return null;

    $stmt = $pdo->prepare("SELECT * FROM pagos WHERE encargo_id = ? ORDER BY fecha ASC, id ASC");
    $stmt->execute([$encargoId]);
    $pagos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $pagado = array_sum(array_column($pagos, 'monto'));
    $encargo['pagos'] = $pagos;
    $encargo['pagado'] = $pagado;
    $encargo['saldo'] = round($encargo['precio'] - $pagado, 2);
    $encargo['liquidado'] = $encargo['saldo'] <= 0.004;
    return $encargo;
}

function nombreCampana(array $campana): string {
    return 'Campaña ' . $campana['numero'] . ' · ' . $campana['anio'];
}

/** Calcula cobrado, vendido y desgloses para el módulo de Reportes, en un rango de fechas y marcas dadas */
function generarReporte(PDO $pdo, string $inicio, string $fin, array $marcaIds): array {
    if (empty($marcaIds)) $marcaIds = [0]; // ninguna seleccionada -> resultado vacío, no todas
    $in = implode(',', array_fill(0, count($marcaIds), '?'));

    // Cobrado: pagos hechos dentro del rango, de encargos de las marcas seleccionadas
    $stmt = $pdo->prepare("
        SELECT pg.fecha, pg.monto, m.id AS marca_id, m.nombre AS marca_nombre, m.color AS marca_color,
               cl.id AS clienta_id, cl.nombre AS clienta_nombre
        FROM pagos pg
        JOIN encargos e ON e.id = pg.encargo_id
        JOIN campanas c ON c.id = e.campana_id
        JOIN marcas m ON m.id = c.marca_id
        JOIN clientas cl ON cl.id = e.clienta_id
        WHERE pg.fecha BETWEEN ? AND ? AND m.id IN ($in)
        ORDER BY pg.fecha ASC
    ");
    $stmt->execute([$inicio, $fin, ...$marcaIds]);
    $pagos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Pedidos registrados en el rango (para ver cuánto se vendió, no solo cuánto se cobró)
    $stmt = $pdo->prepare("
        SELECT e.id, e.fecha, e.precio, m.id AS marca_id, m.nombre AS marca_nombre, m.color AS marca_color,
               COALESCE((SELECT SUM(monto) FROM pagos WHERE encargo_id = e.id), 0) AS pagado
        FROM encargos e
        JOIN campanas c ON c.id = e.campana_id
        JOIN marcas m ON m.id = c.marca_id
        WHERE e.fecha BETWEEN ? AND ? AND m.id IN ($in)
    ");
    $stmt->execute([$inicio, $fin, ...$marcaIds]);
    $pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalCobrado = array_sum(array_column($pagos, 'monto'));
    $totalVendido = array_sum(array_column($pedidos, 'precio'));
    $totalPendienteGenerado = 0;
    foreach ($pedidos as $p) {
        $s = $p['precio'] - $p['pagado'];
        if ($s > 0.004) $totalPendienteGenerado += $s;
    }

    // Desglose por marca
    $porMarca = [];
    foreach ($pagos as $p) {
        $porMarca[$p['marca_id']]['nombre'] = $p['marca_nombre'];
        $porMarca[$p['marca_id']]['color'] = $p['marca_color'];
        $porMarca[$p['marca_id']]['cobrado'] = ($porMarca[$p['marca_id']]['cobrado'] ?? 0) + $p['monto'];
    }
    foreach ($pedidos as $p) {
        $porMarca[$p['marca_id']]['nombre'] = $p['marca_nombre'];
        $porMarca[$p['marca_id']]['color'] = $p['marca_color'];
        $porMarca[$p['marca_id']]['vendido'] = ($porMarca[$p['marca_id']]['vendido'] ?? 0) + $p['precio'];
        $porMarca[$p['marca_id']]['pedidos'] = ($porMarca[$p['marca_id']]['pedidos'] ?? 0) + 1;
    }
    foreach ($porMarca as &$pm) { $pm['cobrado'] = $pm['cobrado'] ?? 0; $pm['vendido'] = $pm['vendido'] ?? 0; $pm['pedidos'] = $pm['pedidos'] ?? 0; }
    unset($pm);

    // Desglose por día (solo cobrado, es lo que más le importa a Alma día a día)
    $porDia = [];
    foreach ($pagos as $p) {
        $porDia[$p['fecha']] = ($porDia[$p['fecha']] ?? 0) + $p['monto'];
    }
    ksort($porDia);

    return compact('totalCobrado', 'totalVendido', 'totalPendienteGenerado', 'porMarca', 'porDia', 'pagos', 'pedidos');
}
