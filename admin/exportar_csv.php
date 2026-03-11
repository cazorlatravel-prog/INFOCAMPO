<?php
/**
 * INFOCAMPO SaaS - Exportar datos a CSV/Excel
 *
 * Exporta infraestructuras o registros de inspección en formato CSV
 * compatible con Excel (BOM UTF-8 + separador punto y coma).
 *
 * Parámetros GET:
 *   - tipo: 'infraestructuras' | 'registros'
 *   - empresa_id: ID de la empresa
 *   - infra_id: (opcional) filtrar registros por infraestructura
 *   - estado: (opcional) filtrar por estado de incidencia
 *   - fecha_desde, fecha_hasta: (opcional) rango de fechas
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

requireRole(['admin', 'superadmin', 'supervisor']);

$pdo = getDB();

$tipo = $_GET['tipo'] ?? '';
$empresaId = (int) ($_GET['empresa_id'] ?? $_SESSION['empresa_id'] ?? 0);

if ($empresaId <= 0) {
    http_response_code(400);
    echo 'Empresa no identificada';
    exit;
}

if (!in_array($tipo, ['infraestructuras', 'registros'], true)) {
    http_response_code(400);
    echo 'Tipo de exportación no válido. Use: infraestructuras o registros';
    exit;
}

// Obtener nombre de la empresa para el archivo
$empStmt = $pdo->prepare("SELECT nombre FROM empresas WHERE id = :id");
$empStmt->execute([':id' => $empresaId]);
$empRow = $empStmt->fetch();
$empNombre = $empRow ? preg_replace('/[^a-zA-Z0-9_-]/', '_', $empRow['nombre']) : 'empresa';

$filename = $tipo . '_' . $empNombre . '_' . date('Y-m-d') . '.csv';

// Headers para descarga CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');

// BOM UTF-8 para que Excel reconozca los caracteres
echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');

if ($tipo === 'infraestructuras') {
    // Cabeceras
    fputcsv($output, [
        'ID', 'Nombre', 'Código', 'Tipo', 'Provincia', 'Municipio', 'Monte',
        'Latitud', 'Longitud', 'Descripción', 'Activa',
        'Nº Inspecciones', 'Fecha Creación',
    ], ';');

    $stmt = $pdo->prepare(
        "SELECT i.*,
                (SELECT COUNT(*) FROM registros r WHERE r.infra_id = i.id) AS num_registros
         FROM infraestructuras i
         WHERE i.empresa_id = :emp
         ORDER BY i.nombre"
    );
    $stmt->execute([':emp' => $empresaId]);

    while ($row = $stmt->fetch()) {
        fputcsv($output, [
            $row['id'],
            $row['nombre'],
            $row['codigo_unico'],
            $row['tipo'] ?? '',
            $row['provincia'] ?? '',
            $row['municipio'] ?? '',
            $row['monte'] ?? '',
            $row['lat_teorica'],
            $row['lon_teorica'],
            $row['descripcion'] ?? '',
            $row['activa'] ? 'Sí' : 'No',
            $row['num_registros'],
            $row['created_at'] ?? '',
        ], ';');
    }

} elseif ($tipo === 'registros') {
    // Cabeceras
    fputcsv($output, [
        'ID', 'Infraestructura', 'Código Infra', 'Operador', 'Fecha',
        'Latitud Real', 'Longitud Real', 'Estado Incidencia',
        'Tipo Foto', 'Secuencia', 'Observaciones', 'URL Foto',
    ], ';');

    $sql = "SELECT r.*, i.nombre AS infra_nombre, i.codigo_unico, u.nombre AS operador
            FROM registros r
            INNER JOIN infraestructuras i ON r.infra_id = i.id
            INNER JOIN usuarios u ON r.usuario_id = u.id
            WHERE i.empresa_id = :emp";
    $params = [':emp' => $empresaId];

    // Filtros opcionales
    $infraId = (int) ($_GET['infra_id'] ?? 0);
    if ($infraId > 0) {
        $sql .= " AND r.infra_id = :infra_id";
        $params[':infra_id'] = $infraId;
    }

    $estado = $_GET['estado'] ?? '';
    if ($estado !== '' && in_array($estado, ['antes', 'durante', 'despues'], true)) {
        $sql .= " AND r.estado_incidencia = :estado";
        $params[':estado'] = $estado;
    }

    $fechaDesde = $_GET['fecha_desde'] ?? '';
    if ($fechaDesde !== '') {
        $sql .= " AND r.fecha >= :f_desde";
        $params[':f_desde'] = $fechaDesde . ' 00:00:00';
    }

    $fechaHasta = $_GET['fecha_hasta'] ?? '';
    if ($fechaHasta !== '') {
        $sql .= " AND r.fecha <= :f_hasta";
        $params[':f_hasta'] = $fechaHasta . ' 23:59:59';
    }

    $sql .= " ORDER BY r.fecha DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    while ($row = $stmt->fetch()) {
        fputcsv($output, [
            $row['id'],
            $row['infra_nombre'],
            $row['codigo_unico'],
            $row['operador'],
            date('d/m/Y H:i', strtotime($row['fecha'])),
            $row['lat_real'],
            $row['lon_real'],
            strtoupper($row['estado_incidencia']),
            $row['tipo_foto'] ?? 'aleatorio',
            $row['secuencia_comparativa'] ?? '',
            $row['observaciones'] ?? '',
            $row['url_cloudinary'],
        ], ';');
    }
}

fclose($output);
