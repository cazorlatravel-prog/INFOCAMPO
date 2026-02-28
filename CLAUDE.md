# CLAUDE.md — INFOCAMPO

## Project Overview

INFOCAMPO is a multi-tenant SaaS platform for field infrastructure inspections. It enables field operators to capture geolocated photos with GPS watermarks, company admins to manage infrastructure and generate reports, and super admins to manage the entire platform including companies, licenses, and dynamic form configurations.

**Language:** Spanish (UI, database columns, comments). All code identifiers and API responses use Spanish terminology.

## Tech Stack

- **Backend:** PHP 8.1+ (no framework, vanilla PHP with PDO)
- **Database:** MySQL 8.0+ with PDO prepared statements
- **Frontend:** Vanilla JavaScript ES6+, Bootstrap 5.3.3, Leaflet 1.9.4 (maps)
- **PDF generation:** DOMPDF ^2.0 (via Composer)
- **Image hosting:** Cloudinary API (with local storage fallback)
- **Offline support:** Service Workers + localStorage
- **Dependencies:** Composer (`composer install` to set up)

## Repository Structure

```
/
├── index.html              # Landing page (marketing/SaaS homepage)
├── composer.json            # PHP dependencies (dompdf, ext-pdo, ext-curl)
├── .env.example             # Environment variable template
├── plan.md                  # Project roadmap and feature specifications
│
├── admin/                   # Company admin panel (admin/supervisor roles)
│   ├── login.php            # Admin authentication
│   ├── dashboard.php        # Stats overview (infrastructure, operators, incidents)
│   ├── index.php            # Timeline view of inspections
│   ├── infraestructuras.php # Infrastructure CRUD
│   ├── unidades_obra.php    # Work unit management
│   ├── usuarios.php         # Company user management
│   ├── mapa.php             # Leaflet interactive map
│   ├── descargar_fotos.php  # ZIP download of photos
│   ├── generar_pdf.php      # PDF report generation (DOMPDF)
│   └── includes/header.php  # Shared navigation header
│
├── public/                  # Field operator mobile-first web app
│   ├── login.php            # Operator authentication
│   ├── operador.php         # Main operator interface (3-screen app)
│   ├── subir.php            # Photo upload endpoint (Cloudinary/local)
│   ├── sw.js                # Service Worker for offline
│   ├── api/                 # JSON API endpoints
│   │   ├── campos.php             # Dynamic form fields by company
│   │   ├── infraestructuras.php   # Infrastructure search/create + provinces/municipalities
│   │   ├── ultima_foto.php        # Last photo for ghost overlay
│   │   ├── unidades_obra.php      # Work unit listing
│   │   ├── registros_mapa.php     # Map records
│   │   └── fotos_comparativas.php # Comparative photo sequences
│   ├── css/
│   │   ├── operador.css     # Operator app styles
│   │   └── camera.css       # Camera interface styles
│   └── js/
│       ├── operador.js      # Main operator app (~1400 lines, state machine)
│       ├── camera.js         # Camera + GPS + ghost overlay (~200 lines)
│       ├── upload.js         # Dynamic fields + upload logic (~150 lines)
│       ├── offline.js        # Offline sync queue
│       ├── watermark.js      # Canvas GPS watermark rendering
│       └── haversine.js      # GPS distance calculation
│
├── superadmin/              # Platform-wide super admin panel
│   ├── login.php            # Super admin authentication
│   ├── index.php            # Global dashboard with metrics
│   ├── empresas.php         # Company CRUD + license management
│   ├── usuarios.php         # Global user management
│   ├── campos.php           # Dynamic form field builder per company
│   ├── impersonate.php      # User impersonation for support
│   └── logout.php           # Logout endpoint
│
├── includes/                # Shared PHP utilities
│   ├── config.php           # PDO connection singleton + env parsing
│   ├── auth.php             # Session auth, CSRF, role checks, impersonation
│   └── cloudinary_helper.php # Cloudinary upload abstraction
│
└── database/                # Schema and migrations
    ├── schema.sql           # v1 initial schema
    ├── schema_v2.sql        # v2: superadmin + licenses + dynamic fields
    ├── schema_v3.sql        # v3: work units + comparative photos
    ├── schema_v4.sql        # v4: province/municipality columns
    ├── migrate.php          # Web-based migration runner
    ├── fix_superadmin.php   # Superadmin setup script
    └── reset_superadmin.php # Password reset utility
```

## Architecture

### Multi-Tenant Model

Three user tiers with strict data isolation by `empresa_id`:

| Role | Access | Panel |
|------|--------|-------|
| `superadmin` | Full platform control, impersonation | `/superadmin/` |
| `admin` | Company infrastructure, users, reports | `/admin/` |
| `supervisor` | Read-only admin access | `/admin/` |
| `operador` | Photo capture and form submission | `/public/operador.php` |

### Authentication & Security

- **Session-based auth** with `$_SESSION['user_id']`, `$_SESSION['user_role']`, `$_SESSION['empresa_id']`
- **Role gating:** `requireRole('admin')` or `requireRole(['admin', 'supervisor'])` at top of each page
- **CSRF protection:** Token in hidden form fields, validated via `validateCsrf()`, also via `X-CSRF-Token` header
- **Passwords:** bcrypt with cost 12: `password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12])`
- **SQL injection prevention:** All queries use PDO prepared statements with named parameters
- **Impersonation:** Superadmin-only, stores original session for restoration

