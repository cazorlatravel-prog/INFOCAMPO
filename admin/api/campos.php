<?php
/**
 * INFOCAMPO - API de Campos de Formulario (Admin)
 *
 * GET ?empresa_id=X            → Lista campos activos
 * GET ?empresa_id=X&export=csv → Exportar campos como CSV
 * POST action=import_csv       → Importar campos desde CSV
 * POST action=create_defaults  → Crear campos por defecto
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
$user = requireRole(['admin', 'supervisor', 'superadmin']);

$pdo = getDB();

header('Content-Type: application/json; charset=utf-8');

$empresaId = getEmpresaIdSeguro();

if ($empresaId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'empresa_id requerido'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------
// GET: listar o exportar
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare(
        "SELECT id, nombre, slug, tipo, opciones, obligatorio, orden
         FROM campos_formulario
         WHERE empresa_id = :emp AND activo = 1
         ORDER BY orden ASC, id ASC"
    );
    $stmt->execute([':emp' => $empresaId]);
    $campos = $stmt->fetchAll();

    // Exportar CSV
    if (isset($_GET['export']) && $_GET['export'] === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="campos_formulario.csv"');

        $out = fopen('php://output', 'w');
        // BOM for Excel UTF-8
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['nombre', 'slug', 'tipo', 'opciones', 'obligatorio', 'orden'], ';');

        foreach ($campos as $c) {
            $opciones = '';
            if ($c['opciones']) {
                $opts = json_decode($c['opciones'], true);
                if (is_array($opts)) $opciones = implode('|', $opts);
            }
            fputcsv($out, [
                $c['nombre'],
                $c['slug'],
                $c['tipo'],
                $opciones,
                $c['obligatorio'] ? 'SI' : 'NO',
                $c['orden'],
            ], ';');
        }
        fclose($out);
        exit;
    }

    // JSON list
    foreach ($campos as &$c) {
        $c['obligatorio'] = (bool) $c['obligatorio'];
        $c['opciones'] = $c['opciones'] ? json_decode($c['opciones'], true) : null;
    }
    unset($c);

    echo json_encode(['ok' => true, 'campos' => $campos], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------
// POST: acciones
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Supervisores no pueden modificar
    if ($_SESSION['user_rol'] === 'supervisor') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Los supervisores no pueden modificar campos'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!validateCsrf()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Token CSRF inválido'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $action = $_POST['action'] ?? '';

    // --- Crear campos por defecto ---
    if ($action === 'create_defaults') {
        $defaults = [
            ['COD Infraestructura', 'cod_infraestructura', 'texto', null, 0, 0],
            ['Nombre Infraestructura', 'nombre_infraestructura', 'texto', null, 0, 1],
            ['Monte', 'monte', 'texto', null, 0, 2],
            ['Municipio', 'municipio', 'texto', null, 0, 3],
            ['Unidad de obra', 'unidad_de_obra', 'texto', null, 0, 4],
            ['Observaciones', 'observaciones_campo', 'textarea', null, 0, 5],
        ];

        $inserted = 0;
        $stmtCheck = $pdo->prepare(
            "SELECT COUNT(*) FROM campos_formulario WHERE empresa_id = :emp AND slug = :slug AND activo = 1"
        );
        $stmtInsert = $pdo->prepare(
            "INSERT INTO campos_formulario (empresa_id, nombre, slug, tipo, opciones, obligatorio, orden)
             VALUES (:emp, :nombre, :slug, :tipo, :opciones, :obligatorio, :orden)"
        );

        foreach ($defaults as $d) {
            $stmtCheck->execute([':emp' => $empresaId, ':slug' => $d[1]]);
            if ((int) $stmtCheck->fetchColumn() === 0) {
                $stmtInsert->execute([
                    ':emp' => $empresaId,
                    ':nombre' => $d[0],
                    ':slug' => $d[1],
                    ':tipo' => $d[2],
                    ':opciones' => $d[3],
                    ':obligatorio' => $d[4],
                    ':orden' => $d[5],
                ]);
                $inserted++;
            }
        }

        echo json_encode(['ok' => true, 'inserted' => $inserted], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- Importar CSV ---
    if ($action === 'import_csv') {
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['ok' => false, 'error' => 'Archivo CSV requerido'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $content = file_get_contents($_FILES['csv_file']['tmp_name']);
        // Remove BOM if present
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $lines = preg_split('/\r?\n/', $content);

        $header = null;
        $imported = 0;
        $errors = [];

        $stmtInsert = $pdo->prepare(
            "INSERT INTO campos_formulario (empresa_id, nombre, slug, tipo, opciones, obligatorio, orden)
             VALUES (:emp, :nombre, :slug, :tipo, :opciones, :obligatorio, :orden)"
        );

        $validTypes = ['texto', 'numero', 'select', 'checkbox', 'textarea', 'fecha'];

        foreach ($lines as $i => $line) {
            $line = trim($line);
            if ($line === '') continue;

            // Try semicolon first, then comma
            $cols = str_getcsv($line, ';');
            if (count($cols) < 2) {
                $cols = str_getcsv($line, ',');
            }

            // First non-empty line is header
            if ($header === null) {
                $header = array_map(fn($h) => strtolower(trim($h)), $cols);
                continue;
            }

            $row = [];
            foreach ($header as $idx => $col) {
                $row[$col] = $cols[$idx] ?? '';
            }

            $nombre = trim($row['nombre'] ?? '');
            if ($nombre === '') {
                $errors[] = "Fila " . ($i + 1) . ": nombre vacío";
                continue;
            }

            $slug = trim($row['slug'] ?? '');
            if ($slug === '') {
                $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT', $nombre) ?: $nombre));
                $slug = trim($slug, '_');
            }

            $tipo = trim($row['tipo'] ?? 'texto');
            if (!in_array($tipo, $validTypes)) $tipo = 'texto';

            $opciones = trim($row['opciones'] ?? '');
            $opcionesJson = null;
            if ($tipo === 'select' && $opciones !== '') {
                $optsArray = array_map('trim', explode('|', $opciones));
                $optsArray = array_filter($optsArray, fn($v) => $v !== '');
                if (!empty($optsArray)) {
                    $opcionesJson = json_encode(array_values($optsArray), JSON_UNESCAPED_UNICODE);
                }
            }

            $obligatorio = in_array(strtoupper(trim($row['obligatorio'] ?? '')), ['SI', 'SÍ', '1', 'TRUE', 'YES']) ? 1 : 0;
            $orden = (int) ($row['orden'] ?? $imported);

            try {
                $stmtInsert->execute([
                    ':emp' => $empresaId,
                    ':nombre' => $nombre,
                    ':slug' => $slug,
                    ':tipo' => $tipo,
                    ':opciones' => $opcionesJson,
                    ':obligatorio' => $obligatorio,
                    ':orden' => $orden,
                ]);
                $imported++;
            } catch (\PDOException $e) {
                if (str_contains($e->getMessage(), 'Duplicate')) {
                    $errors[] = "Fila " . ($i + 1) . ": slug '$slug' duplicado";
                } else {
                    $errors[] = "Fila " . ($i + 1) . ": " . $e->getMessage();
                }
            }
        }

        echo json_encode([
            'ok' => true,
            'imported' => $imported,
            'errors' => $errors,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- Crear campo ---
    if ($action === 'create') {
        $nombre = trim($_POST['nombre'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $tipo = $_POST['tipo'] ?? 'texto';
        if (!in_array($tipo, ['texto', 'numero', 'select', 'checkbox', 'textarea', 'fecha'], true)) {
            $tipo = 'texto';
        }
        $opciones = trim($_POST['opciones'] ?? '');
        $obligatorio = isset($_POST['obligatorio']) ? 1 : 0;
        $orden = (int) ($_POST['orden'] ?? 0);

        if ($slug === '' && $nombre !== '') {
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT', $nombre) ?: $nombre));
            $slug = trim($slug, '_');
        }

        $opcionesJson = null;
        if ($tipo === 'select' && $opciones !== '') {
            $optsArray = array_map('trim', explode("\n", $opciones));
            $optsArray = array_filter($optsArray, fn($v) => $v !== '');
            $opcionesJson = json_encode(array_values($optsArray), JSON_UNESCAPED_UNICODE);
        }

        if ($nombre === '') {
            echo json_encode(['ok' => false, 'error' => 'Nombre obligatorio'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO campos_formulario (empresa_id, nombre, slug, tipo, opciones, obligatorio, orden)
             VALUES (:emp, :nombre, :slug, :tipo, :opciones, :obligatorio, :orden)"
        );
        $stmt->execute([
            ':emp' => $empresaId,
            ':nombre' => $nombre,
            ':slug' => $slug,
            ':tipo' => $tipo,
            ':opciones' => $opcionesJson,
            ':obligatorio' => $obligatorio,
            ':orden' => $orden,
        ]);

        echo json_encode(['ok' => true, 'id' => (int) $pdo->lastInsertId()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- Actualizar campo ---
    if ($action === 'update') {
        $campoId = (int) ($_POST['campo_id'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $tipo = $_POST['tipo'] ?? 'texto';
        if (!in_array($tipo, ['texto', 'numero', 'select', 'checkbox', 'textarea', 'fecha'], true)) {
            $tipo = 'texto';
        }
        $opciones = trim($_POST['opciones'] ?? '');
        $obligatorio = isset($_POST['obligatorio']) ? 1 : 0;
        $orden = (int) ($_POST['orden'] ?? 0);

        if ($slug === '' && $nombre !== '') {
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT', $nombre) ?: $nombre));
            $slug = trim($slug, '_');
        }

        $opcionesJson = null;
        if ($tipo === 'select' && $opciones !== '') {
            $optsArray = array_map('trim', explode("\n", $opciones));
            $optsArray = array_filter($optsArray, fn($v) => $v !== '');
            $opcionesJson = json_encode(array_values($optsArray), JSON_UNESCAPED_UNICODE);
        }

        $stmt = $pdo->prepare(
            "UPDATE campos_formulario SET nombre = :nombre, slug = :slug, tipo = :tipo,
             opciones = :opciones, obligatorio = :obligatorio, orden = :orden
             WHERE id = :id AND empresa_id = :emp"
        );
        $stmt->execute([
            ':nombre' => $nombre,
            ':slug' => $slug,
            ':tipo' => $tipo,
            ':opciones' => $opcionesJson,
            ':obligatorio' => $obligatorio,
            ':orden' => $orden,
            ':id' => $campoId,
            ':emp' => $empresaId,
        ]);

        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- Eliminar campo ---
    if ($action === 'delete') {
        $campoId = (int) ($_POST['campo_id'] ?? 0);
        $pdo->prepare("UPDATE campos_formulario SET activo = 0 WHERE id = :id AND empresa_id = :emp")
            ->execute([':id' => $campoId, ':emp' => $empresaId]);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- Reordenar ---
    if ($action === 'reorder') {
        $order = $_POST['order'] ?? '';
        if ($order) {
            $ids = explode(',', $order);
            foreach ($ids as $pos => $id) {
                $pdo->prepare("UPDATE campos_formulario SET orden = :orden WHERE id = :id AND empresa_id = :emp")
                    ->execute([':orden' => $pos, ':id' => (int) $id, ':emp' => $empresaId]);
            }
        }
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Acción no válida'], JSON_UNESCAPED_UNICODE);
}
