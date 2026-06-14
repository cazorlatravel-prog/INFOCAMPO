<?php
/**
 * FotoGPS - Diagnóstico de conexión a base de datos
 *
 * Sube este archivo a la raíz de tu hosting y ábrelo en el navegador:
 *   https://tu-dominio.com/check_db.php
 *
 * Te dirá si tu hosting puede conectar con Supabase (PostgreSQL).
 *
 * IMPORTANTE: BORRA este archivo del hosting cuando termines de probar.
 */

header('Content-Type: text/html; charset=utf-8');

$pgsql = extension_loaded('pdo_pgsql');
$mysql = extension_loaded('pdo_mysql');
$drivers = PDO::getAvailableDrivers();

?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Diagnóstico BD — FotoGPS</title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; max-width: 640px; margin: 40px auto; padding: 0 20px; color: #1f2937; }
        h1 { font-size: 1.4rem; }
        .row { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-radius: 8px; margin: 8px 0; font-size: 1rem; }
        .ok { background: #dcfce7; color: #166534; }
        .no { background: #fee2e2; color: #991b1b; }
        .info { background: #f1f5f9; color: #334155; }
        code { background: #e2e8f0; padding: 2px 6px; border-radius: 4px; font-size: 0.9em; }
        .note { background: #fef3c7; color: #92400e; padding: 14px 16px; border-radius: 8px; margin-top: 24px; font-size: 0.9rem; }
        .verdict { font-size: 1.1rem; font-weight: 700; padding: 16px; border-radius: 8px; margin: 20px 0; text-align: center; }
    </style>
</head>
<body>
    <h1>🔍 Diagnóstico de base de datos — FotoGPS</h1>

    <div class="row info">
        <strong>Versión de PHP:</strong> <?= htmlspecialchars(PHP_VERSION) ?>
    </div>

    <div class="row <?= $pgsql ? 'ok' : 'no' ?>">
        <?= $pgsql ? '✅' : '❌' ?>
        <strong>pdo_pgsql (PostgreSQL / Supabase):</strong>
        <?= $pgsql ? 'DISPONIBLE' : 'NO disponible' ?>
    </div>

    <div class="row <?= $mysql ? 'ok' : 'no' ?>">
        <?= $mysql ? '✅' : '❌' ?>
        <strong>pdo_mysql (MySQL):</strong>
        <?= $mysql ? 'DISPONIBLE' : 'NO disponible' ?>
    </div>

    <div class="row info">
        <strong>Drivers PDO disponibles:</strong>
        <code><?= htmlspecialchars(implode(', ', $drivers) ?: 'ninguno') ?></code>
    </div>

    <?php if ($pgsql): ?>
        <div class="verdict ok">
            ✅ Puedes conectar FotoGPS directamente a Supabase con <code>DB_DRIVER=pgsql</code>.
            El código actual funciona sin cambios.
        </div>
    <?php else: ?>
        <div class="verdict no">
            ❌ Este hosting NO puede conectar por PDO a PostgreSQL.<br>
            Opciones: seguir con MySQL (<code>DB_DRIVER=mysql</code>) o usar la API REST de Supabase.
        </div>
    <?php endif; ?>

    <div class="note">
        ⚠️ <strong>Seguridad:</strong> borra este archivo (<code>check_db.php</code>) del hosting
        en cuanto termines de revisar el resultado.
    </div>
</body>
</html>
