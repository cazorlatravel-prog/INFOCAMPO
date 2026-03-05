<?php
/**
 * INFOCAMPO SaaS - Redirect al Dashboard Unificado
 *
 * El dashboard de superadmin ahora es /admin/dashboard.php
 */
declare(strict_types=1);

header('Location: /admin/dashboard.php');
exit;
