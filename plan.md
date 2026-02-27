# Plan: Super Administrador + Licencias + Campos Dinámicos

## Resumen
Implementar un sistema de Super Administrador que gestione empresas, licencias y campos personalizados del formulario de visita.

---

## 1. Cambios en Base de Datos (`database/schema_v2.sql`)

### 1.1 Modificar tabla `usuarios`
- Añadir `superadmin` al ENUM de `rol` → `('superadmin','admin','supervisor','operador')`

### 1.2 Añadir campos de licencia a `empresas`
- `licencia_inicio` DATE — inicio de la licencia
- `licencia_fin` DATE — expiración de la licencia
- `max_usuarios` INT — límite de usuarios por empresa
- `max_infraestructuras` INT — límite de infraestructuras
- `logo_url` VARCHAR(512) — logo de la empresa (opcional)

### 1.3 Nueva tabla `campos_formulario` (campos dinámicos)
```sql
campos_formulario (
    id INT AUTO_INCREMENT,
    empresa_id INT UNSIGNED NOT NULL,  -- a qué empresa pertenece
    nombre VARCHAR(150),               -- nombre visible del campo
    slug VARCHAR(100),                 -- identificador único (snake_case)
    tipo ENUM('texto','numero','select','checkbox','textarea','fecha'),
    opciones JSON,                     -- para select: ["Opción A","Opción B"]
    obligatorio TINYINT(1) DEFAULT 0,
    orden INT DEFAULT 0,               -- orden de aparición
    activo TINYINT(1) DEFAULT 1,
    created_at DATETIME
)
```

### 1.4 Nueva tabla `valores_campo` (valores de campos dinámicos por registro)
```sql
valores_campo (
    id INT AUTO_INCREMENT,
    registro_id INT UNSIGNED NOT NULL,  -- FK a registros
    campo_id INT UNSIGNED NOT NULL,     -- FK a campos_formulario
    valor TEXT,                         -- valor almacenado
    created_at DATETIME
)
```

### 1.5 Seed: crear super administrador por defecto
- Email: `superadmin@infocampo.app` con password hasheado

---

## 2. Sistema de Autenticación (`includes/auth.php`)
- Funciones: `login()`, `logout()`, `requireAuth()`, `requireRole()`
- Sesiones PHP con verificación de rol
- Proteger todas las rutas según rol

---

## 3. Panel Super Administrador (`superadmin/`)

### 3.1 Login (`superadmin/login.php`)
- Formulario de login profesional
- Solo permite rol `superadmin`

### 3.2 Dashboard (`superadmin/index.php`)
- Resumen: total empresas, activas, expiradas, usuarios totales
- Tarjetas con métricas

### 3.3 Gestión de Empresas (`superadmin/empresas.php`)
- **Listar** todas las empresas con estado de licencia
- **Crear** nueva empresa (nombre, NIF, email, plan, fechas licencia, límites)
- **Editar** empresa existente
- **Activar/Desactivar** empresa

### 3.4 Constructor de Campos (`superadmin/campos.php`)
- Seleccionar empresa → ver sus campos personalizados
- **Añadir** nuevo campo (nombre, tipo, opciones, obligatorio, orden)
- **Editar** campos existentes
- **Reordenar** campos (drag & drop o flechas)
- **Eliminar** campo (soft delete)
- Vista previa en tiempo real del formulario

---

## 4. Modificar App de Cámara (`public/`)

### 4.1 Nuevo endpoint `public/api/campos.php`
- GET `?empresa_id=X` → devuelve los campos activos de esa empresa en JSON

### 4.2 Modificar `public/index.html`
- Tras capturar foto, cargar dinámicamente los campos del formulario
- Renderizar cada campo según su tipo (input, select, textarea, etc.)
- Enviar valores junto con la foto

### 4.3 Modificar `public/subir.php`
- Recibir los campos dinámicos además de los fijos
- Guardar valores en `valores_campo`

---

## 5. Archivos a crear/modificar

| Archivo | Acción |
|---------|--------|
| `database/schema_v2.sql` | CREAR - migración incremental |
| `database/migrate.php` | MODIFICAR - incluir v2 |
| `includes/auth.php` | CREAR - sistema de autenticación |
| `superadmin/login.php` | CREAR - login superadmin |
| `superadmin/index.php` | CREAR - dashboard |
| `superadmin/empresas.php` | CREAR - CRUD empresas |
| `superadmin/campos.php` | CREAR - constructor campos |
| `superadmin/logout.php` | CREAR - cerrar sesión |
| `superadmin/api/empresa_save.php` | CREAR - API guardar empresa |
| `superadmin/api/campo_save.php` | CREAR - API guardar campo |
| `superadmin/api/campo_delete.php` | CREAR - API eliminar campo |
| `superadmin/api/campo_reorder.php` | CREAR - API reordenar campos |
| `public/api/campos.php` | CREAR - campos por empresa |
| `public/index.html` | MODIFICAR - formulario dinámico |
| `public/subir.php` | MODIFICAR - guardar campos dinámicos |

---

## 6. Diseño Visual
- Panel superadmin con estilo consistente (Bootstrap 5, mismo gradiente azul)
- Interfaz responsive
- Formularios con validación HTML5 + PHP
- Feedback visual con toasts/alertas Bootstrap
