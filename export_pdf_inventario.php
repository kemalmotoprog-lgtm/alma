<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/vendor/fpdf.php';

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

function ascii($s) { return iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s ?? ''); }

class InventarioPDF extends FPDF {
    function Header() {
        $this->SetFont('Arial', 'B', 14);
        $this->Cell(0, 8, ascii('Inventario - Alma Delia'), 0, 1);
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
        $this->SetFont('Arial', 'B', 9);
        $this->SetFillColor(30, 33, 41);
        $this->SetTextColor(255, 255, 255);
        $w = [30, 55, 25, 20, 20, 20];
        $h = ['Codigo', 'Producto', 'Campana', 'Precio', 'Stock', 'Valor'];
        foreach ($h as $i => $t) $this->Cell($w[$i], 7, ascii($t), 1, 0, 'C', true);
        $this->Ln();
        $this->SetTextColor(0, 0, 0);
    }
}

$pdf = new InventarioPDF();
$pdf->AliasNbPages();
$pdf->AddPage('P');

$w = [30, 55, 25, 20, 20, 20];
$porMarca = [];
foreach ($rows as $r) $porMarca[$r['marca_nombre']][] = $r;

$totalValor = 0; $totalStock = 0;

foreach ($porMarca as $marcaNombre => $items) {
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Ln(2);
    $pdf->Cell(0, 8, ascii($marcaNombre), 0, 1);
    $pdf->TablaHeader();
    $pdf->SetFont('Arial', '', 8.5);

    foreach ($items as $p) {
        $valor = $p['precio_sugerido'] * max($p['stock'], 0);
        $totalValor += $valor;
        $totalStock += $p['stock'];
        $campanaTxt = $p['campana_numero'] ? 'C' . $p['campana_numero'] . '/' . $p['campana_anio'] : '-';

        $pdf->Cell($w[0], 6.5, ascii($p['codigo'] ?: '-'), 1, 0, 'C');
        $pdf->Cell($w[1], 6.5, ascii(safe_mb_substr($p['nombre'], 0, 32)), 1);
        $pdf->Cell($w[2], 6.5, ascii($campanaTxt), 1, 0, 'C');
        $pdf->Cell($w[3], 6.5, number_format($p['precio_sugerido'], 2), 1, 0, 'R');
        $pdf->Cell($w[4], 6.5, (string)$p['stock'], 1, 0, 'C');
        $pdf->Cell($w[5], 6.5, number_format($valor, 2), 1, 0, 'R');
        $pdf->Ln();
    }
}

if (empty($rows)) {
    $pdf->Cell(array_sum($w), 8, ascii('Sin productos registrados.'), 1, 1, 'C');
}

$pdf->SetFont('Arial', 'B', 9);
$pdf->SetFillColor(235, 235, 235);
$pdf->Ln(2);
$pdf->Cell($w[0] + $w[1] + $w[2] + $w[3], 7, 'TOTALES', 1, 0, 'R', true);
$pdf->Cell($w[4], 7, (string)$totalStock, 1, 0, 'C', true);
$pdf->Cell($w[5], 7, number_format($totalValor, 2), 1, 1, 'R', true);

$pdf->Output('I', 'inventario_' . date('Y-m-d') . '.pdf');
