<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manual de Usuario - FotoGPS.app</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --bg: #0a1628;
            --bg-card: #111827;
            --bg-glass: rgba(17, 24, 39, 0.7);
            --accent: #3b82f6;
            --accent-light: #60a5fa;
            --green: #22c55e;
            --amber: #f59e0b;
            --purple: #a855f7;
            --text: #f1f5f9;
            --text-muted: #94a3b8;
            --border: rgba(255,255,255,0.08);
            --radius: 16px;
        }

        html { scroll-behavior: smooth; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.7;
            overflow-x: hidden;
        }

        /* ============ COVER ============ */
        .cover {
            position: relative;
            width: 100%;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .cover::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(175deg,
                rgba(15,40,25,0.95) 0%, rgba(20,60,35,0.85) 20%,
                rgba(25,80,45,0.7) 40%, rgba(45,100,60,0.6) 55%,
                rgba(180,140,60,0.5) 70%, rgba(220,160,50,0.4) 80%,
                rgba(240,120,40,0.3) 90%, rgba(200,80,30,0.4) 100%);
            z-index: 1;
        }

        .mountains { position: absolute; bottom: 0; left: 0; right: 0; height: 55%; z-index: 0; }
        .mountain { position: absolute; bottom: 0; width: 100%; }
        .mountain svg { width: 100%; height: auto; display: block; }

        .stars { position: absolute; top: 0; left: 0; right: 0; height: 50%; z-index: 0; }
        .star {
            position: absolute; width: 2px; height: 2px;
            background: #fff; border-radius: 50%; opacity: 0.4;
            animation: twinkle 3s infinite alternate;
        }
        @keyframes twinkle { 0% { opacity: 0.2; } 100% { opacity: 0.7; } }

        .cover-content {
            position: relative; z-index: 10; text-align: center;
            padding: 40px 24px; max-width: 900px;
        }

        .cover-badge {
            display: inline-flex; align-items: center; gap: 8px;
            background: rgba(255,255,255,0.1); backdrop-filter: blur(12px);
            border: 1px solid rgba(255,255,255,0.15); border-radius: 100px;
            padding: 8px 20px; font-size: 0.85rem; font-weight: 500;
            color: rgba(255,255,255,0.85); margin-bottom: 32px;
            letter-spacing: 1px; text-transform: uppercase;
        }
        .cover-badge .dot {
            width: 8px; height: 8px; background: #22c55e; border-radius: 50%;
            animation: pulse-dot 2s infinite;
        }
        @keyframes pulse-dot {
            0%, 100% { box-shadow: 0 0 0 0 rgba(34,197,94,0.6); }
            50% { box-shadow: 0 0 0 6px rgba(34,197,94,0); }
        }

        .cover-icon {
            width: 100px; height: 100px;
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            border-radius: 28px; display: flex; align-items: center; justify-content: center;
            margin: 0 auto 28px;
            box-shadow: 0 20px 40px rgba(59,130,246,0.3), 0 0 0 1px rgba(255,255,255,0.1);
        }
        .cover-icon svg { width: 52px; height: 52px; fill: #fff; }

        .cover-title {
            font-size: clamp(2.5rem, 6vw, 4.5rem); font-weight: 900;
            line-height: 1.05; margin-bottom: 8px; letter-spacing: -1px;
        }
        .cover-title .brand {
            background: linear-gradient(135deg, #60a5fa, #a78bfa, #f472b6);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
        }
        .cover-title .app { font-weight: 400; opacity: 0.5; font-size: 0.6em; }

        .cover-subtitle {
            font-size: clamp(1.2rem, 3vw, 1.8rem); font-weight: 300;
            color: rgba(255,255,255,0.7); margin-bottom: 40px; line-height: 1.4;
        }
        .cover-subtitle strong { font-weight: 600; color: rgba(255,255,255,0.9); }

        .cover-divider {
            width: 80px; height: 3px;
            background: linear-gradient(90deg, transparent, #f59e0b, transparent);
            margin: 0 auto 40px; border-radius: 2px;
        }

        .cover-cards {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px; margin-bottom: 48px;
        }
        .cover-card {
            background: rgba(255,255,255,0.06); backdrop-filter: blur(12px);
            border: 1px solid rgba(255,255,255,0.08); border-radius: 16px;
            padding: 20px 16px; text-align: center; transition: transform 0.3s, background 0.3s;
        }
        .cover-card:hover { transform: translateY(-4px); background: rgba(255,255,255,0.1); }
        .cover-card-icon { font-size: 2rem; margin-bottom: 8px; display: block; }
        .cover-card-title { font-size: 0.95rem; font-weight: 700; margin-bottom: 4px; }
        .cover-card-desc { font-size: 0.78rem; color: rgba(255,255,255,0.5); line-height: 1.3; }

        .cover-footer {
            position: relative; z-index: 10; text-align: center;
            padding: 20px; font-size: 0.8rem; color: rgba(255,255,255,0.3);
        }
        .cover-version {
            display: inline-flex; align-items: center; gap: 6px;
            background: rgba(255,255,255,0.05); border-radius: 100px;
            padding: 4px 14px; font-size: 0.75rem; margin-top: 8px;
        }

        .scroll-indicator {
            position: absolute; bottom: 30px; left: 50%; transform: translateX(-50%);
            z-index: 10; text-align: center; color: rgba(255,255,255,0.4);
            animation: bounce 2s infinite; cursor: pointer;
        }
        @keyframes bounce {
            0%, 100% { transform: translateX(-50%) translateY(0); }
            50% { transform: translateX(-50%) translateY(8px); }
        }

        /* ============ STICKY NAV ============ */
        .manual-nav {
            position: sticky; top: 0; z-index: 100;
            background: rgba(10,15,30,0.85); backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--border); padding: 0;
        }
        .manual-nav-inner {
            max-width: 900px; margin: 0 auto; display: flex;
            align-items: center; gap: 0; overflow-x: auto;
            padding: 0 16px; -ms-overflow-style: none; scrollbar-width: none;
        }
        .manual-nav-inner::-webkit-scrollbar { display: none; }
        .manual-nav-brand {
            font-weight: 700; font-size: 0.9rem; color: var(--accent-light);
            text-decoration: none; white-space: nowrap; padding: 12px 16px 12px 0;
            border-right: 1px solid var(--border); margin-right: 8px;
            display: flex; align-items: center; gap: 6px;
        }
        .manual-nav a.nav-item {
            padding: 12px 14px; font-size: 0.82rem; color: var(--text-muted);
            text-decoration: none; white-space: nowrap; border-bottom: 2px solid transparent;
            transition: color 0.2s, border-color 0.2s;
        }
        .manual-nav a.nav-item:hover,
        .manual-nav a.nav-item.active {
            color: #fff; border-bottom-color: var(--accent);
        }
        .manual-nav .nav-back {
            margin-left: auto; padding: 6px 14px; font-size: 0.8rem;
            color: var(--text-muted); text-decoration: none; white-space: nowrap;
            display: flex; align-items: center; gap: 4px;
        }
        .manual-nav .nav-back:hover { color: #fff; }

        /* ============ MANUAL CONTENT ============ */
        .manual-body {
            max-width: 900px; margin: 0 auto; padding: 48px 24px 80px;
        }

        .manual-body h1 {
            font-size: 2rem; font-weight: 800; color: #fff;
            margin: 60px 0 24px; padding-bottom: 12px;
            border-bottom: 2px solid var(--accent);
            display: flex; align-items: center; gap: 12px;
        }
        .manual-body h1:first-child { margin-top: 0; }
        .manual-body h1 .h-icon {
            width: 40px; height: 40px; border-radius: 12px;
            background: linear-gradient(135deg, var(--accent), #1d4ed8);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.2rem; flex-shrink: 0;
        }

        .manual-body h2 {
            font-size: 1.4rem; font-weight: 700; color: var(--accent-light);
            margin: 40px 0 16px;
        }

        .manual-body h3 {
            font-size: 1.1rem; font-weight: 600; color: #e2e8f0;
            margin: 28px 0 12px;
        }

        .manual-body h4 {
            font-size: 1rem; font-weight: 600; color: var(--text-muted);
            margin: 20px 0 10px;
        }

        .manual-body p {
            margin: 0 0 16px; color: #cbd5e1;
        }

        .manual-body strong { color: #fff; }

        .manual-body a { color: var(--accent-light); text-decoration: none; }
        .manual-body a:hover { text-decoration: underline; }

        .manual-body ul, .manual-body ol {
            margin: 0 0 16px; padding-left: 24px; color: #cbd5e1;
        }
        .manual-body li { margin-bottom: 6px; }
        .manual-body li strong { color: #e2e8f0; }

        .manual-body table {
            width: 100%; border-collapse: collapse; margin: 16px 0 24px;
            font-size: 0.9rem;
        }
        .manual-body thead th {
            background: rgba(59,130,246,0.15); color: var(--accent-light);
            padding: 10px 14px; text-align: left; font-weight: 600;
            border-bottom: 2px solid rgba(59,130,246,0.3);
        }
        .manual-body tbody td {
            padding: 10px 14px; border-bottom: 1px solid var(--border); color: #cbd5e1;
        }
        .manual-body tbody tr:hover { background: rgba(255,255,255,0.03); }

        .manual-body code {
            background: rgba(59,130,246,0.12); color: var(--accent-light);
            padding: 2px 7px; border-radius: 5px; font-size: 0.88em;
            font-family: 'SF Mono', Menlo, Consolas, monospace;
        }

        .manual-body blockquote {
            margin: 16px 0; padding: 14px 20px;
            background: rgba(59,130,246,0.08); border-left: 4px solid var(--accent);
            border-radius: 0 12px 12px 0; color: #93c5fd;
        }
        .manual-body blockquote strong { color: #bfdbfe; }

        .manual-body hr {
            border: none; height: 1px; background: var(--border);
            margin: 48px 0;
        }

        /* FAQ section */
        .faq-item { margin-bottom: 20px; }
        .faq-q {
            font-weight: 600; color: #fff; margin-bottom: 6px;
            display: flex; align-items: flex-start; gap: 8px;
        }
        .faq-q::before {
            content: 'P'; background: var(--accent); color: #fff;
            width: 24px; height: 24px; border-radius: 6px;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.75rem; font-weight: 700; flex-shrink: 0; margin-top: 1px;
        }
        .faq-a { color: #cbd5e1; padding-left: 32px; }
        .faq-a::before {
            content: 'R: '; font-weight: 600; color: var(--green);
        }

        /* Back to top */
        .back-to-top {
            position: fixed; bottom: 24px; right: 24px; z-index: 99;
            width: 44px; height: 44px; border-radius: 50%;
            background: var(--accent); color: #fff; border: none;
            cursor: pointer; display: none; align-items: center; justify-content: center;
            font-size: 1.2rem; box-shadow: 0 4px 20px rgba(59,130,246,0.4);
            transition: transform 0.2s;
        }
        .back-to-top:hover { transform: scale(1.1); }
        .back-to-top.visible { display: flex; }

        /* Footer */
        .manual-footer {
            text-align: center; padding: 32px 24px; color: var(--text-muted);
            font-size: 0.85rem; border-top: 1px solid var(--border);
            max-width: 900px; margin: 0 auto;
        }

        /* Print */
        @media print {
            body { background: #fff; color: #000; }
            .cover { min-height: auto; page-break-after: always; }
            .cover::before, .stars, .mountains { display: none; }
            .manual-nav { display: none; }
            .back-to-top { display: none !important; }
            .manual-body h1 { color: #1e3a5f; border-bottom-color: #1e3a5f; }
            .manual-body h2 { color: #2563eb; }
            .manual-body p, .manual-body li, .manual-body td { color: #333; }
            .manual-body blockquote { background: #f0f7ff; border-left-color: #2563eb; color: #1e40af; }
            .manual-body thead th { background: #e0ecff; color: #1e3a5f; }
            .manual-body tbody td { border-bottom-color: #ddd; color: #333; }
            .cover-content { border: 3px solid #1e3a5f; border-radius: 20px; padding: 60px 40px; }
            .cover-title .brand { -webkit-text-fill-color: #1e3a5f; color: #1e3a5f; }
            .cover-subtitle { color: #444; }
            .cover-card { border: 1px solid #ddd; background: #f9f9f9; }
            .cover-card-title { color: #1e3a5f; }
            .cover-card-desc { color: #666; }
        }

        @media (max-width: 640px) {
            .cover-cards { grid-template-columns: repeat(2, 1fr); }
            .manual-body { padding: 32px 16px 60px; }
            .manual-body h1 { font-size: 1.5rem; }
            .manual-body h2 { font-size: 1.2rem; }
            .manual-body table { font-size: 0.8rem; }
            .manual-body thead th, .manual-body tbody td { padding: 8px 10px; }
        }
    </style>
</head>
<body>

<!-- ========== COVER ========== -->
<div class="cover" id="cover">
    <div class="stars" aria-hidden="true">
        <div class="star" style="top:8%;left:15%;animation-delay:0.2s;"></div>
        <div class="star" style="top:12%;left:45%;animation-delay:1.1s;width:3px;height:3px;"></div>
        <div class="star" style="top:5%;left:72%;animation-delay:0.7s;"></div>
        <div class="star" style="top:18%;left:88%;animation-delay:1.5s;"></div>
        <div class="star" style="top:25%;left:30%;animation-delay:0.4s;width:3px;height:3px;"></div>
        <div class="star" style="top:15%;left:60%;animation-delay:2s;"></div>
        <div class="star" style="top:22%;left:10%;animation-delay:0.9s;"></div>
        <div class="star" style="top:8%;left:92%;animation-delay:1.8s;width:3px;height:3px;"></div>
    </div>

    <div class="mountains" aria-hidden="true">
        <div class="mountain" style="opacity:0.15;">
            <svg viewBox="0 0 1200 300" preserveAspectRatio="none"><path d="M0,300 L0,180 Q100,80 200,140 Q300,60 400,120 Q500,40 600,100 Q700,50 800,130 Q900,70 1000,110 Q1100,60 1200,150 L1200,300 Z" fill="#1a4a2a"/></svg>
        </div>
        <div class="mountain" style="opacity:0.25;">
            <svg viewBox="0 0 1200 280" preserveAspectRatio="none"><path d="M0,280 L0,200 Q150,100 300,160 Q400,90 500,150 Q600,80 700,140 Q850,60 1000,130 Q1100,90 1200,170 L1200,280 Z" fill="#1f5530"/></svg>
        </div>
        <div class="mountain" style="opacity:0.35;">
            <svg viewBox="0 0 1200 240" preserveAspectRatio="none"><path d="M0,240 L0,180 Q200,120 350,170 Q450,110 550,160 Q700,100 850,150 Q950,120 1100,170 L1200,190 L1200,240 Z" fill="#245a35"/></svg>
        </div>
        <div class="mountain" style="opacity:0.5;">
            <svg viewBox="0 0 1200 120" preserveAspectRatio="none"><path d="M0,120 L0,90 Q100,70 200,85 Q300,65 400,80 Q500,60 600,75 Q700,55 800,70 Q900,50 1000,65 Q1100,55 1200,80 L1200,120 Z" fill="#1a3a20"/></svg>
        </div>
    </div>

    <div class="cover-content">
        <div class="cover-badge"><span class="dot"></span> Manual de Usuario</div>

        <div class="cover-icon">
            <svg viewBox="0 0 24 24"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/></svg>
        </div>

        <h1 class="cover-title">
            <span class="brand">FotoGPS</span><span class="app">.app</span>
        </h1>

        <p class="cover-subtitle">
            Plataforma de <strong>recogida de datos en campo</strong><br>
            con fotografia geoposicionada
        </p>

        <div class="cover-divider"></div>

        <div class="cover-cards">
            <div class="cover-card">
                <span class="cover-card-icon">&#128247;</span>
                <div class="cover-card-title">Fotos GPS</div>
                <div class="cover-card-desc">Captura geoposicionada con marca de agua UTM</div>
            </div>
            <div class="cover-card">
                <span class="cover-card-icon">&#127760;</span>
                <div class="cover-card-title">Mapas</div>
                <div class="cover-card-desc">Visualizacion con ortofoto, KML y navegacion</div>
            </div>
            <div class="cover-card">
                <span class="cover-card-icon">&#128202;</span>
                <div class="cover-card-title">Informes</div>
                <div class="cover-card-desc">PDF, CSV, ZIP y GPX por infraestructura</div>
            </div>
            <div class="cover-card">
                <span class="cover-card-icon">&#128274;</span>
                <div class="cover-card-title">Offline</div>
                <div class="cover-card-desc">Trabaja sin conexion, sincroniza al volver</div>
            </div>
        </div>

        <div style="font-size:0.85rem;color:rgba(255,255,255,0.4);line-height:1.6;">
            <div style="margin-bottom:4px;">Gestion y seguimiento fotografico de cortafuegos e infraestructuras forestales</div>
            <div><strong style="color:rgba(255,255,255,0.6);">INFOCAMPO</strong> &mdash; Plataforma SaaS Multi-empresa</div>
        </div>
    </div>

    <div class="cover-footer">
        <div>Marzo 2026</div>
        <div class="cover-version">v2.0 &bull; fotogps.app</div>
    </div>

    <div class="scroll-indicator" onclick="document.getElementById('manual-content').scrollIntoView({behavior:'smooth'})">
        <i class="bi bi-chevron-double-down" style="font-size:1.5rem;"></i>
        <div style="font-size:0.75rem;margin-top:4px;">Leer manual</div>
    </div>
</div>

<!-- ========== NAV ========== -->
<nav class="manual-nav" id="manual-nav">
    <div class="manual-nav-inner">
        <a href="#cover" class="manual-nav-brand"><i class="bi bi-geo-alt-fill"></i> FotoGPS.app</a>
        <a href="#sec-1" class="nav-item">Introduccion</a>
        <a href="#sec-2" class="nav-item">Acceso</a>
        <a href="#sec-3" class="nav-item">Operador</a>
        <a href="#sec-4" class="nav-item">Admin</a>
        <a href="#sec-5" class="nav-item">FAQ</a>
        <a href="/" class="nav-back"><i class="bi bi-arrow-left"></i> Volver</a>
    </div>
</nav>

<!-- ========== MANUAL CONTENT ========== -->
<div class="manual-body" id="manual-content">

    <!-- ===== SECCION 1: INTRODUCCION ===== -->
    <h1 id="sec-1"><span class="h-icon"><i class="bi bi-info-circle-fill"></i></span> 1. Introduccion</h1>

    <h2>1.1 Que es FotoGPS.app</h2>
    <p>FotoGPS.app (INFOCAMPO) es una plataforma SaaS (Software as a Service) disenada para la recogida de datos en campo mediante fotografia geoposicionada. Permite a equipos de trabajo capturar fotos con marca de agua GPS, gestionar infraestructuras, generar informes y supervisar el progreso de inspecciones desde cualquier dispositivo.</p>

    <h2>1.2 Tecnologia utilizada</h2>
    <table>
        <thead><tr><th>Componente</th><th>Tecnologia</th></tr></thead>
        <tbody>
            <tr><td><strong>Backend</strong></td><td>PHP 8.1+ con PDO (sin framework)</td></tr>
            <tr><td><strong>Base de datos</strong></td><td>MySQL 8.0+</td></tr>
            <tr><td><strong>Frontend</strong></td><td>JavaScript ES6+, Bootstrap 5.3.3</td></tr>
            <tr><td><strong>Mapas</strong></td><td>Leaflet 1.9.4 con OpenStreetMap</td></tr>
            <tr><td><strong>GPS</strong></td><td>API de geolocalizacion del navegador (ETRS89/WGS84)</td></tr>
            <tr><td><strong>Coordenadas</strong></td><td>Conversion automatica a UTM</td></tr>
            <tr><td><strong>Camara</strong></td><td>API MediaDevices del navegador (camara trasera del movil)</td></tr>
            <tr><td><strong>Marca de agua</strong></td><td>Renderizado en Canvas HTML5 en tiempo real</td></tr>
            <tr><td><strong>Almacenamiento de fotos</strong></td><td>Cloudinary (nube) con fallback local</td></tr>
            <tr><td><strong>Generacion PDF</strong></td><td>DOMPDF (via Composer)</td></tr>
            <tr><td><strong>Soporte offline</strong></td><td>Service Workers + IndexedDB</td></tr>
            <tr><td><strong>Instalable</strong></td><td>Progressive Web App (PWA)</td></tr>
        </tbody>
    </table>

    <h2>1.3 Que puede hacer la aplicacion</h2>

    <h3>Para operadores de campo</h3>
    <ul>
        <li>Capturar fotos geoposicionadas con marca de agua automatica (coordenadas UTM, fecha, ubicacion, brujula, mini-mapa)</li>
        <li>Dos modos de foto: <strong>Aleatorias</strong> (libres) y <strong>Comparativas</strong> (con overlay fantasma de la foto anterior para replicar el mismo encuadre)</li>
        <li>Anotar fotos antes de enviarlas (circulo rojo + texto senalando incidencias)</li>
        <li>Trabajar sin conexion: las fotos se guardan y sincronizan automaticamente al recuperar cobertura</li>
        <li>Navegar hasta infraestructuras con indicador de distancia en tiempo real y avisos sonoros de proximidad</li>
        <li>Ver mapa interactivo con capas KML, ortofotos y topografico</li>
    </ul>

    <h3>Para administradores</h3>
    <ul>
        <li>Gestionar infraestructuras (crear, importar desde Excel/KML, editar, desactivar)</li>
        <li>Gestionar usuarios (operadores, supervisores, administradores)</li>
        <li>Gestionar unidades de obra y tipos de trabajo</li>
        <li>Ver timeline de inspecciones con filtros por fecha, operador, situacion y tipo</li>
        <li>Ver todas las fotos en un mapa interactivo con marcadores y clusters</li>
        <li>Generar informes PDF por infraestructura</li>
        <li>Descargar fotos en ZIP por infraestructura</li>
        <li>Exportar datos en CSV y waypoints en GPX</li>
        <li>Configurar la marca de agua (elementos visibles, tamano de texto, mini-mapa)</li>
        <li>Configurar que campos ve el operador en su interfaz</li>
        <li>Configurar el formato de nombre de las fotos</li>
        <li>Definir campos de formulario dinamicos personalizados por empresa</li>
        <li>Gestionar capas KML/GeoJSON para visualizar en los mapas</li>
    </ul>

    <h2>1.4 Roles de usuario</h2>
    <table>
        <thead><tr><th>Rol</th><th>Acceso</th><th>Panel</th></tr></thead>
        <tbody>
            <tr><td><strong>Admin</strong></td><td>Gestion completa de la empresa</td><td>Panel de administracion</td></tr>
            <tr><td><strong>Supervisor</strong></td><td>Acceso de consulta al panel admin</td><td>Panel de administracion</td></tr>
            <tr><td><strong>Operador</strong></td><td>Captura de fotos en campo</td><td>App de campo (movil)</td></tr>
        </tbody>
    </table>

    <hr>

    <!-- ===== SECCION 2: ACCESO ===== -->
    <h1 id="sec-2"><span class="h-icon"><i class="bi bi-box-arrow-in-right"></i></span> 2. Acceso a la plataforma</h1>

    <h2>2.1 Inicio de sesion</h2>
    <ol>
        <li>Accede a la URL de la plataforma (ej: <code>https://fotogps.app</code>)</li>
        <li>Introduce tu <strong>email o telefono</strong> y tu <strong>contrasena</strong></li>
        <li>Pulsa <strong>Acceder</strong></li>
        <li>Segun tu rol, seras redirigido al panel correspondiente:
            <ul>
                <li>Operadores: Pantalla de toma de datos</li>
                <li>Administradores/Supervisores: Panel de administracion</li>
            </ul>
        </li>
    </ol>
    <blockquote><strong>Nota:</strong> La contrasena es sensible a mayusculas/minusculas. Si no puedes acceder, contacta con tu administrador.</blockquote>

    <h2>2.2 Cerrar sesion</h2>
    <ul>
        <li><strong>Operadores:</strong> Pulsa en tu avatar (iniciales) en la esquina superior derecha y selecciona "Cerrar sesion"</li>
        <li><strong>Administradores:</strong> Pulsa el icono de cerrar sesion en la barra superior</li>
    </ul>

    <hr>

    <!-- ===== SECCION 3: OPERADOR ===== -->
    <h1 id="sec-3"><span class="h-icon"><i class="bi bi-camera-fill"></i></span> 3. Manual del Operador de Campo</h1>

    <h2>3.1 Instalar la app en el movil (recomendado)</h2>
    <p>FotoGPS.app es una Progressive Web App (PWA) que se puede instalar como una app nativa:</p>

    <h3>Android (Chrome):</h3>
    <ol>
        <li>Abre la web en Chrome</li>
        <li>Aparecera un banner morado con el boton <strong>Instalar</strong></li>
        <li>Pulsa Instalar y confirma</li>
        <li>La app aparecera en tu pantalla de inicio</li>
    </ol>

    <h3>iPhone (Safari):</h3>
    <ol>
        <li>Abre la web en Safari</li>
        <li>Pulsa el icono de compartir (cuadrado con flecha)</li>
        <li>Desplazate y selecciona <strong>Anadir a pantalla de inicio</strong></li>
        <li>Confirma el nombre y pulsa Anadir</li>
    </ol>
    <blockquote>Instalar la app permite acceso rapido y pantalla completa sin barra del navegador.</blockquote>

    <h2>3.2 Pantalla principal: Ficha de Visita</h2>
    <p>Al acceder como operador, veras la pantalla de <strong>Ficha de Visita</strong>. Esta es tu centro de trabajo.</p>

    <h3>Indicador de conexion</h3>
    <ul>
        <li><strong>Punto verde "En linea":</strong> Estas conectado. Las fotos se suben al instante.</li>
        <li><strong>Punto rojo "Sin conexion":</strong> Sin internet. Las fotos se guardan localmente y se sincronizaran cuando vuelvas a tener cobertura.</li>
    </ul>

    <h3>Seleccion de ubicacion (si esta habilitado)</h3>
    <ol>
        <li><strong>Provincia:</strong> Selecciona la provincia donde trabajas. Filtra las infraestructuras disponibles.</li>
        <li><strong>Municipio:</strong> Se actualiza automaticamente segun la provincia seleccionada.</li>
        <li><strong>Monte:</strong> (Opcional) Filtra por monte o zona forestal.</li>
    </ol>

    <h3>Buscar o crear infraestructura</h3>
    <ol>
        <li>Escribe en el campo de busqueda el nombre o codigo de la infraestructura</li>
        <li>Apareceran las opciones que coincidan</li>
        <li>Pulsa sobre una para seleccionarla</li>
        <li>Si no existe, escribe el nombre completo y pulsa <strong>"+ Crear: [nombre]"</strong> para crearla en tu ubicacion GPS actual</li>
    </ol>
    <blockquote>Una vez seleccionada, aparecera resaltada con su nombre y codigo. Pulsa la <strong>X</strong> para deseleccionarla.</blockquote>

    <h3>Campos del formulario</h3>
    <p>Segun la configuracion de tu empresa, podras ver:</p>
    <ul>
        <li><strong>Tipo de trabajo:</strong> Selecciona el tipo de trabajo a realizar</li>
        <li><strong>Unidad de obra:</strong> Selecciona la unidad de obra correspondiente</li>
        <li><strong>Observaciones:</strong> Escribe notas generales de la visita</li>
        <li><strong>Situacion de la obra:</strong> Selecciona ANTES / DURANTE / DESPUES (si esta habilitado)</li>
        <li><strong>Campos dinamicos:</strong> Campos personalizados configurados por tu administrador</li>
    </ul>

    <h2>3.3 Captura de fotos</h2>

    <h3>Modo Aleatorio (Fotos libres)</h3>
    <ol>
        <li>Selecciona una infraestructura</li>
        <li>Pulsa el boton <strong>Fotos Aleatorias</strong></li>
        <li>Se abrira la camara trasera del movil</li>
        <li>Encuadra la foto</li>
        <li>Pulsa el <strong>boton circular</strong> (disparador) en la parte inferior</li>
        <li>Revisa la foto en la pantalla de previsualizacion</li>
        <li>Pulsa <strong>Aceptar</strong> para guardar o <strong>Repetir</strong> para volver a sacar la foto</li>
    </ol>

    <h3>Modo Comparativo (con ghost/fantasma)</h3>
    <p>Este modo permite sacar fotos en la misma posicion exacta que la visita anterior, superponiendo la foto antigua como guia transparente.</p>
    <ol>
        <li>Selecciona una infraestructura</li>
        <li>Pulsa el boton <strong>Fotos Comparativas</strong></li>
        <li>Se abrira la camara con un panel para cargar la foto anterior</li>
        <li>Selecciona la foto anterior de la lista de miniaturas</li>
        <li>Aparecera una imagen semi-transparente superpuesta sobre la camara en vivo</li>
        <li>Usa el <strong>control deslizante de opacidad</strong> (icono del ojo) para ajustar la transparencia del fantasma</li>
        <li>Alinea tu encuadre con la foto anterior</li>
        <li>Pulsa el disparador</li>
        <li>Revisa y acepta la foto</li>
    </ol>
    <blockquote>Las fotos comparativas se numeran como W1, W2, W3... (waypoints). Cada una guarda las coordenadas GPS para posterior descarga en formato GPX.</blockquote>

    <h3>Pantalla de previsualizacion</h3>
    <p>Despues de capturar una foto, veras la previsualizacion con la marca de agua aplicada. Tienes tres opciones:</p>
    <ul>
        <li><strong>Repetir:</strong> Descarta la foto y vuelve a la camara</li>
        <li><strong>Anotar:</strong> Activa el modo de anotacion (ver seccion siguiente)</li>
        <li><strong>Aceptar:</strong> Sube la foto al servidor (o la guarda localmente si no hay conexion)</li>
    </ul>

    <h3>Anotaciones en fotos</h3>
    <p>Permite senalar puntos de interes o incidencias directamente sobre la foto:</p>
    <ol>
        <li>Pulsa <strong>Anotar</strong> en la pantalla de previsualizacion</li>
        <li>Toca el punto de la foto que quieres senalar</li>
        <li>Aparecera un <strong>circulo rojo</strong> en ese punto</li>
        <li>Escribe una descripcion en el campo de texto (ej: "Grieta en poste")</li>
        <li>Ajusta el tamano del circulo con el control deslizante</li>
        <li>Pulsa <strong>Aceptar</strong> para guardar la foto con la anotacion</li>
    </ol>

    <h2>3.4 Galeria de fotos de la visita</h2>
    <p>En la parte inferior de la pantalla principal aparece la seccion <strong>"Fotos de esta visita"</strong> con miniaturas de todas las fotos tomadas:</p>
    <ul>
        <li><strong>ALEA:</strong> Foto aleatoria</li>
        <li><strong>W1, W2...:</strong> Foto comparativa (waypoint)</li>
        <li><strong>Asterisco (*):</strong> Foto pendiente de sincronizar</li>
    </ul>

    <h2>3.5 Finalizar visita</h2>
    <p>Cuando hayas terminado de tomar fotos:</p>
    <ol>
        <li>Pulsa el boton <strong>Finalizar visita</strong> (rojo, parte inferior)</li>
        <li>Se guardara la visita con todas las fotos, observaciones y datos del formulario</li>
        <li>Se reiniciaran todos los campos para empezar una nueva visita</li>
    </ol>
    <blockquote>Si necesitas registrar una visita sin tomar fotos (por ejemplo, si no puedes acceder a la infraestructura), usa el boton <strong>Guardar visita sin foto</strong>.</blockquote>

    <h2>3.6 Mapa de Visitas</h2>
    <p>Pulsa el boton <strong>Mapa de Visitas</strong> para ver un mapa interactivo con:</p>
    <ul>
        <li><strong>Tu ubicacion actual</strong> (marcador azul con anillo pulsante)</li>
        <li><strong>Infraestructuras visitadas</strong> (circulos de colores con numero de fotos)</li>
        <li><strong>Infraestructuras no visitadas</strong> (marcadores grises)</li>
        <li><strong>Capas KML</strong> (limites administrativos u otros datos configurados por el admin)</li>
    </ul>

    <h3>Capas del mapa</h3>
    <p>En la esquina superior izquierda puedes cambiar el tipo de mapa:</p>
    <ul>
        <li><strong>Mapa:</strong> Mapa de calles (OpenStreetMap)</li>
        <li><strong>Ortofoto:</strong> Imagen aerea (IGN Espana PNOA)</li>
        <li><strong>Topografico:</strong> Mapa topografico (IGN Espana)</li>
    </ul>

    <h3>Buscar en el mapa</h3>
    <p>Pulsa el icono de busqueda para filtrar infraestructuras por nombre o codigo.</p>

    <h3>Navegar hasta una infraestructura</h3>
    <ol>
        <li>Pulsa sobre una infraestructura en el mapa</li>
        <li>En el panel de detalle, pulsa <strong>"Ir a esta ubicacion"</strong></li>
        <li>Se activara el modo navegacion:
            <ul>
                <li>Veras la <strong>distancia en tiempo real</strong> hasta el destino</li>
                <li>Una <strong>linea roja discontinua</strong> conecta tu posicion con el objetivo</li>
                <li><strong>Avisos sonoros</strong> de proximidad:
                    <ul>
                        <li>1 pitido a mas de 20m</li>
                        <li>2 pitidos entre 10-20m</li>
                        <li>3 pitidos a menos de 5m</li>
                    </ul>
                </li>
                <li>El color del indicador cambia: gris (lejos) &gt; amarillo (cerca) &gt; azul (muy cerca) &gt; verde (llegaste)</li>
            </ul>
        </li>
        <li>Pulsa <strong>Detener</strong> para desactivar la navegacion</li>
    </ol>

    <h3>Tomar fotos desde el mapa</h3>
    <p>Desde el panel de detalle de una infraestructura, puedes pulsar <strong>Foto</strong> o <strong>Comparativa</strong> para abrir directamente la camara para esa infraestructura.</p>

    <h2>3.7 Mis Visitas</h2>
    <p>Pulsa <strong>Mis Visitas</strong> para ver el historial de tus registros anteriores:</p>
    <ul>
        <li>Las visitas se agrupan por infraestructura</li>
        <li>Cada foto muestra: miniatura, situacion (ANTES/DURANTE/DESPUES), tipo (ALEA/W1) y hora</li>
        <li><strong>Continuar visita:</strong> Carga los datos de una visita anterior para anadir mas fotos</li>
        <li>Pulsa sobre una foto para editar sus datos (situacion, unidad de obra, observaciones)</li>
    </ul>

    <h2>3.8 Trabajo sin conexion (modo offline)</h2>
    <p>FotoGPS.app funciona sin conexion a internet:</p>
    <ol>
        <li><strong>Captura de fotos:</strong> Las fotos se guardan localmente en el dispositivo</li>
        <li><strong>Indicador:</strong> Aparece una barra inferior mostrando "X fotos pendientes"</li>
        <li><strong>Sincronizacion automatica:</strong> Cuando recuperes conexion, las fotos se suben automaticamente</li>
        <li><strong>Sincronizacion manual:</strong> Pulsa el boton <strong>Sincronizar</strong> en la barra de pendientes</li>
        <li><strong>Precarga de fotos:</strong> Antes de ir a zona sin cobertura, puedes precargar las fotos comparativas de una infraestructura con el boton <strong>"Precargar fotos offline"</strong></li>
    </ol>
    <blockquote><strong>Importante:</strong> No borres los datos del navegador mientras tengas fotos pendientes de sincronizar. Si alguna foto falla al subir, se reintentara automaticamente.</blockquote>

    <h2>3.9 Marca de agua en las fotos</h2>
    <p>Cada foto capturada incluye automaticamente una marca de agua con informacion configurable por el administrador:</p>
    <table>
        <thead><tr><th>Elemento</th><th>Ejemplo</th><th>Configurable</th></tr></thead>
        <tbody>
            <tr><td>Fecha y hora</td><td><code>12 mar 2026 11:50:18</code></td><td>Si</td></tr>
            <tr><td>Coordenadas UTM</td><td><code>30S 499752 4196518</code></td><td>Si</td></tr>
            <tr><td>Orientacion</td><td><code>173 S</code></td><td>Si</td></tr>
            <tr><td>Ubicacion</td><td><code>Cazorla, Jaen 23470</code></td><td>Si</td></tr>
            <tr><td>Pais</td><td><code>Espana</code></td><td>Si</td></tr>
            <tr><td>Brujula grafica</td><td>Rosa de los vientos con flecha</td><td>Si</td></tr>
            <tr><td>Codigo infraestructura</td><td><code>TORRE-A42</code></td><td>Si (opcional)</td></tr>
            <tr><td>Situacion</td><td><code>ANTES / DURANTE / DESPUES</code></td><td>Si (opcional)</td></tr>
            <tr><td>Tipo de foto</td><td><code>FOT ALE / FOT COM</code></td><td>Si (opcional)</td></tr>
            <tr><td>Mini-mapa</td><td>Mapa con marcador de ubicacion</td><td>Si (opcional)</td></tr>
        </tbody>
    </table>

    <hr>

    <!-- ===== SECCION 4: ADMINISTRADOR ===== -->
    <h1 id="sec-4"><span class="h-icon"><i class="bi bi-gear-fill"></i></span> 4. Manual del Administrador</h1>

    <h2>4.1 Panel de administracion: vista general</h2>
    <p>Al acceder como administrador, veras el <strong>Dashboard</strong> con un resumen de la actividad de tu empresa.</p>

    <h3>Barra de navegacion</h3>
    <p>La barra superior incluye:</p>
    <ul>
        <li><strong>Logo FotoGPS.app</strong> con nombre de la empresa</li>
        <li><strong>Busqueda global</strong> (Ctrl+K): Busca infraestructuras, inspecciones y usuarios</li>
        <li><strong>Campana de alertas:</strong> Muestra inspecciones en fase "Durante" en las ultimas 24h</li>
        <li><strong>Nombre y rol</strong> del usuario</li>
        <li><strong>Boton Instalar:</strong> Para instalar la PWA en tu dispositivo</li>
        <li><strong>Cerrar sesion</strong></li>
    </ul>

    <p>El menu lateral incluye las siguientes secciones:</p>
    <table>
        <thead><tr><th>Seccion</th><th>Descripcion</th></tr></thead>
        <tbody>
            <tr><td>Dashboard</td><td>Resumen de estadisticas y actividad</td></tr>
            <tr><td>Infraestructuras</td><td>Timeline de inspecciones por infraestructura</td></tr>
            <tr><td>Fotos</td><td>Galeria de fotos</td></tr>
            <tr><td>Mapa</td><td>Mapa interactivo de todos los registros</td></tr>
            <tr><td>Informes</td><td>Generacion de informes</td></tr>
            <tr><td>Trabajos</td><td>Gestion de tipos de trabajo</td></tr>
            <tr><td>Unidades</td><td>Gestion de unidades de obra</td></tr>
            <tr><td>Usuarios</td><td>Gestion de operadores, supervisores y admins</td></tr>
            <tr><td>Campos</td><td>Constructor de campos de formulario dinamicos</td></tr>
            <tr><td>Capas</td><td>Gestion de capas GeoJSON de infraestructuras</td></tr>
            <tr><td>Puntos</td><td>Gestion de puntos personalizados en el mapa</td></tr>
            <tr><td>Ajustes</td><td>Configuracion de la empresa</td></tr>
        </tbody>
    </table>

    <h2>4.2 Dashboard</h2>
    <p>El dashboard muestra:</p>

    <h3>Tarjetas de estadisticas</h3>
    <ul>
        <li><strong>Infraestructuras:</strong> Numero total de infraestructuras activas</li>
        <li><strong>Inspecciones:</strong> Total de registros fotograficos, con tendencia de los ultimos 7 dias</li>
        <li><strong>Operadores activos:</strong> Numero de operadores con cuenta activa</li>
        <li><strong>Durante (24h):</strong> Registros en fase "Durante" en las ultimas 24 horas (resaltado si hay alguno)</li>
    </ul>

    <h3>Grafico de actividad (14 dias)</h3>
    <p>Grafico de barras apiladas mostrando la actividad diaria por situacion:</p>
    <ul>
        <li><strong>Azul:</strong> Fotos "Antes"</li>
        <li><strong>Ambar:</strong> Fotos "Durante"</li>
        <li><strong>Verde:</strong> Fotos "Despues"</li>
    </ul>

    <h3>Ultimas inspecciones</h3>
    <p>Lista de los 10 registros mas recientes con: nombre de infraestructura y codigo, situacion (badge de color), operador y fecha/hora.</p>

    <h3>Registros en curso</h3>
    <p>Tarjetas con los registros en fase "Durante" mas recientes, mostrando infraestructura, operador, fecha y observaciones.</p>

    <h3>Accesos rapidos</h3>
    <p>Botones para acceder rapidamente a: Infraestructuras, Usuarios, Mapa y Gestion de infraestructuras.</p>

    <h2>4.3 Infraestructuras (Timeline de inspecciones)</h2>
    <p>Vista detallada del historial de inspecciones por infraestructura.</p>

    <h3>Panel izquierdo: Lista de infraestructuras</h3>
    <p>Muestra todas las infraestructuras activas. Cada una muestra: codigo (azul), nombre, punto de color segun ultimo estado y numero de inspecciones. Pulsa una para cargar su timeline.</p>

    <h3>Panel derecho: Timeline de la infraestructura seleccionada</h3>

    <h4>Cabecera</h4>
    <p>Muestra el nombre, codigo, coordenadas GPS, tipo y botones de accion:</p>
    <table>
        <thead><tr><th>Boton</th><th>Funcion</th></tr></thead>
        <tbody>
            <tr><td><strong>Comparar</strong></td><td>Vista de fotos comparativas lado a lado</td></tr>
            <tr><td><strong>CSV</strong></td><td>Exportar inspecciones a archivo CSV</td></tr>
            <tr><td><strong>ZIP</strong></td><td>Descargar todas las fotos en un archivo ZIP</td></tr>
            <tr><td><strong>GPX</strong></td><td>Descargar waypoints GPS de fotos comparativas</td></tr>
            <tr><td><strong>PDF</strong></td><td>Generar informe PDF de la infraestructura</td></tr>
        </tbody>
    </table>

    <h4>Filtros</h4>
    <ul>
        <li><strong>Desde / Hasta:</strong> Rango de fechas</li>
        <li><strong>Situacion:</strong> Todos / Antes / Durante / Despues</li>
        <li><strong>Operador:</strong> Filtrar por operador especifico</li>
        <li>Boton <strong>Filtrar</strong> y boton <strong>Limpiar filtros</strong></li>
    </ul>

    <h4>Modos de vista</h4>
    <ul>
        <li><strong>Timeline:</strong> Lista cronologica con tarjetas detalladas (fecha, operador, situacion, foto, GPS, observaciones)</li>
        <li><strong>Galeria:</strong> Cuadricula de miniaturas con overlay de informacion</li>
    </ul>

    <h4>Visor de fotos (Lightbox)</h4>
    <p>Al pulsar una foto se abre un visor a pantalla completa con: imagen grande, metadatos (fecha, situacion, operador, observaciones), flechas de navegacion, boton de descarga, boton para abrir en nueva ventana, contador de posicion, cierre con X/clic fuera/tecla Escape y navegacion con flechas del teclado.</p>

    <h2>4.4 Gestion de Infraestructuras</h2>
    <p>Pagina CRUD completa para gestionar infraestructuras.</p>

    <h3>Crear nueva infraestructura</h3>
    <p>Pulsa <strong>Nueva Infraestructura</strong> para abrir el formulario:</p>
    <table>
        <thead><tr><th>Campo</th><th>Descripcion</th></tr></thead>
        <tbody>
            <tr><td>Nombre*</td><td>Nombre de la infraestructura (obligatorio)</td></tr>
            <tr><td>Codigo unico</td><td>Identificador unico (auto-generado si se deja vacio)</td></tr>
            <tr><td>Tipo</td><td>Clasificacion (torre, poste, etc.)</td></tr>
            <tr><td>Provincia</td><td>Provincia</td></tr>
            <tr><td>Municipio</td><td>Municipio</td></tr>
            <tr><td>Monte</td><td>Monte o zona forestal</td></tr>
            <tr><td>Latitud</td><td>Coordenada GPS (7 decimales)</td></tr>
            <tr><td>Longitud</td><td>Coordenada GPS (7 decimales)</td></tr>
            <tr><td>Descripcion</td><td>Texto descriptivo opcional</td></tr>
        </tbody>
    </table>

    <p>Opciones para establecer coordenadas:</p>
    <ul>
        <li><strong>Usar mi ubicacion:</strong> Captura las coordenadas GPS del dispositivo</li>
        <li><strong>Elegir en mapa:</strong> Abre un mapa interactivo donde puedes hacer clic para colocar el marcador</li>
    </ul>

    <h3>Importar infraestructuras</h3>
    <p>Tres modos de importacion masiva:</p>
    <ol>
        <li><strong>Importar Excel:</strong> Sube un archivo CSV/XLSX con columnas: nombre, lat_teorica, lon_teorica (y opcionalmente: codigo_unico, tipo, provincia, municipio, monte, descripcion)</li>
        <li><strong>Importar KML:</strong> Sube un archivo KML con puntos georeferenciados. Los nombres y coordenadas se extraen automaticamente.</li>
        <li><strong>KML + Excel:</strong> Combinacion donde el KML aporta las coordenadas y el Excel aporta los datos detallados. Se emparejan por nombre.</li>
    </ol>

    <h3>Tarjetas de infraestructura</h3>
    <p>Cada infraestructura se muestra como tarjeta con:</p>
    <ul>
        <li><strong>Borde lateral de color</strong> segun ultimo estado de inspeccion (azul=antes, ambar=durante, verde=despues, gris=sin inspecciones)</li>
        <li>Nombre, codigo, tipo, ubicacion, coordenadas GPS</li>
        <li>Numero de inspecciones y fecha de la ultima</li>
        <li>Botones: <strong>Ver timeline</strong>, <strong>Editar</strong>, <strong>Activar/Desactivar</strong>, <strong>Eliminar</strong></li>
    </ul>

    <h3>Exportar</h3>
    <p><strong>Exportar CSV:</strong> Descarga todas las infraestructuras en formato CSV compatible con Excel.</p>

    <h2>4.5 Gestion de Usuarios</h2>

    <h3>Crear usuario</h3>
    <p>Pulsa <strong>Nuevo Usuario</strong> y completa:</p>
    <table>
        <thead><tr><th>Campo</th><th>Descripcion</th></tr></thead>
        <tbody>
            <tr><td>Nombre completo*</td><td>Nombre del usuario</td></tr>
            <tr><td>Email</td><td>Email (opcional si tiene telefono)</td></tr>
            <tr><td>Telefono</td><td>Telefono (opcional si tiene email)</td></tr>
            <tr><td>Rol*</td><td>Operador / Supervisor / Administrador</td></tr>
            <tr><td>Contrasena*</td><td>Minimo 12 caracteres</td></tr>
        </tbody>
    </table>

    <p>Al crear un operador, se genera automaticamente un <strong>enlace de acceso directo</strong> que puedes copiar y enviar al operador por WhatsApp o SMS.</p>

    <h3>Tabla de usuarios</h3>
    <p>Muestra todos los usuarios con: nombre, email, telefono, rol (badge de color), estado (Activo/Inactivo), enlace de acceso (para operadores), ultimo login, y acciones: <strong>Cambiar contrasena</strong>, <strong>Activar/Desactivar</strong>, <strong>Eliminar</strong>.</p>

    <h3>Estadisticas</h3>
    <ul>
        <li>Total de usuarios</li>
        <li>Usuarios activos</li>
        <li>Limite del plan</li>
        <li>Plazas disponibles</li>
    </ul>

    <h2>4.6 Mapa Interactivo</h2>
    <p>Mapa a pantalla completa con todas las fotos geoposicionadas y las infraestructuras de la empresa.</p>

    <h3>Filtros (barra superior)</h3>
    <ul>
        <li>Operador</li>
        <li>Unidad de obra</li>
        <li>Infraestructura</li>
        <li>Tipo de foto (Todos / Aleatorio / Comparativo)</li>
        <li>Estado/Situacion (Todos / Antes / Durante / Despues)</li>
    </ul>
    <blockquote>En movil, los filtros se ocultan y se acceden mediante el boton de embudo en la esquina inferior derecha.</blockquote>

    <h3>Estadisticas del mapa</h3>
    <p>Pastillas de colores en la esquina superior derecha mostrando: total de fotos, fotos comparativas, aleatorias, fotos antes, durante, despues e infraestructuras unicas con fotos.</p>

    <h3>Marcadores</h3>
    <ul>
        <li><strong>Infraestructuras:</strong> Marcadores en coordenadas teoricas, coloreados por ultimo estado. Al pulsar se abre popup con informacion y enlace al timeline.</li>
        <li><strong>Fotos:</strong> Marcadores individuales en coordenadas reales (donde se tomo la foto). Al pulsar se muestra miniatura, fecha, operador y metadatos.</li>
        <li><strong>Agrupacion (clustering):</strong> Los marcadores cercanos se agrupan automaticamente para mejorar el rendimiento.</li>
    </ul>

    <h3>Capas</h3>
    <ul>
        <li><strong>Capas KML:</strong> Archivos KML subidos por el administrador para mostrar limites o areas</li>
        <li><strong>Capas de infraestructuras (GeoJSON):</strong> Datos geoespaciales vinculados a infraestructuras</li>
    </ul>

    <h2>4.7 Informes PDF</h2>
    <p>Para generar un informe PDF de una infraestructura:</p>
    <ol>
        <li>Ve a la seccion <strong>Infraestructuras</strong> (timeline)</li>
        <li>Selecciona una infraestructura</li>
        <li>Pulsa el boton <strong>PDF</strong></li>
        <li>Se abrira una nueva pestana con el informe en formato imprimible</li>
        <li>Pulsa <strong>Imprimir</strong> (o Ctrl+P) y selecciona <strong>"Guardar como PDF"</strong></li>
    </ol>
    <p>El informe incluye: datos de la infraestructura (codigo, nombre, empresa, tipo), resumen estadistico (total inspecciones, desglose por situacion y tipo de foto), fotos comparativas agrupadas por visita, fotos aleatorias con sus metadatos, y cada foto con imagen, fecha/hora, coordenadas GPS, situacion, operador y observaciones.</p>

    <h2>4.8 Descarga masiva de fotos (ZIP)</h2>
    <ol>
        <li>Ve a <strong>Infraestructuras</strong> y selecciona una</li>
        <li>Pulsa el boton <strong>ZIP</strong></li>
        <li>Se descargara un archivo ZIP con todas las fotos</li>
    </ol>
    <p>El archivo ZIP: nombre <code>CODIGO_fotos_YYYYMMDD.zip</code>, cada foto nombrada como <code>CODIGO_FECHA_SITUACION_001.jpg</code>. Limite de seguridad: maximo 500 fotos por descarga.</p>

    <h2>4.9 Exportacion CSV</h2>
    <ol>
        <li>Ve a <strong>Infraestructuras</strong> y selecciona una</li>
        <li>Pulsa el boton <strong>CSV</strong></li>
        <li>Se descargara un archivo CSV compatible con Excel</li>
    </ol>
    <p>El CSV incluye: fecha, situacion, operador, coordenadas GPS, tipo de foto, nombre de archivo, observaciones y campos dinamicos.</p>

    <h2>4.10 Exportacion GPX (Waypoints)</h2>
    <ol>
        <li>Ve a <strong>Infraestructuras</strong> y selecciona una</li>
        <li>Pulsa el boton <strong>GPX</strong></li>
        <li>Se descargara un archivo GPX con los puntos de cada foto comparativa</li>
    </ol>
    <p>El archivo GPX es compatible con software de GPS y cartografia (Google Earth, QGIS, Garmin, etc.) y contiene: nombre del waypoint (CODIGO_W1, CODIGO_W2...), coordenadas, fecha y metadatos.</p>

    <h2>4.11 Unidades de Obra</h2>
    <p>Gestiona las categorias de unidades de obra que los operadores seleccionan al tomar fotos.</p>

    <h3>Crear unidad de obra</h3>
    <ul>
        <li><strong>Nombre:</strong> Nombre de la unidad (ej: "Cimentacion")</li>
        <li><strong>Codigo:</strong> Codigo opcional (ej: "UO-001")</li>
        <li><strong>Descripcion:</strong> Descripcion opcional</li>
    </ul>

    <h3>Acciones</h3>
    <p>Editar, Activar/Desactivar, Eliminar</p>

    <h2>4.12 Ajustes de Empresa</h2>
    <p>Pagina de configuracion dividida en tres secciones:</p>

    <h3>Formato de nombre de fotos</h3>
    <table>
        <thead><tr><th>Opcion</th><th>Formato</th><th>Ejemplo</th></tr></thead>
        <tbody>
            <tr><td>1</td><td>Codigo + N</td><td><code>INF-001_001</code></td></tr>
            <tr><td>2</td><td>Codigo + Situacion + N</td><td><code>INF-001_Antes_001</code></td></tr>
            <tr><td>3</td><td>Codigo + Situacion + Tipo + N</td><td><code>INF-001_Antes_Aleatoria_001</code></td></tr>
        </tbody>
    </table>
    <p>Se muestra una previsualizacion en tiempo real del formato seleccionado.</p>

    <h3>Campos visibles para el operador</h3>
    <p>Activa o desactiva las opciones que ven los operadores en su app:</p>
    <table>
        <thead><tr><th>Opcion</th><th>Descripcion</th></tr></thead>
        <tbody>
            <tr><td>Empresa</td><td>Muestra el nombre de la empresa en el formulario</td></tr>
            <tr><td>Infraestructura (info detalle)</td><td>Muestra detalles adicionales (tipo, coordenadas)</td></tr>
            <tr><td>Situacion de la obra</td><td>Muestra selector Antes / Durante / Despues</td></tr>
            <tr><td>Mapa de localizacion</td><td>Muestra boton de mapa interactivo</td></tr>
            <tr><td>Capas de infraestructuras</td><td>Permite ver capas GeoJSON en el mapa del operador</td></tr>
        </tbody>
    </table>

    <p>Si activas el mapa, puedes configurar la <strong>escala de zoom</strong>:</p>
    <ul>
        <li>1:500.000 (zoom 9, por defecto)</li>
        <li>1:250.000 (zoom 10)</li>
        <li>1:100.000 (zoom 12)</li>
        <li>1:50.000 (zoom 13)</li>
    </ul>

    <h3>Marca de agua en fotos</h3>
    <p>Configura que informacion aparece superpuesta en cada foto capturada.</p>

    <p><strong>Tamano del texto:</strong></p>
    <ul>
        <li>Pequeno (discreto)</li>
        <li>Mediano (por defecto)</li>
        <li>Grande (50% mas grande)</li>
        <li>Muy grande (doble tamano)</li>
    </ul>

    <p><strong>Informacion base</strong> (activada por defecto):</p>
    <ul>
        <li>Fecha y hora (zona horaria Madrid)</li>
        <li>Coordenadas UTM</li>
        <li>Orientacion (grados + punto cardinal)</li>
        <li>Municipio, provincia y CP</li>
        <li>Pais</li>
        <li>Brujula grafica (rosa de los vientos)</li>
    </ul>

    <p><strong>Informacion adicional</strong> (desactivada por defecto):</p>
    <ul>
        <li>Codigo de infraestructura</li>
        <li>Situacion de la obra (Antes/Durante/Despues)</li>
        <li>Tipo de foto (FOT ALE / FOT COM)</li>
        <li>Mini-mapa de localizacion (con opciones de escala y tamano)</li>
    </ul>

    <h2>4.13 Campos de Formulario Dinamicos</h2>
    <p>Permite crear campos personalizados que aparecen en el formulario del operador.</p>

    <h3>Tipos de campo disponibles</h3>
    <table>
        <thead><tr><th>Tipo</th><th>Descripcion</th><th>Ejemplo</th></tr></thead>
        <tbody>
            <tr><td>Texto corto</td><td>Campo de texto de una linea</td><td>Nombre del tecnico</td></tr>
            <tr><td>Numero</td><td>Campo numerico</td><td>Temperatura ambiente</td></tr>
            <tr><td>Desplegable</td><td>Lista de opciones predefinidas</td><td>Material (Acero/Madera/Hormigon)</td></tr>
            <tr><td>Casilla Si/No</td><td>Checkbox</td><td>Acceso con vehiculo</td></tr>
            <tr><td>Texto largo</td><td>Area de texto multilinea</td><td>Descripcion detallada</td></tr>
            <tr><td>Fecha</td><td>Selector de fecha</td><td>Fecha de instalacion</td></tr>
        </tbody>
    </table>

    <h3>Gestion de campos</h3>
    <ul>
        <li><strong>Crear:</strong> Define nombre, tipo, opciones (para desplegables), orden y si es obligatorio</li>
        <li><strong>Reordenar:</strong> Arrastra y suelta los campos para cambiar su orden</li>
        <li><strong>Editar:</strong> Modifica cualquier propiedad del campo</li>
        <li><strong>Eliminar:</strong> Desactiva el campo (no se borra, se oculta)</li>
    </ul>

    <h3>Importar/Exportar</h3>
    <ul>
        <li><strong>Exportar CSV:</strong> Descarga la configuracion de campos en formato CSV</li>
        <li><strong>Importar CSV:</strong> Sube un archivo CSV para crear campos masivamente</li>
        <li><strong>Campos por defecto:</strong> Boton para crear 6 campos estandar automaticamente</li>
    </ul>

    <h3>Vista previa</h3>
    <p>En la columna derecha se muestra una previsualizacion de como vera el operador los campos en su formulario.</p>

    <hr>

    <!-- ===== SECCION 5: FAQ ===== -->
    <h1 id="sec-5"><span class="h-icon"><i class="bi bi-question-circle-fill"></i></span> 5. Preguntas frecuentes</h1>

    <div class="faq-item">
        <div class="faq-q">Las fotos que tomo sin conexion, se pierden?</div>
        <div class="faq-a">No. Se guardan localmente en el dispositivo y se sincronizan automaticamente cuando recuperes conexion a internet. Mientras tanto, veras un indicador de "fotos pendientes".</div>
    </div>

    <div class="faq-item">
        <div class="faq-q">Puedo usar la app en iPhone?</div>
        <div class="faq-a">Si. Funciona en Safari en iPhone. Puedes instalarla desde el menu "Compartir > Anadir a pantalla de inicio".</div>
    </div>

    <div class="faq-item">
        <div class="faq-q">Que precision tiene el GPS?</div>
        <div class="faq-a">La precision depende del dispositivo y las condiciones. En exterior con buena senal suele ser de 3-10 metros. Las coordenadas se muestran en formato UTM ETRS89.</div>
    </div>

    <div class="faq-item">
        <div class="faq-q">Puedo cambiar la situacion de una foto despues de tomarla?</div>
        <div class="faq-a">Si. Desde "Mis Visitas", pulsa sobre la foto y podras editar la situacion, unidad de obra y observaciones.</div>
    </div>

    <div class="faq-item">
        <div class="faq-q">Cuantas fotos puedo descargar en el ZIP?</div>
        <div class="faq-a">El limite por descarga es de 500 fotos. Si una infraestructura tiene mas, se descargaran las 500 mas recientes.</div>
    </div>

    <div class="faq-item">
        <div class="faq-q">Que formato tienen los archivos GPX?</div>
        <div class="faq-a">Los archivos GPX contienen waypoints con coordenadas WGS84, compatibles con Google Earth, QGIS, Garmin y otros programas de cartografia.</div>
    </div>

    <div class="faq-item">
        <div class="faq-q">El ghost/fantasma funciona sin conexion?</div>
        <div class="faq-a">Si, si previamente has pulsado "Precargar fotos offline" para esa infraestructura. Las fotos se almacenan localmente para uso sin conexion.</div>
    </div>

    <div class="faq-item">
        <div class="faq-q">Que navegadores son compatibles?</div>
        <div class="faq-a">Chrome (Android), Safari (iOS), Edge y Firefox recientes. Se recomienda Chrome en Android para la mejor experiencia.</div>
    </div>

</div>

<!-- Footer -->
<div class="manual-footer">
    <p>Manual generado para <strong>FotoGPS.app</strong> (INFOCAMPO) &mdash; Marzo 2026</p>
    <p style="margin-top:8px;"><a href="/" style="color:var(--accent-light);text-decoration:none;">Volver a la pagina principal</a></p>
</div>

<!-- Back to top button -->
<button class="back-to-top" id="back-to-top" onclick="window.scrollTo({top:0,behavior:'smooth'})" title="Volver arriba">
    <i class="bi bi-chevron-up"></i>
</button>

<script>
// Back to top visibility
const btn = document.getElementById('back-to-top');
window.addEventListener('scroll', () => {
    btn.classList.toggle('visible', window.scrollY > 600);
});

// Active nav item on scroll
const sections = document.querySelectorAll('h1[id^="sec-"]');
const navItems = document.querySelectorAll('.manual-nav a.nav-item');
const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            navItems.forEach(a => a.classList.remove('active'));
            const id = entry.target.id;
            const link = document.querySelector(`.manual-nav a[href="#${id}"]`);
            if (link) link.classList.add('active');
        }
    });
}, { rootMargin: '-20% 0px -70% 0px' });
sections.forEach(s => observer.observe(s));
</script>

</body>
</html>