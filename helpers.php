<?php

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
