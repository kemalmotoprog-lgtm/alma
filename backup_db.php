<?php
require_once __DIR__ . '/db.php';

$pdo = getDB();

$nombreArchivo = 'respaldo_catalogo_' . date('Y-m-d_His') . '.sqlite';
$rutaTemporal = sys_get_temp_dir() . '/' . uniqid('catalogo_backup_') . '.sqlite';

try {
    // VACUUM INTO crea una copia consistente de la base, incluyendo cualquier
    // cambio reciente que todavía viva solo en el WAL (no basta con copiar el .sqlite a mano).
    $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $rutaTemporal) . "'");
} catch (Throwable $e) {
    http_response_code(500);
    die('No se pudo generar el respaldo: ' . htmlspecialchars($e->getMessage()));
}

if (!file_exists($rutaTemporal)) {
    http_response_code(500);
    die('No se pudo generar el respaldo.');
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
header('Content-Length: ' . filesize($rutaTemporal));
header('Cache-Control: no-store');
readfile($rutaTemporal);
unlink($rutaTemporal);
exit;
