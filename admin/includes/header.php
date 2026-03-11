<?php
/**
 * INFOCAMPO SaaS - Header compartido para el panel de administracion
 *
 * Incluir al inicio de cada pagina admin, despues de cargar auth.php y obtener $empresaId.
 * Variables esperadas: $empresaId, $currentPage (string con nombre de la pagina activa)
 */

// Detectar si estamos en modo suplantacion
$impersonating = isImpersonating();

// Determinar empresa para la navegacion
$_navEmpresaId = $empresaId ?? ($_SESSION['empresa_id'] ?? 0);
$_navEmpresaNombre = $_SESSION['empresa_nombre'] ?? '';
$_navUserName = $_SESSION['user_name'] ?? '';
$_navUserRol = $_SESSION['user_rol'] ?? '';
$currentPage = $currentPage ?? '';

// Cargar contadores para badge de alertas (registros "durante" ultimas 24h)
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

<!-- Skip to content (accessibility) -->
<a href="#main-content" class="skip-link">Ir al contenido</a>

<!-- Banner: Instalar App (PWA) -->
<div id="admin-install-banner" class="admin-install-banner" style="display:none;">
    <div class="admin-install-banner-inner">
        <i class="bi bi-phone-fill"></i>
        <span><strong>Instala FotoGPS</strong> en tu dispositivo para acceso rapido</span>
        <button type="button" id="btn-admin-install" class="admin-install-btn">
            <i class="bi bi-download"></i> Instalar App
        </button>
        <button type="button" id="btn-admin-install-dismiss" class="admin-install-dismiss" aria-label="Cerrar">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>
</div>

<?php if ($impersonating): ?>
<div class="impersonate-bar" style="background:linear-gradient(90deg,#f59e0b,#d97706);color:#fff;padding:10px 20px;display:flex;align-items:center;justify-content:space-between;font-size:0.85rem;position:sticky;top:0;z-index:9999;">
    <div>
        <i class="bi bi-eye" style="margin-right:6px;"></i>
        Viendo como: <strong><?= htmlspecialchars($_SESSION['user_name']) ?></strong>
        (<?= htmlspecialchars($_SESSION['user_rol']) ?> - <?= htmlspecialchars($_SESSION['empresa_nombre']) ?>)
    </div>
    <a href="/superadmin/impersonate.php?stop=1" class="btn btn-sm btn-light fw-semibold" style="color:#92400e;">
        <i class="bi bi-box-arrow-left"></i> Volver
    </a>
</div>
<?php endif; ?>