### Database Tables

| Table | Purpose |
|-------|---------|
| `empresas` | Tenant companies with subscription plans and license limits |
| `usuarios` | Users (all roles) scoped to empresa_id |
| `infraestructuras` | Infrastructure assets to inspect, with GPS coordinates |
| `registros` | Inspection records (photos, GPS, severity, observations) |
| `campos_formulario` | Dynamic form fields configured per company |
| `valores_campo` | Field values stored per inspection record |
| `unidades_obra` | Work unit classifications per company |

### API Conventions

All API endpoints under `/public/api/` follow this pattern:

- **GET** for reads, **POST** for creates/updates
- JSON responses: `{ "ok": true, "data": ... }` or `{ "ok": false, "error": "message" }`
- File uploads via `multipart/form-data` to `/public/subir.php`
- Query parameters for filtering: `empresa_id`, `infra_id`, `q` (search), `action` (sub-routes)

### Operator App Flow (3 screens)

1. **Ficha** — Select province > municipality > infrastructure > work unit; fill observations and dynamic fields
2. **Camera** — Live video feed with real-time GPS, distance indicator, ghost overlay from previous photo, incidence level toggle
3. **Preview** — Captured image with watermark, final observations, submit or retake

## Development Setup

```bash
# 1. Clone and install PHP dependencies
composer install

# 2. Configure environment
cp .env.example .env
# Edit .env with DB credentials and Cloudinary keys

# 3. Initialize database
# Open database/migrate.php in browser to run all migrations

# 4. Create superadmin account
# Run database/fix_superadmin.php in browser

# 5. Serve with any PHP-capable server
php -S localhost:8000
```

### Required Environment Variables

```
DB_HOST=localhost
DB_NAME=infocampo_saas
DB_USER=root
DB_PASS=
CLOUDINARY_CLOUD_NAME=
CLOUDINARY_API_KEY=
CLOUDINARY_API_SECRET=
APP_URL=http://localhost
```

## Coding Conventions

### PHP

- `declare(strict_types=1);` at the top of every PHP file
- PDO with named parameters (`:id`, `:empresa_id`) — never concatenate SQL
- `requireRole()` call at the top of every protected page
- CSRF validation on all POST handlers
- JSON responses via `echo json_encode([...])` with `Content-Type: application/json`
- Error responses set appropriate HTTP status codes

### JavaScript

- Vanilla ES6+ — no build tools, no transpilation, no npm
- Module pattern with IIFEs: `const Module = (() => { ... return { init }; })()`
- `fetch()` API for all AJAX calls (no jQuery/axios)
- `async/await` for asynchronous operations
- camelCase for variables and functions

### CSS

- Bootstrap 5 for admin panels
- Custom CSS for operator mobile app
- Mobile-first responsive design with `@media` breakpoints
- No preprocessors (no Sass/Less)

### Naming

- **Database columns:** snake_case in Spanish (`empresa_id`, `estado_incidencia`, `url_cloudinary`)
- **PHP variables:** snake_case (`$empresa_id`, `$user_role`)
- **JS variables:** camelCase (`infraId`, `empresaId`, `ghostActive`)
- **PHP classes:** PascalCase (`CloudinaryHelper`)
- **URL slugs:** snake_case (`unidades_obra`, `campos_formulario`)

### File Organization

- Each panel (`admin/`, `public/`, `superadmin/`) is self-contained with its own login
- Shared code lives in `includes/` only
- API endpoints live under `public/api/`
- Database migrations are sequential SQL files in `database/`
- No MVC framework — each PHP file handles its own routing, logic, and rendering

## Important Patterns to Follow

1. **Always scope queries by `empresa_id`** — Never expose data across tenants
2. **Always use prepared statements** — No raw SQL string concatenation
3. **Always validate CSRF on POST** — Call `validateCsrf()` before processing
4. **Always check roles** — Call `requireRole()` at the top of protected pages
5. **Keep JavaScript vanilla** — No frameworks, no build steps, no npm dependencies
6. **Inline templates** — PHP files mix HTML and PHP (no template engine)
7. **Bootstrap for admin UI** — Use Bootstrap 5 components and grid for admin panels
8. **Mobile-first for operator** — The operator app targets phone browsers with camera access

## Testing

No automated test framework is configured. Testing is manual via browser. When making changes:

- Verify all three panels load correctly (`/admin/`, `/public/`, `/superadmin/`)
- Test API endpoints return valid JSON
- Test photo upload flow end-to-end
- Verify multi-tenant isolation (data from company A should never appear for company B)
- Check responsive layout on mobile viewport sizes

## Database Migrations

Migrations are incremental SQL files executed in order via `database/migrate.php`. When adding schema changes:

1. Create a new `database/schema_v{N}.sql` file
2. Use `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` or `CREATE TABLE IF NOT EXISTS` for idempotency
3. Update `database/migrate.php` to include the new file
4. Document the changes in the SQL file header
