<?php
/**
 * INFOCAMPO - Redirect al constructor de campos unificado
 *
 * Aplicación single-tenant (TRAGSA): los campos dinámicos del formulario
 * se gestionan desde el panel de administración en /admin/campos.php.
 */
declare(strict_types=1);

header('Location: /admin/campos.php');
exit;
