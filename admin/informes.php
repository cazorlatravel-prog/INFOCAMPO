<?php
/**
 * INFOCAMPO SaaS - Página de Informes (Admin)
 *
 * Genera informes completos de infraestructura con:
 * - Datos de la infraestructura
 * - Plano de localización con escala
 * - Histórico de visitas
 * - Fotos aleatorias y comparativas
 * - Observaciones y campos dinámicos
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin', 'supervisor', 'superadmin']);

$pdo = getDB();
$currentPage = 'informes';
$empresaId = (int) ($_SESSION['empresa_id'] ?? 0);

// Cargar infraestructuras
$infraestructuras = [];
if ($empresaId > 0) {
    $stmt = $pdo->prepare(
        "SELECT i.id, i.nombre, i.codigo_unico, i.tipo, i.lat_teorica, i.lon_teorica,
                i.provincia, i.municipio,
                COUNT(r.id) AS num_fotos
         FROM infraestructuras i
         LEFT JOIN registros r ON r.infra_id = i.id
         WHERE i.empresa_id = :emp AND i.activa = 1
         GROUP BY i.id
         ORDER BY i.nombre"
    );
    $stmt->execute([':emp' => $empresaId]);
    $infraestructuras = $stmt->fetchAll();
}

// Si se solicita un informe concreto
$infraId = isset($_GET['infra_id']) ? (int) $_GET['infra_id'] : 0;
$infra = null;
$registros = [];
$comparativas = [];
$aleatorias = [];
$visitasComp = [];
$conteo = ['antes' => 0, 'durante' => 0, 'despues' => 0];
$camposDinamicos = [];
$valoresCampos = [];

if ($infraId > 0) {
    // Datos de infraestructura
    $stmt = $pdo->prepare(
        "SELECT i.*, e.nombre AS empresa_nombre
         FROM infraestructuras i
         INNER JOIN empresas e ON i.empresa_id = e.id
         WHERE i.id = :id AND i.empresa_id = :emp"
    );
    $stmt->execute([':id' => $infraId, ':emp' => $empresaId]);
    $infra = $stmt->fetch();

    if ($infra) {
        // Registros
        $stmt = $pdo->prepare(
            "SELECT r.*, u.nombre AS usuario_nombre, uo.nombre AS unidad_obra_nombre
             FROM registros r
             INNER JOIN usuarios u ON r.usuario_id = u.id
             LEFT JOIN unidades_obra uo ON r.unidad_obra_id = uo.id
             WHERE r.infra_id = :infra_id
             ORDER BY r.fecha ASC"
        );
        $stmt->execute([':infra_id' => $infraId]);
        $registros = $stmt->fetchAll();

        // Separar por tipo
        $comparativas = array_filter($registros, fn($r) => ($r['tipo_foto'] ?? '') === 'comparativo');
        $aleatorias = array_filter($registros, fn($r) => ($r['tipo_foto'] ?? '') !== 'comparativo');

        // Agrupar comparativas por visita (día + operador)
        foreach ($comparativas as $r) {
            $dia = date('Y-m-d', strtotime($r['fecha']));
            $visitasComp[$dia][] = $r;
        }

        // Conteo situaciones
        foreach ($registros as $r) {
            if (isset($conteo[$r['estado_incidencia']])) {
                $conteo[$r['estado_incidencia']]++;
            }
        }

        // Campos dinámicos de la empresa
        $stmt = $pdo->prepare(
            "SELECT id, nombre, slug, tipo FROM campos_formulario
             WHERE empresa_id = :emp AND activo = 1 ORDER BY orden ASC"
        );
        $stmt->execute([':emp' => $empresaId]);
        $camposDinamicos = $stmt->fetchAll();

        // Valores de campos dinámicos por registro
        if (!empty($camposDinamicos) && !empty($registros)) {
            $regIds = array_column($registros, 'id');
            $placeholders = implode(',', array_fill(0, count($regIds), '?'));
            $stmt = $pdo->prepare(
                "SELECT vc.registro_id, vc.campo_id, vc.valor, cf.nombre AS campo_nombre
                 FROM valores_campo vc
                 INNER JOIN campos_formulario cf ON vc.campo_id = cf.id
                 WHERE vc.registro_id IN ($placeholders)"
            );
            $stmt->execute($regIds);
            foreach ($stmt->fetchAll() as $vc) {
                $valoresCampos[(int)$vc['registro_id']][(int)$vc['campo_id']] = [
                    'valor' => $vc['valor'],
                    'nombre' => $vc['campo_nombre'],
                ];
            }
        }
    }
}

$fechaGeneracion = date('d/m/Y H:i');
$empresaNombre = $_SESSION['empresa_nombre'] ?? '';
$totalRegistros = count($registros);
$totalComp = count($comparativas);
$totalAlea = count($aleatorias);

// Calcular histórico de visitas agrupado por día
$historico = [];
foreach ($registros as $r) {
    $dia = date('Y-m-d', strtotime($r['fecha']));
    if (!isset($historico[$dia])) {
        $historico[$dia] = [
            'fecha' => $dia,
            'operadores' => [],
            'fotos' => 0,
            'antes' => 0,
            'durante' => 0,
            'despues' => 0,
            'observaciones' => [],
        ];
    }
    $historico[$dia]['fotos']++;
    if (isset($historico[$dia][$r['estado_incidencia']])) {
        $historico[$dia][$r['estado_incidencia']]++;
    }
    $historico[$dia]['operadores'][$r['usuario_nombre']] = true;
    if (!empty($r['observaciones'])) {
        $historico[$dia]['observaciones'][] = $r['observaciones'];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>FotoGPS.app - Informes</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="/admin/css/admin.css" rel="stylesheet">
<?php if ($infra): ?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<?php endif; ?>
<style>
    .page-content { max-width: 1000px; margin: 0 auto; padding: 24px; }

    /* Selector */
    .selector-card { background: #fff; border-radius: 12px; padding: 28px; box-shadow: 0 1px 4px rgba(0,0,0,0.06); margin-bottom: 24px; }

    /* Informe */
    .report { background: #fff; border-radius: 4px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); }
    .report-header { background: #1e3a5f; color: #fff; padding: 28px 36px; }
    .report-header h1 { font-size: 20px; font-weight: 800; margin-bottom: 4px; }
    .report-header p { opacity: 0.7; font-size: 11px; margin: 0; }

    .section { padding: 24px 36px; }
    .section h2 { font-size: 15px; color: #1e3a5f; border-bottom: 2px solid #1e3a5f; padding-bottom: 5px; margin-bottom: 14px; font-weight: 700; }
    .section h3 { font-size: 13px; color: #444; margin: 16px 0 8px; padding: 5px 10px; background: #f0f4ff; border-left: 3px solid #1e3a5f; }
    .section-divider { border: none; border-top: 1px solid #e0e0e0; margin: 0; }

    table.info { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    table.info td { padding: 7px 12px; border: 1px solid #dee2e6; font-size: 12px; }
    table.info td.label { background: #f8f9fa; font-weight: bold; width: 22%; color: #555; }

    table.data { width: 100%; border-collapse: collapse; }
    table.data th, table.data td { padding: 7px 10px; border: 1px solid #dee2e6; text-align: center; font-size: 11px; }
    table.data th { background: #1e3a5f; color: #fff; font-size: 10px; font-weight: 700; }

    .badge-antes { color: #1e40af; font-weight: bold; }
    .badge-durante { color: #854d0e; font-weight: bold; }
    .badge-despues { color: #166534; font-weight: bold; }
    .badge-comp { color: #6d28d9; font-weight: bold; }
    .badge-alea { color: #1d4ed8; font-weight: bold; }

    /* Mapa plano */
    #report-map { height: 300px; border: 1px solid #dee2e6; border-radius: 6px; margin-bottom: 8px; }
    .map-scale-info { font-size: 10px; color: #888; text-align: center; }

    /* Fotos */
    .foto-block { border: 1px solid #dee2e6; border-radius: 6px; padding: 12px; margin-bottom: 14px; page-break-inside: avoid; }
    .foto-block img { max-width: 100%; max-height: 300px; display: block; margin: 8px auto; border-radius: 4px; }
    .foto-meta { font-size: 11px; color: #666; line-height: 1.6; }
    .foto-meta strong { color: #333; }

    .comp-pair { display: flex; gap: 12px; margin-bottom: 14px; page-break-inside: avoid; }
    .comp-col { flex: 1; }
    .comp-col img { width: 100%; max-height: 220px; object-fit: contain; border-radius: 4px; }
    .comp-label { text-align: center; font-size: 11px; font-weight: bold; color: #6d28d9; margin-bottom: 6px; }
    .comp-gps { text-align: center; font-size: 9px; color: #888; margin-top: 4px; }

    /* Observaciones */
    .obs-item { background: #f8f9fa; border-radius: 6px; padding: 10px 14px; margin-bottom: 8px; font-size: 12px; border-left: 3px solid #2d6a9f; }
    .obs-item .obs-date { font-weight: 700; color: #1e3a5f; font-size: 11px; }
    .obs-item .obs-text { color: #333; margin-top: 2px; }

    /* Valores campos dinámicos */
    .campos-table td { font-size: 11px; }

    .report-footer { text-align: center; font-size: 10px; color: #999; border-top: 1px solid #dee2e6; padding: 12px; }

    /* Toolbar acciones */
    .report-toolbar { background: #fff; border-bottom: 1px solid #e5e7eb; padding: 10px 36px; display: flex; gap: 8px; align-items: center; }

    /* Print */
    @media print {
        .no-print { display: none !important; }
        body { background: #fff; }
        .report { box-shadow: none; }
        .report-header, table.data th, .section h3 { -webkit-print-color-adjust: exact; print-color-adjust: exact; color-adjust: exact; }
        .section { padding: 14px 24px; }
        .page-break { page-break-before: always; }
        .foto-block, .comp-pair { page-break-inside: avoid; }
        .section h2 { page-break-after: avoid; }
        #report-map { height: 250px; }
    }

    @media (max-width: 600px) {
        .section { padding: 16px; }
        .comp-pair { flex-direction: column; }
        .page-content { padding: 12px; }
    }
</style>
</head>
<body>
    <?php include __DIR__ . '/includes/header.php'; ?>

    <div class="page-content">

        <?php if (!$infra): ?>
        <!-- Selector de infraestructura -->
        <div class="selector-card">
            <h4 class="mb-3"><i class="bi bi-file-earmark-richtext me-2"></i>Generar Informe</h4>
            <p class="text-muted mb-3">Selecciona una infraestructura para generar un informe completo con datos, fotos, plano de localización e histórico de visitas.</p>

            <?php if (empty($infraestructuras)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-building" style="font-size:2rem;opacity:0.4;"></i>
                    <p class="mt-2">No hay infraestructuras disponibles.</p>
                </div>
            <?php else: ?>
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label fw-semibold small">Infraestructura</label>
                        <select id="select-infra" class="form-select">
                            <option value="">-- Seleccionar infraestructura --</option>
                            <?php foreach ($infraestructuras as $inf): ?>
                                <option value="<?= $inf['id'] ?>">
                                    <?= htmlspecialchars($inf['codigo_unico'] . ' - ' . $inf['nombre']) ?>
                                    (<?= $inf['num_fotos'] ?> fotos)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <button class="btn btn-primary w-100" onclick="generateReport()">
                            <i class="bi bi-file-earmark-richtext"></i> Generar Informe
                        </button>
                    </div>
                </div>

                <!-- Lista rápida de infraestructuras con más fotos -->
                <div class="mt-4">
                    <h6 class="text-muted small"><i class="bi bi-star"></i> Acceso rápido</h6>
                    <div class="d-flex flex-wrap gap-2">
                        <?php
                        $topInfras = array_filter($infraestructuras, fn($i) => $i['num_fotos'] > 0);
                        usort($topInfras, fn($a, $b) => $b['num_fotos'] - $a['num_fotos']);
                        $topInfras = array_slice($topInfras, 0, 10);
                        foreach ($topInfras as $ti):
                        ?>
                            <a href="informes.php?infra_id=<?= $ti['id'] ?>" class="btn btn-sm btn-outline-secondary">
                                <?= htmlspecialchars($ti['codigo_unico']) ?>
                                <span class="badge bg-primary ms-1"><?= $ti['num_fotos'] ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <script>
        function generateReport() {
            var id = document.getElementById('select-infra').value;
            if (!id) { alert('Selecciona una infraestructura'); return; }
            window.location.href = 'informes.php?infra_id=' + id;
        }
        </script>

        <?php else: ?>
        <!-- Informe completo -->

        <!-- Toolbar -->
        <div class="report-toolbar no-print" style="border-radius:8px 8px 0 0;margin-bottom:0;">
            <a href="informes.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Volver
            </a>
            <button onclick="window.print()" class="btn btn-sm btn-primary">
                <i class="bi bi-printer"></i> Imprimir / Guardar PDF
            </button>
            <span class="ms-auto text-muted small">Informe: <?= htmlspecialchars($infra['codigo_unico']) ?></span>
        </div>

        <div class="report">
            <!-- Cabecera -->
            <div class="report-header">
                <h1>Informe de Infraestructura</h1>
                <p><?= htmlspecialchars($empresaNombre) ?> &mdash; Generado el <?= $fechaGeneracion ?></p>
            </div>

            <!-- 1. Datos de la infraestructura -->
            <div class="section">
                <h2><i class="bi bi-building me-1"></i> Datos de la Infraestructura</h2>
                <table class="info">
                    <tr>
                        <td class="label">Código</td>
                        <td><?= htmlspecialchars($infra['codigo_unico']) ?></td>
                        <td class="label">Nombre</td>
                        <td><?= htmlspecialchars($infra['nombre']) ?></td>
                    </tr>
                    <tr>
                        <td class="label">Tipo</td>
                        <td><?= htmlspecialchars($infra['tipo'] ?? 'N/A') ?></td>
                        <td class="label">Empresa</td>
                        <td><?= htmlspecialchars($infra['empresa_nombre']) ?></td>
                    </tr>
                    <tr>
                        <td class="label">Provincia</td>
                        <td><?= htmlspecialchars($infra['provincia'] ?? '-') ?></td>
                        <td class="label">Municipio</td>
                        <td><?= htmlspecialchars($infra['municipio'] ?? '-') ?></td>
                    </tr>
                    <tr>
                        <td class="label">Latitud (ETRS89)</td>
                        <td><?= $infra['lat_teorica'] ?? '-' ?></td>
                        <td class="label">Longitud (ETRS89)</td>
                        <td><?= $infra['lon_teorica'] ?? '-' ?></td>
                    </tr>
                </table>

                <!-- Resumen numérico -->
                <table class="data">
                    <thead>
                        <tr>
                            <th>Total Fotos</th>
                            <th>Comparativas</th>
                            <th>Aleatorias</th>
                            <th>Fot. Antes</th>
                            <th>Fot. Durante</th>
                            <th>Fot. Después</th>
                            <th>Visitas</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong><?= $totalRegistros ?></strong></td>
                            <td class="badge-comp"><?= $totalComp ?></td>
                            <td class="badge-alea"><?= $totalAlea ?></td>
                            <td class="badge-antes"><?= $conteo['antes'] ?></td>
                            <td class="badge-durante"><?= $conteo['durante'] ?></td>
                            <td class="badge-despues"><?= $conteo['despues'] ?></td>
                            <td><strong><?= count($historico) ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <hr class="section-divider">

            <!-- 2. Plano de localización -->
            <?php if ($infra['lat_teorica'] && $infra['lon_teorica']): ?>
            <div class="section">
                <h2><i class="bi bi-map me-1"></i> Plano de Localización</h2>
                <div id="report-map"></div>
                <div class="map-scale-info" id="map-scale-info">
                    Coordenadas: <?= $infra['lat_teorica'] ?>, <?= $infra['lon_teorica'] ?> (ETRS89) &mdash; Escala: calculando...
                </div>
            </div>
            <hr class="section-divider">
            <?php endif; ?>

            <!-- 3. Histórico de visitas -->
            <?php if (!empty($historico)): ?>
            <div class="section">
                <h2><i class="bi bi-clock-history me-1"></i> Histórico de Visitas</h2>
                <table class="data">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Operador(es)</th>
                            <th>Fotos</th>
                            <th>Antes</th>
                            <th>Durante</th>
                            <th>Después</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($historico as $h): ?>
                        <tr>
                            <td><?= date('d/m/Y', strtotime($h['fecha'])) ?></td>
                            <td style="text-align:left;font-size:10px;"><?= htmlspecialchars(implode(', ', array_keys($h['operadores']))) ?></td>
                            <td><strong><?= $h['fotos'] ?></strong></td>
                            <td class="badge-antes"><?= $h['antes'] ?></td>
                            <td class="badge-durante"><?= $h['durante'] ?></td>
                            <td class="badge-despues"><?= $h['despues'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <hr class="section-divider">
            <?php endif; ?>

            <!-- 4. Observaciones -->
            <?php
            $todasObs = [];
            foreach ($registros as $r) {
                if (!empty(trim($r['observaciones'] ?? ''))) {
                    $todasObs[] = [
                        'fecha' => $r['fecha'],
                        'operador' => $r['usuario_nombre'],
                        'estado' => $r['estado_incidencia'],
                        'texto' => $r['observaciones'],
                    ];
                }
            }
            if (!empty($todasObs)):
            ?>
            <div class="section">
                <h2><i class="bi bi-chat-text me-1"></i> Observaciones</h2>
                <?php foreach ($todasObs as $obs): ?>
                    <div class="obs-item">
                        <div class="obs-date">
                            <?= date('d/m/Y H:i', strtotime($obs['fecha'])) ?>
                            &mdash; <?= htmlspecialchars($obs['operador']) ?>
                            &mdash; <span class="badge-<?= $obs['estado'] ?>"><?= strtoupper($obs['estado']) ?></span>
                        </div>
                        <div class="obs-text"><?= nl2br(htmlspecialchars($obs['texto'])) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <hr class="section-divider">
            <?php endif; ?>

            <!-- 5. Campos dinámicos (si hay valores) -->
            <?php if (!empty($valoresCampos)): ?>
            <div class="section">
                <h2><i class="bi bi-ui-checks-grid me-1"></i> Datos de Formulario</h2>
                <table class="data campos-table">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Operador</th>
                            <?php foreach ($camposDinamicos as $cd): ?>
                                <th><?= htmlspecialchars($cd['nombre']) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($registros as $reg):
                        if (!isset($valoresCampos[(int)$reg['id']])) continue;
                        $vals = $valoresCampos[(int)$reg['id']];
                    ?>
                        <tr>
                            <td><?= date('d/m/Y H:i', strtotime($reg['fecha'])) ?></td>
                            <td><?= htmlspecialchars($reg['usuario_nombre']) ?></td>
                            <?php foreach ($camposDinamicos as $cd): ?>
                                <td style="text-align:left;"><?= htmlspecialchars($vals[(int)$cd['id']]['valor'] ?? '-') ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <hr class="section-divider">
            <?php endif; ?>

            <!-- 6. Fotos Comparativas -->
            <?php if (!empty($visitasComp)): ?>
            <div class="page-break"></div>
            <div class="section">
                <h2><i class="bi bi-layers me-1"></i> Fotos Comparativas</h2>
                <?php
                $visitaNum = 0;
                foreach ($visitasComp as $dia => $fotos):
                    $visitaNum++;
                    $fechaDia = date('d/m/Y', strtotime($dia));
                    $opVisita = htmlspecialchars($fotos[0]['usuario_nombre']);
                    usort($fotos, fn($a, $b) => ($a['secuencia_comparativa'] ?? 0) - ($b['secuencia_comparativa'] ?? 0));

                    if ($visitaNum > 1): ?>
            </div>
            <div class="page-break"></div>
            <div class="section">
                <h2><i class="bi bi-layers me-1"></i> Fotos Comparativas (cont.)</h2>
                    <?php endif; ?>

                <h3>Visita <?= $visitaNum ?> &mdash; <?= $fechaDia ?> &mdash; Operador: <?= $opVisita ?></h3>

                <?php
                $pares = array_chunk($fotos, 2);
                foreach ($pares as $par):
                ?>
                <div class="comp-pair">
                    <?php foreach ($par as $f):
                        $seq = $f['secuencia_comparativa'] ?? '?';
                        $url = htmlspecialchars($f['url_cloudinary']);
                        $estadoF = strtoupper($f['estado_incidencia']);
                        $badgeF = "badge-{$f['estado_incidencia']}";
                    ?>
                    <div class="comp-col">
                        <div class="comp-label">W<?= $seq ?> &mdash; <span class="<?= $badgeF ?>"><?= $estadoF ?></span></div>
                        <img src="<?= $url ?>" alt="W<?= $seq ?>" loading="lazy">
                        <div class="comp-gps">GPS: <?= $f['lat_real'] ?>, <?= $f['lon_real'] ?></div>
                        <?php if (!empty($f['observaciones'])): ?>
                            <div style="text-align:center;font-size:10px;color:#666;margin-top:2px;"><?= htmlspecialchars(mb_substr($f['observaciones'], 0, 80)) ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php if (count($par) === 1): ?><div class="comp-col"></div><?php endif; ?>
                </div>
                <?php endforeach; ?>

                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- 7. Fotos Aleatorias -->
            <?php if (!empty($aleatorias)): ?>
            <div class="page-break"></div>
            <div class="section">
                <h2><i class="bi bi-camera me-1"></i> Fotos Aleatorias</h2>
                <?php
                $fotosAlea = array_values($aleatorias);
                foreach ($fotosAlea as $idx => $reg):
                    if ($idx > 0 && $idx % 4 === 0):
                ?>
            </div>
            <div class="page-break"></div>
            <div class="section">
                <h2><i class="bi bi-camera me-1"></i> Fotos Aleatorias (cont.)</h2>
                <?php endif;
                    $fecha = date('d/m/Y H:i', strtotime($reg['fecha']));
                    $operador = htmlspecialchars($reg['usuario_nombre']);
                    $estado = strtoupper($reg['estado_incidencia']);
                    $badgeClass = "badge-{$reg['estado_incidencia']}";
                    $url = htmlspecialchars($reg['url_cloudinary']);
                    $uo = !empty($reg['unidad_obra_nombre']) ? ' | U.Obra: ' . htmlspecialchars($reg['unidad_obra_nombre']) : '';
                ?>
                <div class="foto-block">
                    <div class="foto-meta">
                        <strong><?= $fecha ?></strong> &mdash; <?= $operador ?>
                        &mdash; <span class="<?= $badgeClass ?>"><?= $estado ?></span>
                        &mdash; GPS: <?= $reg['lat_real'] ?>, <?= $reg['lon_real'] ?><?= $uo ?>
                    </div>
                    <img src="<?= $url ?>" alt="Inspección" loading="lazy">
                    <?php if (!empty($reg['observaciones'])): ?>
                        <div style="font-size:11px;color:#555;margin-top:6px;padding:6px 10px;background:#f8f9fa;border-radius:4px;">
                            <i class="bi bi-chat-text"></i> <?= htmlspecialchars($reg['observaciones']) ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- 8. Detalle completo de registros -->
            <div class="page-break"></div>
            <div class="section">
                <h2><i class="bi bi-table me-1"></i> Detalle de Registros</h2>
                <?php if (empty($registros)): ?>
                    <p style="color:#888;font-style:italic;">No hay registros para esta infraestructura.</p>
                <?php else: ?>
                <table class="data">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Operador</th>
                            <th>Tipo</th>
                            <th>Situación</th>
                            <th>GPS Real</th>
                            <th>U. Obra</th>
                            <th style="text-align:left;">Observaciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($registros as $reg):
                        $fecha = date('d/m/Y H:i', strtotime($reg['fecha']));
                        $operador = htmlspecialchars($reg['usuario_nombre']);
                        $gps = $reg['lat_real'] . ', ' . $reg['lon_real'];
                        $estado = $reg['estado_incidencia'];
                        $tipo = ($reg['tipo_foto'] ?? 'aleatorio') === 'comparativo' ? 'COMP' : 'ALEA';
                        $tipoClass = ($reg['tipo_foto'] ?? 'aleatorio') === 'comparativo' ? 'badge-comp' : 'badge-alea';
                        $obs = htmlspecialchars(mb_substr($reg['observaciones'] ?? '-', 0, 80));
                        $uo = htmlspecialchars($reg['unidad_obra_nombre'] ?? '-');
                    ?>
                        <tr>
                            <td><?= $fecha ?></td>
                            <td><?= $operador ?></td>
                            <td class="<?= $tipoClass ?>"><?= $tipo ?></td>
                            <td class="badge-<?= $estado ?>"><?= strtoupper($estado) ?></td>
                            <td style="font-size:9px;"><?= $gps ?></td>
                            <td><?= $uo ?></td>
                            <td style="text-align:left;"><?= $obs ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <!-- Footer -->
            <div class="report-footer">
                FotoGPS.app &mdash; Informe generado automáticamente &mdash; <?= $fechaGeneracion ?>
            </div>
        </div><!-- /report -->

        <?php endif; ?>
    </div><!-- /page-content -->

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <?php if ($infra && $infra['lat_teorica'] && $infra['lon_teorica']): ?>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
    (function() {
        var lat = <?= (float)$infra['lat_teorica'] ?>;
        var lon = <?= (float)$infra['lon_teorica'] ?>;

        var map = L.map('report-map', {
            zoomControl: true,
            attributionControl: true,
        }).setView([lat, lon], 15);

        // Capa base: PNOA ortofoto
        var pnoa = L.tileLayer.wms('https://www.ign.es/wms-inspire/pnoa-ma', {
            layers: 'OI.OrthoimageCoverage',
            format: 'image/png',
            transparent: false,
            attribution: '&copy; IGN España - PNOA',
            maxZoom: 20,
        });

        var osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OSM', maxZoom: 19,
        });

        osm.addTo(map);
        L.control.layers({ 'OpenStreetMap': osm, 'Ortofoto PNOA': pnoa }).addTo(map);

        // Marcador infraestructura
        var icon = L.divIcon({
            className: 'report-marker',
            html: '<div style="width:20px;height:20px;border-radius:50%;background:#dc2626;border:3px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,0.4);"></div>',
            iconSize: [20, 20],
            iconAnchor: [10, 10],
        });
        L.marker([lat, lon], { icon: icon })
            .bindPopup('<strong><?= addslashes(htmlspecialchars($infra['nombre'])) ?></strong><br><code><?= addslashes(htmlspecialchars($infra['codigo_unico'])) ?></code>')
            .addTo(map);

        // Marcadores de fotos
        var photosBounds = [[lat, lon]];
        <?php foreach ($registros as $r): ?>
        <?php if ($r['lat_real'] && $r['lon_real']): ?>
        (function() {
            var fLat = <?= (float)$r['lat_real'] ?>;
            var fLon = <?= (float)$r['lon_real'] ?>;
            var color = <?= json_encode(['antes' => '#3b82f6', 'durante' => '#f59e0b', 'despues' => '#22c55e'][$r['estado_incidencia']] ?? '#9ca3af') ?>;
            var fIcon = L.divIcon({
                className: 'photo-dot',
                html: '<div style="width:8px;height:8px;border-radius:50%;background:' + color + ';border:1.5px solid #fff;box-shadow:0 1px 3px rgba(0,0,0,0.3);"></div>',
                iconSize: [8, 8], iconAnchor: [4, 4],
            });
            L.marker([fLat, fLon], { icon: fIcon }).addTo(map);
            photosBounds.push([fLat, fLon]);
        })();
        <?php endif; ?>
        <?php endforeach; ?>

        // Fit bounds to include all markers
        if (photosBounds.length > 1) {
            map.fitBounds(photosBounds, { padding: [30, 30], maxZoom: 17 });
        }

        // Scale bar
        L.control.scale({ metric: true, imperial: false, position: 'bottomleft' }).addTo(map);

        // Update scale info text
        function updateScaleInfo() {
            var center = map.getCenter();
            var zoom = map.getZoom();
            var metersPerPixel = 40075016.686 * Math.abs(Math.cos(center.lat * Math.PI / 180)) / Math.pow(2, zoom + 8);
            var mapWidth = map.getSize().x;
            var totalMeters = metersPerPixel * mapWidth;
            var scaleText;
            if (totalMeters > 1000) {
                scaleText = (totalMeters / 1000).toFixed(1) + ' km';
            } else {
                scaleText = Math.round(totalMeters) + ' m';
            }
            document.getElementById('map-scale-info').textContent =
                'Coordenadas: ' + lat.toFixed(7) + ', ' + lon.toFixed(7) + ' (ETRS89) — Ancho de mapa: ~' + scaleText + ' — Zoom: ' + zoom;
        }
        map.on('zoomend moveend', updateScaleInfo);
        updateScaleInfo();
    })();
    </script>
    <?php endif; ?>

</body>
</html>
