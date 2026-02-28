<?php
/**
 * INFOCAMPO SaaS - Header compartido para el panel de administración
 *
 * Incluir al inicio de cada página admin, después de cargar auth.php y obtener $empresaId.
 * Variables esperadas: $empresaId, $currentPage (string con nombre de la página activa)
 */

// Detectar si estamos en modo suplantación
$impersonating = isImpersonating();

// Determinar empresa para la navegación
$_navEmpresaId = $empresaId ?? ($_SESSION['empresa_id'] ?? 0);
$_navEmpresaNombre = $_SESSION['empresa_nombre'] ?? '';
$_navUserName = $_SESSION['user_name'] ?? '';
$_navUserRol = $_SESSION['user_rol'] ?? '';
$currentPage = $currentPage ?? '';

// Cargar contadores para badge de alertas (incidencias críticas últimas 24h)
$_alertCount = 0;
if ($_navEmpresaId > 0) {
    try {
        $stmtAlert = getDB()->prepare(
            "SELECT COUNT(*) FROM registros r
             INNER JOIN infraestructuras i ON r.infra_id = i.id
             WHERE i.empresa_id = :emp_id
               AND r.estado_incidencia = 'critico'
               AND r.fecha >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        $stmtAlert->execute([':emp_id' => $_navEmpresaId]);
        $_alertCount = (int) $stmtAlert->fetchColumn();
    } catch (Exception $e) {}
}
?>
<?php if ($impersonating): ?>
<div style="background:linear-gradient(90deg,#f59e0b,#d97706);color:#fff;padding:10px 24px;display:flex;align-items:center;justify-content:space-between;font-size:0.9rem;position:sticky;top:0;z-index:9999;">
    <div>
        <i class="bi bi-eye" style="margin-right:6px;"></i>
        Viendo como: <strong><?= htmlspecialchars($_SESSION['user_name']) ?></strong>
        (<?= htmlspecialchars($_SESSION['user_rol']) ?> - <?= htmlspecialchars($_SESSION['empresa_nombre']) ?>)
    </div>
    <a href="/superadmin/impersonate.php?stop=1" class="btn btn-sm btn-light fw-semibold" style="color:#92400e;">
        <i class="bi bi-box-arrow-left"></i> Volver a Super Admin
    </a>
</div>
<?php endif; ?>

<!-- Header -->
<div class="brand-bar d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-3">
        <h1 class="mb-0" style="font-size:1.3rem;font-weight:700;color:#fff;">FotoGPS.app</h1>
        <?php if ($_navEmpresaNombre): ?>
            <span class="badge" style="background:rgba(255,255,255,0.15);font-size:0.75rem;padding:5px 12px;border-radius:8px;">
                <?= htmlspecialchars($_navEmpresaNombre) ?>
            </span>
        <?php endif; ?>
    </div>
    <div class="d-flex align-items-center gap-3">
        <?php if ($_alertCount > 0): ?>
            <a href="index.php?empresa_id=<?= $_navEmpresaId ?>" class="position-relative" style="color:#fff;text-decoration:none;" title="Incidencias críticas (24h)">
                <i class="bi bi-bell-fill" style="font-size:1.1rem;"></i>
                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:0.6rem;">
                    <?= $_alertCount ?>
                </span>
            </a>
        <?php endif; ?>
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-person-circle" style="font-size:1.2rem;opacity:0.7;color:#fff;"></i>
            <div>
                <div class="small fw-semibold" style="color:#fff;line-height:1.2;"><?= htmlspecialchars($_navUserName) ?></div>
                <div style="font-size:0.65rem;color:rgba(255,255,255,0.5);"><?= ucfirst($_navUserRol) ?></div>
            </div>
        </div>
        <?php if (!$impersonating): ?>
            <a href="login.php?logout=1" class="btn btn-sm" style="background:rgba(255,255,255,0.1);color:rgba(255,255,255,0.7);font-size:0.75rem;border:1px solid rgba(255,255,255,0.15);">
                <i class="bi bi-box-arrow-left"></i>
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Navigation -->
<nav class="nav-admin">
    <ul class="nav">
        <li><a href="dashboard.php<?= $_navEmpresaId ? '?empresa_id=' . $_navEmpresaId : '' ?>" class="nav-link <?= $currentPage === 'dashboard' ? 'active' : '' ?>">
            <i class="bi bi-speedometer2"></i> Dashboard
        </a></li>
        <li><a href="index.php<?= $_navEmpresaId ? '?empresa_id=' . $_navEmpresaId : '' ?>" class="nav-link <?= $currentPage === 'infraestructuras' ? 'active' : '' ?>">
            <i class="bi bi-geo-alt"></i> Infraestructuras
        </a></li>
        <li><a href="unidades_obra.php<?= $_navEmpresaId ? '?empresa_id=' . $_navEmpresaId : '' ?>" class="nav-link <?= $currentPage === 'unidades_obra' ? 'active' : '' ?>">
            <i class="bi bi-tools"></i> Unidades de Obra
        </a></li>
        <li><a href="usuarios.php<?= $_navEmpresaId ? '?empresa_id=' . $_navEmpresaId : '' ?>" class="nav-link <?= $currentPage === 'usuarios' ? 'active' : '' ?>">
            <i class="bi bi-people"></i> Usuarios
        </a></li>
        <li><a href="mapa.php<?= $_navEmpresaId ? '?empresa_id=' . $_navEmpresaId : '' ?>" class="nav-link <?= $currentPage === 'mapa' ? 'active' : '' ?>">
            <i class="bi bi-map"></i> Mapa
        </a></li>
    </ul>
</nav>
