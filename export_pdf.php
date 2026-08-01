<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/vendor/fpdf.php';

$pdo = getDB();
$campanaId = (int)($_GET['campana_id'] ?? 0);

$stmt = $pdo->prepare("SELECT c.*, m.nombre AS marca_nombre FROM campanas c JOIN marcas m ON m.id = c.marca_id WHERE c.id = ?");
$stmt->execute([$campanaId]);
$campana = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$campana) { http_response_code(404); die('Campaña no encontrada'); }

$stmt = $pdo->prepare("SELECT e.*, cl.nombre AS clienta_nombre, cl.alias AS clienta_alias
                        FROM encargos e JOIN clientas cl ON cl.id = e.clienta_id
                        WHERE e.campana_id = ? ORDER BY cl.nombre, e.creado_en");
$stmt->execute([$campanaId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

function ascii($s) {
    // FPDF core fonts solo soportan latin-1
    return iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s ?? '');
}

class ReportePDF extends FPDF {
    public $tituloReporte = '';
    function Header() {
        $this->SetFont('Arial', 'B', 16);
        $this->Cell(0, 8, ascii(NOMBRE_NEGOCIO), 0, 1);
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 7, ascii($this->tituloReporte), 0, 1);
        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(120, 120, 120);
        $this->Cell(0, 6, ascii('Generado el ' . date('d/m/Y H:i')), 0, 1);
        $this->SetTextColor(0, 0, 0);
        $this->Ln(2);
    }
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(140, 140, 140);
        $this->Cell(0, 10, 'Pagina ' . $this->PageNo(), 0, 0, 'C');
    }
    function TablaHeader() {
        $this->SetFont('Arial', 'B', 8.5);
        $this->SetFillColor(30, 33, 41);
        $this->SetTextColor(255, 255, 255);
        $w = [45, 42, 18, 18, 22, 22, 22];
        $h = ['Clienta', 'Encargo', 'Estado', 'Precio', 'Pagado', 'Saldo', 'Estatus'];
        foreach ($h as $i => $t) $this->Cell($w[$i], 7, ascii($t), 1, 0, 'C', true);
        $this->Ln();
        $this->SetTextColor(0, 0, 0);
    }
}

$pdf = new ReportePDF();
$pdf->tituloReporte = 'Reporte ' . $campana['marca_nombre'] . ' - Campana ' . $campana['numero'] . '/' . $campana['anio'];
$pdf->AliasNbPages();
$pdf->AddPage('L'); // horizontal, mas espacio para columnas
$pdf->TablaHeader();
$pdf->SetFont('Arial', '', 8.5);

$w = [45, 42, 18, 18, 22, 22, 22];
$totalPrecio = 0; $totalPagado = 0; $totalSaldo = 0;

foreach ($rows as $r) {
    $pagosStmt = $pdo->prepare("SELECT COALESCE(SUM(monto),0) FROM pagos WHERE encargo_id = ?");
    $pagosStmt->execute([$r['id']]);
    $pagado = (float)$pagosStmt->fetchColumn();
    $saldo = round($r['precio'] - $pagado, 2);
    $liquidado = $saldo <= 0.004;

    $totalPrecio += $r['precio'];
    $totalPagado += $pagado;
    $totalSaldo += max($saldo, 0);

    $nombre = $r['clienta_nombre'] . ($r['clienta_alias'] ? ' ("' . $r['clienta_alias'] . '")' : '');
    $pdf->Cell($w[0], 6.5, ascii(safe_mb_substr($nombre, 0, 26)), 1);
    $pdf->Cell($w[1], 6.5, ascii(safe_mb_substr($r['descripcion'] ?: '-', 0, 24)), 1);
    $pdf->Cell($w[2], 6.5, ascii($r['estado'] === 'entregado' ? 'Entregado' : 'Pendiente'), 1, 0, 'C');
    $pdf->Cell($w[3], 6.5, number_format($r['precio'], 2), 1, 0, 'R');
    $pdf->Cell($w[4], 6.5, number_format($pagado, 2), 1, 0, 'R');
    $pdf->Cell($w[5], 6.5, number_format(max($saldo,0), 2), 1, 0, 'R');
    $pdf->SetTextColor($liquidado ? 30 : 180, $liquidado ? 130 : 110, $liquidado ? 70 : 30);
    $pdf->Cell($w[6], 6.5, ascii($liquidado ? 'Liquidado' : 'Pendiente'), 1, 0, 'C');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln();
}

if (empty($rows)) {
    $pdf->Cell(array_sum($w), 8, ascii('Sin encargos registrados en esta campana.'), 1, 1, 'C');
}

// Totales
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetFillColor(235, 235, 235);
$pdf->Cell($w[0] + $w[1] + $w[2], 7, 'TOTALES', 1, 0, 'R', true);
$pdf->Cell($w[3], 7, number_format($totalPrecio, 2), 1, 0, 'R', true);
$pdf->Cell($w[4], 7, number_format($totalPagado, 2), 1, 0, 'R', true);
$pdf->Cell($w[5], 7, number_format($totalSaldo, 2), 1, 0, 'R', true);
$pdf->Cell($w[6], 7, '', 1, 1, 'C', true);

$filename = 'reporte_' . preg_replace('/[^a-z0-9]+/i', '_', strtolower($campana['marca_nombre'])) . '_c' . $campana['numero'] . '_' . $campana['anio'] . '.pdf';
$pdf->Output('I', $filename);
