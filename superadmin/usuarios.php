<?php
/**
 * INFOCAMPO - Redirect a la gestión de usuarios unificada
 *
 * Aplicación single-tenant (TRAGSA): la gestión de usuarios se realiza
 * desde el panel de administración en /admin/usuarios.php.
 */
declare(strict_types=1);

header('Location: /admin/usuarios.php');
exit;
