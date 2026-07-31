<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/vendor/fpdf.php';

$pdo = getDB();
$clientaId = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM clientas WHERE id = ?");
$stmt->execute([$clientaId]);
$clienta = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$clienta) { http_response_code(404); die('Clienta no encontrada'); }

$stmt = $pdo->prepare("
    SELECT e.*, c.numero AS campana_numero, c.anio AS campana_anio, m.nombre AS marca_nombre
    FROM encargos e JOIN campanas c ON c.id = e.campana_id JOIN marcas m ON m.id = c.marca_id
    WHERE e.clienta_id = ? ORDER BY e.fecha DESC
");
$stmt->execute([$clientaId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

function ascii($s) { return iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s ?? ''); }

class EstadoCuentaPDF extends FPDF {
    public $nombreClienta = '';
    function Header() {
        $this->SetFont('Arial', 'B', 14);
        $this->Cell(0, 8, ascii('Estado de cuenta - ' . $this->nombreClienta), 0, 1);
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
        $w = [35, 55, 20, 20, 22, 22, 22];
        $h = ['Marca/Camp.', 'Encargo', 'Fecha', 'Estado', 'Precio', 'Pagado', 'Saldo'];
        foreach ($h as $i => $t) $this->Cell($w[$i], 7, ascii($t), 1, 0, 'C', true);
        $this->Ln();
        $this->SetTextColor(0, 0, 0);
    }
}

$pdf = new EstadoCuentaPDF();
$pdf->nombreClienta = $clienta['nombre'] . ($clienta['alias'] ? ' ("' . $clienta['alias'] . '")' : '');
$pdf->AliasNbPages();
$pdf->AddPage('L');
$pdf->TablaHeader();
$pdf->SetFont('Arial', '', 8.5);

$w = [35, 55, 20, 20, 22, 22, 22];
$totalComprado = 0; $totalPagado = 0; $totalSaldo = 0;

foreach ($rows as $r) {
    $stmt2 = $pdo->prepare("SELECT COALESCE(SUM(monto),0) FROM pagos WHERE encargo_id = ?");
    $stmt2->execute([$r['id']]);
    $pagado = (float)$stmt2->fetchColumn();
    $saldo = round($r['precio'] - $pagado, 2);
    $liquidado = $saldo <= 0.004;

    $totalComprado += $r['precio'];
    $totalPagado += $pagado;
    $totalSaldo += max($saldo, 0);

    $pdf->Cell($w[0], 6.5, ascii($r['marca_nombre'] . ' C' . $r['campana_numero'] . '/' . $r['campana_anio']), 1);
    $pdf->Cell($w[1], 6.5, ascii(safe_mb_substr($r['descripcion'] ?: '-', 0, 32)), 1);
    $pdf->Cell($w[2], 6.5, date('d/m/y', strtotime($r['fecha'])), 1, 0, 'C');
    $pdf->Cell($w[3], 6.5, ascii($r['estado'] === 'entregado' ? 'Entregado' : 'Pendiente'), 1, 0, 'C');
    $pdf->Cell($w[4], 6.5, number_format($r['precio'], 2), 1, 0, 'R');
    $pdf->Cell($w[5], 6.5, number_format($pagado, 2), 1, 0, 'R');
    $pdf->SetTextColor($liquidado ? 30 : 180, $liquidado ? 130 : 110, $liquidado ? 70 : 30);
    $pdf->Cell($w[6], 6.5, number_format(max($saldo,0), 2), 1, 0, 'R');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln();
}

if (empty($rows)) {
    $pdf->Cell(array_sum($w), 8, ascii('Sin encargos registrados.'), 1, 1, 'C');
}

$pdf->SetFont('Arial', 'B', 9);
$pdf->SetFillColor(235, 235, 235);
$pdf->Cell($w[0] + $w[1] + $w[2] + $w[3], 7, 'TOTALES', 1, 0, 'R', true);
$pdf->Cell($w[4], 7, number_format($totalComprado, 2), 1, 0, 'R', true);
$pdf->Cell($w[5], 7, number_format($totalPagado, 2), 1, 0, 'R', true);
$pdf->Cell($w[6], 7, number_format($totalSaldo, 2), 1, 1, 'R', true);

$filename = 'estado_cuenta_' . preg_replace('/[^a-z0-9]+/i', '_', strtolower($clienta['nombre'])) . '.pdf';
$pdf->Output('I', $filename);