<!-- Header -->
<div class="brand-bar d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-2 gap-md-3">
        <h1 class="mb-0" style="font-size:1.15rem;font-weight:700;color:#fff;">FotoGPS<span style="opacity:0.5;font-weight:400;">.app</span></h1>
        <?php if ($_navEmpresaNombre): ?>
            <span class="badge d-none d-sm-inline-block" style="background:rgba(255,255,255,0.15);font-size:0.72rem;padding:4px 10px;border-radius:8px;">
                <?= htmlspecialchars($_navEmpresaNombre) ?>
            </span>
        <?php endif; ?>
    </div>
    <div class="d-flex align-items-center gap-2 gap-md-3">
        <?php if ($_navEmpresaId > 0): ?>
        <!-- Busqueda global (oculta en movil) -->
        <div class="position-relative d-none d-lg-block" id="global-search-wrapper" style="width:260px;">
            <div class="input-group input-group-sm">
                <span class="input-group-text" style="background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.2);color:rgba(255,255,255,0.6);">
                    <i class="bi bi-search"></i>
                </span>
                <input type="text" id="global-search-input" class="form-control form-control-sm"
                       placeholder="Buscar..."
                       autocomplete="off"
                       data-empresa-id="<?= $_navEmpresaId ?>"
                       style="background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.2);color:#fff;font-size:0.8rem;"
                       aria-label="Busqueda global">
                <span class="input-group-text d-none d-xl-flex" style="background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.2);color:rgba(255,255,255,0.4);font-size:0.6rem;padding:2px 6px;">
                    Ctrl+K
                </span>
            </div>
            <div id="global-search-results" class="position-absolute w-100 mt-1" style="display:none;z-index:9999;max-height:400px;overflow-y:auto;background:#fff;border-radius:10px;box-shadow:0 8px 30px rgba(0,0,0,0.2);"></div>
        </div>
        <?php endif; ?>

        <?php if ($_alertCount > 0): ?>
            <a href="index.php?empresa_id=<?= $_navEmpresaId ?>" class="position-relative" style="color:#fff;text-decoration:none;" title="En progreso (24h)" aria-label="<?= $_alertCount ?> alertas activas">
                <i class="bi bi-bell-fill" style="font-size:1.1rem;"></i>
                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:0.55rem;">
                    <?= $_alertCount ?>
                </span>
            </a>
        <?php endif; ?>

        <div class="d-none d-sm-flex align-items-center gap-2">
            <i class="bi bi-person-circle" style="font-size:1.1rem;opacity:0.7;color:#fff;"></i>
            <div>
                <div class="small fw-semibold" style="color:#fff;line-height:1.2;font-size:0.8rem;"><?= htmlspecialchars($_navUserName) ?></div>
                <div style="font-size:0.6rem;color:rgba(255,255,255,0.5);"><?= ucfirst($_navUserRol) ?></div>
            </div>
        </div>

        <?php if (!$impersonating): ?>
            <a href="/login.php?logout=1" class="btn btn-sm" style="background:rgba(255,255,255,0.1);color:rgba(255,255,255,0.7);font-size:0.72rem;border:1px solid rgba(255,255,255,0.15);padding:5px 10px;" aria-label="Cerrar sesion">
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

    function escHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
    }

    async function doSearch(q) {
        try {
            const resp = await fetch('api/buscar.php?empresa_id=' + empresaId + '&q=' + encodeURIComponent(q));
            const data = await resp.json();
            if (!data.ok || data.results.length === 0) {
                searchResults.innerHTML = '<div style="padding:16px;text-align:center;color:#9ca3af;font-size:0.85rem;"><i class="bi bi-search me-1"></i>Sin resultados para "' + escHtml(q) + '"</div>';
                searchResults.style.display = 'block';
                return;
            }
            let html = '';
            let lastType = '';
            const typeLabels = { infraestructura: 'Infraestructuras', registro: 'Inspecciones', usuario: 'Usuarios' };
            const typeColors = { infraestructura: '#2563eb', registro: '#7c3aed', usuario: '#059669' };
            const allowedIcons = ['bi-building', 'bi-geo-alt', 'bi-person', 'bi-camera', 'bi-image', 'bi-file-earmark'];

            data.results.forEach(function(r) {
                if (r.type !== lastType) {
                    html += '<div style="padding:6px 14px;font-size:0.65rem;text-transform:uppercase;letter-spacing:0.5px;color:#9ca3af;font-weight:700;background:#f9fafb;">' + escHtml(typeLabels[r.type] || r.type) + '</div>';
                    lastType = r.type;
                }
                var safeUrl = escHtml(r.url);
                var safeIcon = allowedIcons.includes(r.icon) ? r.icon : 'bi-search';
                html += '<a href="' + safeUrl + '" style="display:flex;align-items:start;gap:10px;padding:10px 14px;text-decoration:none;color:#1f2937;border-bottom:1px solid #f3f4f6;transition:background 0.1s;" onmouseover="this.style.background=\'#f0f4ff\'" onmouseout="this.style.background=\'transparent\'">';
                html += '<i class="bi ' + safeIcon + '" style="color:' + (typeColors[r.type] || '#6b7280') + ';font-size:1rem;margin-top:2px;"></i>';
                html += '<div style="min-width:0;flex:1;">';
                html += '<div style="font-size:0.85rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escHtml(r.title) + '</div>';
                html += '<div style="font-size:0.72rem;color:#6b7280;">' + escHtml(r.subtitle) + '</div>';
                if (r.extra) html += '<div style="font-size:0.68rem;color:#9ca3af;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escHtml(r.extra) + '</div>';
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
<nav class="nav-admin" aria-label="Navegacion principal">
    <button class="nav-mobile-toggle" onclick="this.nextElementSibling.classList.toggle('show')" aria-expanded="false" aria-controls="main-nav">
        <i class="bi bi-list"></i> Menu
    </button>
    <ul class="nav" id="main-nav" role="menubar">
        <li role="none"><a href="/admin/dashboard.php<?= $_navEmpresaId ? '?empresa_id=' . $_navEmpresaId : '' ?>" class="nav-link <?= $currentPage === 'dashboard' ? 'active' : '' ?>" role="menuitem">
            <i class="bi bi-speedometer2"></i> <span>Dashboard</span>
        </a></li>
        <li role="none"><a href="/admin/index.php<?= $_navEmpresaId ? '?empresa_id=' . $_navEmpresaId : '' ?>" class="nav-link <?= $currentPage === 'infraestructuras' ? 'active' : '' ?>" role="menuitem">
            <i class="bi bi-geo-alt"></i> <span>Infraestructuras</span>
        </a></li>
        <?php if (in_array($_navUserRol, ['admin', 'superadmin'], true)): ?>
        <li role="none"><a href="/admin/tipos_trabajo.php<?= $_navEmpresaId ? '?empresa_id=' . $_navEmpresaId : '' ?>" class="nav-link <?= $currentPage === 'tipos_trabajo' ? 'active' : '' ?>" role="menuitem">
            <i class="bi bi-briefcase"></i> <span>Trabajos</span>
        </a></li>
        <li role="none"><a href="/admin/unidades_obra.php<?= $_navEmpresaId ? '?empresa_id=' . $_navEmpresaId : '' ?>" class="nav-link <?= $currentPage === 'unidades_obra' ? 'active' : '' ?>" role="menuitem">
            <i class="bi bi-tools"></i> <span>Unidades</span>
        </a></li>
        <?php endif; ?>
        <?php if (in_array($_navUserRol, ['admin', 'superadmin'], true)): ?>
        <li role="none"><a href="/admin/usuarios.php<?= $_navEmpresaId ? '?empresa_id=' . $_navEmpresaId : '' ?>" class="nav-link <?= $currentPage === 'usuarios' ? 'active' : '' ?>" role="menuitem">
            <i class="bi bi-people"></i> <span>Usuarios</span>
        </a></li>
        <?php endif; ?>
        <li role="none"><a href="/admin/fotos.php<?= $_navEmpresaId ? '?empresa_id=' . $_navEmpresaId : '' ?>" class="nav-link <?= $currentPage === 'fotos' ? 'active' : '' ?>" role="menuitem">
            <i class="bi bi-images"></i> <span>Fotos</span>
        </a></li>
        <li role="none"><a href="/admin/mapa.php<?= $_navEmpresaId ? '?empresa_id=' . $_navEmpresaId : '' ?>" class="nav-link <?= $currentPage === 'mapa' ? 'active' : '' ?>" role="menuitem">
            <i class="bi bi-map"></i> <span>Mapa</span>
        </a></li>
        <?php if (in_array($_navUserRol, ['admin', 'superadmin'], true)): ?>
        <li role="none"><a href="/admin/campos.php" class="nav-link <?= $currentPage === 'campos' ? 'active' : '' ?>" role="menuitem">
            <i class="bi bi-ui-checks-grid"></i> <span>Campos</span>
        </a></li>
        <?php endif; ?>
        <li role="none"><a href="/admin/informes.php<?= $_navEmpresaId ? '?empresa_id=' . $_navEmpresaId : '' ?>" class="nav-link <?= $currentPage === 'informes' ? 'active' : '' ?>" role="menuitem">
            <i class="bi bi-file-earmark-text"></i> <span>Informes</span>
        </a></li>
        <?php if (in_array($_navUserRol, ['admin', 'superadmin'], true)): ?>
        <li role="none"><a href="/admin/capas_infra.php<?= $_navEmpresaId ? '?empresa_id=' . $_navEmpresaId : '' ?>" class="nav-link <?= $currentPage === 'capas_infra' ? 'active' : '' ?>" role="menuitem">
            <i class="bi bi-layers"></i> <span>Capas</span>
        </a></li>
        <li role="none"><a href="/admin/puntos_mapa.php<?= $_navEmpresaId ? '?empresa_id=' . $_navEmpresaId : '' ?>" class="nav-link <?= $currentPage === 'puntos_mapa' ? 'active' : '' ?>" role="menuitem">
            <i class="bi bi-geo-fill"></i> <span>Puntos</span>
        </a></li>
        <li role="none"><a href="/admin/ajustes.php<?= $_navEmpresaId ? '?empresa_id=' . $_navEmpresaId : '' ?>" class="nav-link <?= $currentPage === 'ajustes' ? 'active' : '' ?>" role="menuitem">
            <i class="bi bi-sliders2"></i> <span>Ajustes</span>
        </a></li>
        <?php endif; ?>
        <?php if ($_navUserRol === 'superadmin'): ?>
        <li class="nav-item" style="position:relative;" role="none">
            <a href="#" class="nav-link <?= in_array($currentPage, ['empresas', 'usuarios_global', 'campos_global', 'impersonate'], true) ? 'active' : '' ?>" onclick="document.getElementById('submenu-plataforma').classList.toggle('d-none');return false;" role="menuitem" aria-haspopup="true">
                <i class="bi bi-gear"></i> <span>Plataforma</span> <i class="bi bi-chevron-down" style="font-size:0.6rem;"></i>
            </a>
            <ul id="submenu-plataforma" class="<?= in_array($currentPage, ['empresas', 'usuarios_global', 'campos_global', 'impersonate'], true) ? '' : 'd-none' ?>" style="list-style:none;padding:0;margin:0;background:rgba(0,0,0,0.03);border-radius:6px;" role="menu">
                <li role="none"><a href="/superadmin/empresas.php" class="nav-link <?= $currentPage === 'empresas' ? 'active' : '' ?>" style="padding-left:28px;font-size:0.82rem;" role="menuitem">
                    <i class="bi bi-building"></i> Empresas
                </a></li>
                <li role="none"><a href="/superadmin/usuarios.php" class="nav-link <?= $currentPage === 'usuarios_global' ? 'active' : '' ?>" style="padding-left:28px;font-size:0.82rem;" role="menuitem">
                    <i class="bi bi-people-fill"></i> Usuarios Globales
                </a></li>
                <li role="none"><a href="/superadmin/campos.php" class="nav-link <?= $currentPage === 'campos_global' ? 'active' : '' ?>" style="padding-left:28px;font-size:0.82rem;" role="menuitem">
                    <i class="bi bi-sliders"></i> Campos Globales
                </a></li>
                <li role="none"><a href="/superadmin/impersonate.php" class="nav-link <?= $currentPage === 'impersonate' ? 'active' : '' ?>" style="padding-left:28px;font-size:0.82rem;" role="menuitem">
                    <i class="bi bi-eye"></i> Impersonar
                </a></li>
            </ul>
        </li>
        <?php endif; ?>
    </ul>
</nav>

<!-- PWA Install Banner Styles & Script -->
<style>
.admin-install-banner {
    background: linear-gradient(90deg, #4f6ef7, #6366f1, #8b5cf6);
    color: #fff;
    padding: 0;
    position: sticky;
    top: 0;
    z-index: 10000;
    animation: pwaSlideDown 0.4s ease-out;
    box-shadow: 0 4px 20px rgba(79, 110, 247, 0.4);
}
@keyframes pwaSlideDown {
    from { transform: translateY(-100%); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}
.admin-install-banner-inner {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 10px 20px;
    flex-wrap: wrap;
    font-size: 0.88rem;
}
.admin-install-banner-inner > i:first-child {
    font-size: 1.2rem;
    animation: pwaPulse 2s infinite;
}
@keyframes pwaPulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.15); }
}
.admin-install-btn {
    background: #fff;
    color: #4f6ef7;
    border: none;
    padding: 7px 18px;
    border-radius: 8px;
    font-size: 0.82rem;
    font-weight: 800;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.15s;
    white-space: nowrap;
}
.admin-install-btn:hover {
    background: #eef2ff;
    transform: scale(1.04);
}
.admin-install-dismiss {
    background: rgba(255,255,255,0.2);
    border: none;
    color: #fff;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 0.7rem;
    transition: background 0.15s;
    flex-shrink: 0;
}
.admin-install-dismiss:hover {
    background: rgba(255,255,255,0.35);
}
@media (max-width: 576px) {
    .admin-install-banner-inner {
        font-size: 0.8rem;
        padding: 8px 12px;
        gap: 8px;
    }
    .admin-install-btn {
        padding: 6px 14px;
        font-size: 0.78rem;
    }
}
</style>
<script>
(function() {
    // Inject manifest link for PWA support
    if (!document.querySelector('link[rel="manifest"]')) {
        var link = document.createElement('link');
        link.rel = 'manifest';
        link.href = '/admin/manifest.json';
        document.head.appendChild(link);
    }

    var banner = document.getElementById('admin-install-banner');
    var btnInstall = document.getElementById('btn-admin-install');
    var btnDismiss = document.getElementById('btn-admin-install-dismiss');
    if (!banner || !btnInstall) return;

    var deferredPrompt = null;

    // Already installed? Don't show
    if (window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true) return;

    // Check if dismissed recently (7 days)
    var dismissed = localStorage.getItem('admin-pwa-install-dismissed');
    if (dismissed && (Date.now() - parseInt(dismissed, 10)) < 7 * 24 * 60 * 60 * 1000) return;

    window.addEventListener('beforeinstallprompt', function(e) {
        e.preventDefault();
        deferredPrompt = e;
        banner.style.display = 'block';
    });

    // iOS detection - show banner with manual instructions
    var isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
    if (isIOS && !window.navigator.standalone) {
        banner.style.display = 'block';
    }

    btnInstall.addEventListener('click', async function() {
        if (deferredPrompt) {
            deferredPrompt.prompt();
            var result = await deferredPrompt.userChoice;
            deferredPrompt = null;
            if (result.outcome === 'accepted') {
                banner.style.display = 'none';
            }
        } else {
            // Fallback instructions
            if (isIOS) {
                alert('Para instalar FotoGPS en tu dispositivo:\n\n1. Pulsa el boton Compartir (cuadrado con flecha)\n2. Pulsa "Añadir a pantalla de inicio"\n3. Confirma pulsando "Añadir"');
            } else {
                alert('Para instalar FotoGPS:\n\n1. Abre el menu del navegador (tres puntos)\n2. Pulsa "Instalar aplicacion" o "Añadir a pantalla de inicio"');
            }
        }
    });

    if (btnDismiss) {
        btnDismiss.addEventListener('click', function() {
            banner.style.display = 'none';
            localStorage.setItem('admin-pwa-install-dismissed', String(Date.now()));
        });
    }
})();
</script>

<div id="main-content">
