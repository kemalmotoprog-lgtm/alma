<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/vendor/fpdf.php';

$pdo = getDB();
$hoy = date('Y-m-d');
$fechaInicio = trim($_GET['fecha_inicio'] ?? '') ?: $hoy;
$fechaFin = trim($_GET['fecha_fin'] ?? '') ?: $hoy;
if ($fechaFin < $fechaInicio) [$fechaInicio, $fechaFin] = [$fechaFin, $fechaInicio];

$todasMarcas = $pdo->query("SELECT id FROM marcas")->fetchAll(PDO::FETCH_COLUMN);
$marcasSel = isset($_GET['marcas']) ? array_map('intval', (array)$_GET['marcas']) : $todasMarcas;

$reporte = generarReporte($pdo, $fechaInicio, $fechaFin, $marcasSel);

function ascii($s) { return iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s ?? ''); }

class ReportePDF extends FPDF {
    public $rango = '';
    function Header() {
        $this->SetFont('Arial', 'B', 14);
        $this->Cell(0, 8, ascii('Reporte de cobranza'), 0, 1);
        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(120, 120, 120);
        $this->Cell(0, 6, ascii($this->rango . ' · Generado el ' . date('d/m/Y H:i')), 0, 1);
        $this->SetTextColor(0, 0, 0);
        $this->Ln(2);
    }
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(140, 140, 140);
        $this->Cell(0, 10, 'Pagina ' . $this->PageNo(), 0, 0, 'C');
    }
    function Seccion($titulo) {
        $this->Ln(3);
        $this->SetFont('Arial', 'B', 11);
        $this->Cell(0, 8, ascii($titulo), 0, 1);
    }
    function TablaHeader($cols) {
        $this->SetFont('Arial', 'B', 8.5);
        $this->SetFillColor(30, 33, 41);
        $this->SetTextColor(255, 255, 255);
        foreach ($cols as $c) $this->Cell($c[1], 7, ascii($c[0]), 1, 0, 'C', true);
        $this->Ln();
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Arial', '', 8.5);
    }
}

$pdf = new ReportePDF();
$pdf->rango = $fechaInicio === $fechaFin ? "Fecha: $fechaInicio" : "Del $fechaInicio al $fechaFin";
$pdf->AliasNbPages();
$pdf->AddPage('P');

// Resumen
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(63, 9, ascii('Cobrado: ') . number_format($reporte['totalCobrado'], 2), 1);
$pdf->Cell(63, 9, ascii('Vendido: ') . number_format($reporte['totalVendido'], 2), 1);
$pdf->Cell(64, 9, ascii('Quedo a deber: ') . number_format($reporte['totalPendienteGenerado'], 2), 1);
$pdf->Ln();

// Por marca
$pdf->Seccion('Cobrado por marca');
$pdf->TablaHeader([['Marca', 60], ['Pedidos', 30], ['Vendido', 45], ['Cobrado', 45]]);
foreach ($reporte['porMarca'] as $pm) {
    $pdf->Cell(60, 6.5, ascii($pm['nombre']), 1);
    $pdf->Cell(30, 6.5, (string)$pm['pedidos'], 1, 0, 'C');
    $pdf->Cell(45, 6.5, number_format($pm['vendido'], 2), 1, 0, 'R');
    $pdf->Cell(45, 6.5, number_format($pm['cobrado'], 2), 1, 0, 'R');
    $pdf->Ln();
}
if (empty($reporte['porMarca'])) {
    $pdf->Cell(180, 8, ascii('Sin movimientos en este rango.'), 1, 1, 'C');
}

// Por día (si el rango abarca más de un día)
if ($fechaInicio !== $fechaFin && !empty($reporte['porDia'])) {
    $pdf->Seccion('Cobrado por dia');
    $pdf->TablaHeader([['Fecha', 90], ['Cobrado', 90]]);
    foreach ($reporte['porDia'] as $dia => $monto) {
        $pdf->Cell(90, 6.5, date('d/m/Y', strtotime($dia)), 1);
        $pdf->Cell(90, 6.5, number_format($monto, 2), 1, 0, 'R');
        $pdf->Ln();
    }
}

// Top clientas
if (!empty($reporte['topClientas'])) {
    $pdf->Seccion('Quien mas pago en este rango');
    $pdf->TablaHeader([['Clienta', 90], ['Pagado', 90]]);
    foreach ($reporte['topClientas'] as $tc) {
        $pdf->Cell(90, 6.5, ascii(safe_mb_substr($tc['nombre'], 0, 45)), 1);
        $pdf->Cell(90, 6.5, number_format($tc['total'], 2), 1, 0, 'R');
        $pdf->Ln();
    }
}

$pdf->Output('I', 'reporte_' . $fechaInicio . '_a_' . $fechaFin . '.pdf');
