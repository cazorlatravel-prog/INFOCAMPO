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

// Cargar contadores para badge de alertas (registros "durante" últimas 24h)
$_alertCount = 0;
if ($_navEmpresaId > 0) {
    try {
        $stmtAlert = getDB()->prepare(
            "SELECT COUNT(*) FROM registros r
             INNER JOIN infraestructuras i ON r.infra_id = i.id
             WHERE i.empresa_id = :emp_id
               AND r.estado_incidencia = 'durante'
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
        <?php if ($_navEmpresaId > 0): ?>
        <!-- Búsqueda global -->
        <div class="position-relative" id="global-search-wrapper" style="width:280px;">
            <div class="input-group input-group-sm">
                <span class="input-group-text" style="background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.2);color:rgba(255,255,255,0.6);">
                    <i class="bi bi-search"></i>
                </span>
                <input type="text" id="global-search-input" class="form-control form-control-sm"
                       placeholder="Buscar infraestructuras, registros..."
                       autocomplete="off"
                       data-empresa-id="<?= $_navEmpresaId ?>"
                       style="background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.2);color:#fff;font-size:0.8rem;"
                       aria-label="Búsqueda global">
                <span class="input-group-text d-none d-md-flex" style="background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.2);color:rgba(255,255,255,0.4);font-size:0.65rem;padding:2px 6px;">
                    Ctrl+K
                </span>
            </div>
            <div id="global-search-results" class="position-absolute w-100 mt-1" style="display:none;z-index:9999;max-height:400px;overflow-y:auto;background:#fff;border-radius:10px;box-shadow:0 8px 30px rgba(0,0,0,0.2);"></div>
        </div>
        <?php endif; ?>

        <?php if ($_alertCount > 0): ?>
            <a href="index.php?empresa_id=<?= $_navEmpresaId ?>" class="position-relative" style="color:#fff;text-decoration:none;" title="En progreso (24h)">
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

<!-- Global Search Script -->
<script>
(function() {
    const searchInput = document.getElementById('global-search-input');
    const searchResults = document.getElementById('global-search-results');
    if (!searchInput || !searchResults) return;

    let debounceTimer = null;
    const empresaId = searchInput.dataset.empresaId;

    // Ctrl+K shortcut
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            searchInput.focus();
            searchInput.select();
        }
        if (e.key === 'Escape') {
            searchResults.style.display = 'none';
            searchInput.blur();
        }
    });

    searchInput.addEventListener('input', function() {
        clearTimeout(debounceTimer);
        const q = this.value.trim();
        if (q.length < 2) { searchResults.style.display = 'none'; return; }
        debounceTimer = setTimeout(() => doSearch(q), 300);
    });

    searchInput.addEventListener('focus', function() {
        if (this.value.trim().length >= 2) doSearch(this.value.trim());
    });

    document.addEventListener('click', function(e) {
        if (!document.getElementById('global-search-wrapper').contains(e.target)) {
            searchResults.style.display = 'none';
        }
    });

    async function doSearch(q) {
        try {
            const resp = await fetch('api/buscar.php?empresa_id=' + empresaId + '&q=' + encodeURIComponent(q));
            const data = await resp.json();
            if (!data.ok || data.results.length === 0) {
                searchResults.innerHTML = '<div style="padding:16px;text-align:center;color:#9ca3af;font-size:0.85rem;"><i class="bi bi-search me-1"></i>Sin resultados para "' + q + '"</div>';
                searchResults.style.display = 'block';
                return;
            }
            let html = '';
            let lastType = '';
            const typeLabels = { infraestructura: 'Infraestructuras', registro: 'Inspecciones', usuario: 'Usuarios' };
            const typeColors = { infraestructura: '#2563eb', registro: '#7c3aed', usuario: '#059669' };

            data.results.forEach(function(r) {
                if (r.type !== lastType) {
                    html += '<div style="padding:6px 14px;font-size:0.65rem;text-transform:uppercase;letter-spacing:0.5px;color:#9ca3af;font-weight:700;background:#f9fafb;">' + (typeLabels[r.type] || r.type) + '</div>';
                    lastType = r.type;
                }
                html += '<a href="' + r.url + '" style="display:flex;align-items:start;gap:10px;padding:10px 14px;text-decoration:none;color:#1f2937;border-bottom:1px solid #f3f4f6;transition:background 0.1s;" onmouseover="this.style.background=\'#f0f4ff\'" onmouseout="this.style.background=\'transparent\'">';
                html += '<i class="bi ' + r.icon + '" style="color:' + (typeColors[r.type] || '#6b7280') + ';font-size:1rem;margin-top:2px;"></i>';
                html += '<div style="min-width:0;flex:1;">';
                html += '<div style="font-size:0.85rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + r.title + '</div>';
                html += '<div style="font-size:0.72rem;color:#6b7280;">' + r.subtitle + '</div>';
                if (r.extra) html += '<div style="font-size:0.68rem;color:#9ca3af;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + r.extra + '</div>';
                html += '</div></a>';
            });

            searchResults.innerHTML = html;
            searchResults.style.display = 'block';
        } catch (err) {
            searchResults.style.display = 'none';
        }
    }
})();
</script>

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
        <li><a href="campos.php" class="nav-link <?= $currentPage === 'campos' ? 'active' : '' ?>">
            <i class="bi bi-ui-checks-grid"></i> Campos
        </a></li>
    </ul>
</nav>
