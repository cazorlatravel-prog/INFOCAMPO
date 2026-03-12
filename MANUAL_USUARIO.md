# Manual de Usuario - FotoGPS.app

## Plataforma INFOCAMPO de Recogida de Datos en Campo

---

# 1. Introduccion

## 1.1 Que es FotoGPS.app

FotoGPS.app (INFOCAMPO) es una plataforma SaaS (Software as a Service) disenada para la recogida de datos en campo mediante fotografia geoposicionada. Permite a equipos de trabajo capturar fotos con marca de agua GPS, gestionar infraestructuras, generar informes y supervisar el progreso de inspecciones desde cualquier dispositivo.

## 1.2 Tecnologia utilizada

| Componente | Tecnologia |
|---|---|
| **Backend** | PHP 8.1+ con PDO (sin framework) |
| **Base de datos** | MySQL 8.0+ |
| **Frontend** | JavaScript ES6+, Bootstrap 5.3.3 |
| **Mapas** | Leaflet 1.9.4 con OpenStreetMap |
| **GPS** | API de geolocalizacion del navegador (ETRS89/WGS84) |
| **Coordenadas** | Conversion automatica a UTM |
| **Camara** | API MediaDevices del navegador (camara trasera del movil) |
| **Marca de agua** | Renderizado en Canvas HTML5 en tiempo real |
| **Almacenamiento de fotos** | Cloudinary (nube) con fallback local |
| **Generacion PDF** | DOMPDF (via Composer) |
| **Soporte offline** | Service Workers + IndexedDB |
| **Instalable** | Progressive Web App (PWA) |

## 1.3 Que puede hacer la aplicacion

### Para operadores de campo
- Capturar fotos geoposicionadas con marca de agua automatica (coordenadas UTM, fecha, ubicacion, brujula, mini-mapa)
- Dos modos de foto: **Aleatorias** (libres) y **Comparativas** (con overlay fantasma de la foto anterior para replicar el mismo encuadre)
- Anotar fotos antes de enviarlas (circulo rojo + texto senalando incidencias)
- Trabajar sin conexion: las fotos se guardan y sincronizan automaticamente al recuperar cobertura
- Navegar hasta infraestructuras con indicador de distancia en tiempo real y avisos sonoros de proximidad
- Ver mapa interactivo con capas KML, ortofotos y topografico

### Para administradores
- Gestionar infraestructuras (crear, importar desde Excel/KML, editar, desactivar)
- Gestionar usuarios (operadores, supervisores, administradores)
- Gestionar unidades de obra y tipos de trabajo
- Ver timeline de inspecciones con filtros por fecha, operador, situacion y tipo
- Ver todas las fotos en un mapa interactivo con marcadores y clusters
- Generar informes PDF por infraestructura
- Descargar fotos en ZIP por infraestructura
- Exportar datos en CSV y waypoints en GPX
- Configurar la marca de agua (elementos visibles, tamano de texto, mini-mapa)
- Configurar que campos ve el operador en su interfaz
- Configurar el formato de nombre de las fotos
- Definir campos de formulario dinamicos personalizados por empresa
- Gestionar capas KML/GeoJSON para visualizar en los mapas

## 1.4 Roles de usuario

| Rol | Acceso | Panel |
|---|---|---|
| **Admin** | Gestion completa de la empresa | Panel de administracion |
| **Supervisor** | Acceso de consulta al panel admin | Panel de administracion |
| **Operador** | Captura de fotos en campo | App de campo (movil) |

---

# 2. Acceso a la plataforma

## 2.1 Inicio de sesion

1. Accede a la URL de la plataforma (ej: `https://fotogps.app`)
2. Introduce tu **email o telefono** y tu **contrasena**
3. Pulsa **Acceder**
4. Segun tu rol, seras redirigido al panel correspondiente:
   - Operadores: Pantalla de toma de datos
   - Administradores/Supervisores: Panel de administracion

> **Nota:** La contrasena es sensible a mayusculas/minusculas. Si no puedes acceder, contacta con tu administrador.

## 2.2 Cerrar sesion

- **Operadores:** Pulsa en tu avatar (iniciales) en la esquina superior derecha y selecciona "Cerrar sesion"
- **Administradores:** Pulsa el icono de cerrar sesion en la barra superior

---

# 3. Manual del Operador de Campo

## 3.1 Instalar la app en el movil (recomendado)

FotoGPS.app es una Progressive Web App (PWA) que se puede instalar como una app nativa:

**Android (Chrome):**
1. Abre la web en Chrome
2. Aparecera un banner morado con el boton **Instalar**
3. Pulsa Instalar y confirma
4. La app aparecera en tu pantalla de inicio

**iPhone (Safari):**
1. Abre la web en Safari
2. Pulsa el icono de compartir (cuadrado con flecha)
3. Desplazate y selecciona **Anadir a pantalla de inicio**
4. Confirma el nombre y pulsa Anadir

> Instalar la app permite acceso rapido y pantalla completa sin barra del navegador.

## 3.2 Pantalla principal: Ficha de Visita

Al acceder como operador, veras la pantalla de **Ficha de Visita**. Esta es tu centro de trabajo.

### Indicador de conexion
- **Punto verde "En linea":** Estas conectado. Las fotos se suben al instante.
- **Punto rojo "Sin conexion":** Sin internet. Las fotos se guardan localmente y se sincronizaran cuando vuelvas a tener cobertura.

### Seleccion de ubicacion (si esta habilitado)
1. **Provincia:** Selecciona la provincia donde trabajas. Filtra las infraestructuras disponibles.
2. **Municipio:** Se actualiza automaticamente segun la provincia seleccionada.
3. **Monte:** (Opcional) Filtra por monte o zona forestal.

### Buscar o crear infraestructura
1. Escribe en el campo de busqueda el nombre o codigo de la infraestructura
2. Apareceran las opciones que coincidan
3. Pulsa sobre una para seleccionarla
4. Si no existe, escribe el nombre completo y pulsa **"+ Crear: [nombre]"** para crearla en tu ubicacion GPS actual

> Una vez seleccionada, aparecera resaltada con su nombre y codigo. Pulsa la **X** para deseleccionarla.

### Campos del formulario
Segun la configuracion de tu empresa, podras ver:
- **Tipo de trabajo:** Selecciona el tipo de trabajo a realizar
- **Unidad de obra:** Selecciona la unidad de obra correspondiente
- **Observaciones:** Escribe notas generales de la visita
- **Situacion de la obra:** Selecciona ANTES / DURANTE / DESPUES (si esta habilitado)
- **Campos dinamicos:** Campos personalizados configurados por tu administrador

## 3.3 Captura de fotos

### Modo Aleatorio (Fotos libres)

1. Selecciona una infraestructura
2. Pulsa el boton **Fotos Aleatorias**
3. Se abrira la camara trasera del movil
4. Encuadra la foto
5. Pulsa el **boton circular** (disparador) en la parte inferior
6. Revisa la foto en la pantalla de previsualizacion
7. Pulsa **Aceptar** para guardar o **Repetir** para volver a sacar la foto

### Modo Comparativo (con ghost/fantasma)

Este modo permite sacar fotos en la misma posicion exacta que la visita anterior, superponiendo la foto antigua como guia transparente.

1. Selecciona una infraestructura
2. Pulsa el boton **Fotos Comparativas**
3. Se abrira la camara con un panel para cargar la foto anterior
4. Selecciona la foto anterior de la lista de miniaturas
5. Aparecera una imagen semi-transparente superpuesta sobre la camara en vivo
6. Usa el **control deslizante de opacidad** (icono del ojo) para ajustar la transparencia del fantasma
7. Alinea tu encuadre con la foto anterior
8. Pulsa el disparador
9. Revisa y acepta la foto

> Las fotos comparativas se numeran como W1, W2, W3... (waypoints). Cada una guarda las coordenadas GPS para posterior descarga en formato GPX.

### Pantalla de previsualizacion

Despues de capturar una foto, veras la previsualizacion con la marca de agua aplicada. Tienes tres opciones:

- **Repetir:** Descarta la foto y vuelve a la camara
- **Anotar:** Activa el modo de anotacion (ver seccion siguiente)
- **Aceptar:** Sube la foto al servidor (o la guarda localmente si no hay conexion)

### Anotaciones en fotos

Permite senalar puntos de interes o incidencias directamente sobre la foto:

1. Pulsa **Anotar** en la pantalla de previsualizacion
2. Toca el punto de la foto que quieres senalar
3. Aparecera un **circulo rojo** en ese punto
4. Escribe una descripcion en el campo de texto (ej: "Grieta en poste")
5. Ajusta el tamano del circulo con el control deslizante
6. Pulsa **Aceptar** para guardar la foto con la anotacion

## 3.4 Galeria de fotos de la visita

En la parte inferior de la pantalla principal aparece la seccion **"Fotos de esta visita"** con miniaturas de todas las fotos tomadas:

- **ALEA:** Foto aleatoria
- **W1, W2...:** Foto comparativa (waypoint)
- **Asterisco (*):** Foto pendiente de sincronizar

## 3.5 Finalizar visita

Cuando hayas terminado de tomar fotos:

1. Pulsa el boton **Finalizar visita** (rojo, parte inferior)
2. Se guardara la visita con todas las fotos, observaciones y datos del formulario
3. Se reiniciaran todos los campos para empezar una nueva visita

> Si necesitas registrar una visita sin tomar fotos (por ejemplo, si no puedes acceder a la infraestructura), usa el boton **Guardar visita sin foto**.

## 3.6 Mapa de Visitas

Pulsa el boton **Mapa de Visitas** para ver un mapa interactivo con:

- **Tu ubicacion actual** (marcador azul con anillo pulsante)
- **Infraestructuras visitadas** (circulos de colores con numero de fotos)
- **Infraestructuras no visitadas** (marcadores grises)
- **Capas KML** (limites administrativos u otros datos configurados por el admin)

### Capas del mapa
En la esquina superior izquierda puedes cambiar el tipo de mapa:
- **Mapa:** Mapa de calles (OpenStreetMap)
- **Ortofoto:** Imagen aerea (IGN Espana PNOA)
- **Topografico:** Mapa topografico (IGN Espana)

### Buscar en el mapa
Pulsa el icono de busqueda para filtrar infraestructuras por nombre o codigo.

### Navegar hasta una infraestructura
1. Pulsa sobre una infraestructura en el mapa
2. En el panel de detalle, pulsa **"Ir a esta ubicacion"**
3. Se activara el modo navegacion:
   - Veras la **distancia en tiempo real** hasta el destino
   - Una **linea roja discontinua** conecta tu posicion con el objetivo
   - **Avisos sonoros** de proximidad:
     - 1 pitido a mas de 20m
     - 2 pitidos entre 10-20m
     - 3 pitidos a menos de 5m
   - El color del indicador cambia: gris (lejos) > amarillo (cerca) > azul (muy cerca) > verde (llegaste)
4. Pulsa **Detener** para desactivar la navegacion

### Tomar fotos desde el mapa
Desde el panel de detalle de una infraestructura, puedes pulsar **Foto** o **Comparativa** para abrir directamente la camara para esa infraestructura.

## 3.7 Mis Visitas

Pulsa **Mis Visitas** para ver el historial de tus registros anteriores:

- Las visitas se agrupan por infraestructura
- Cada foto muestra: miniatura, situacion (ANTES/DURANTE/DESPUES), tipo (ALEA/W1) y hora
- **Continuar visita:** Carga los datos de una visita anterior para anadir mas fotos
- Pulsa sobre una foto para editar sus datos (situacion, unidad de obra, observaciones)

## 3.8 Trabajo sin conexion (modo offline)

FotoGPS.app funciona sin conexion a internet:

1. **Captura de fotos:** Las fotos se guardan localmente en el dispositivo
2. **Indicador:** Aparece una barra inferior mostrando "X fotos pendientes"
3. **Sincronizacion automatica:** Cuando recuperes conexion, las fotos se suben automaticamente
4. **Sincronizacion manual:** Pulsa el boton **Sincronizar** en la barra de pendientes
5. **Precarga de fotos:** Antes de ir a zona sin cobertura, puedes precargar las fotos comparativas de una infraestructura con el boton **"Precargar fotos offline"**

> **Importante:** No borres los datos del navegador mientras tengas fotos pendientes de sincronizar. Si alguna foto falla al subir, se reintentara automaticamente.

## 3.9 Marca de agua en las fotos

Cada foto capturada incluye automaticamente una marca de agua con informacion configurable por el administrador:

| Elemento | Ejemplo | Configurable |
|---|---|---|
| Fecha y hora | `12 mar 2026 11:50:18` | Si |
| Coordenadas UTM | `30S 499752 4196518` | Si |
| Orientacion | `173 S` | Si |
| Ubicacion | `Cazorla, Jaen 23470` | Si |
| Pais | `Espana` | Si |
| Brujula grafica | Rosa de los vientos con flecha | Si |
| Codigo infraestructura | `TORRE-A42` | Si (opcional) |
| Situacion | `ANTES / DURANTE / DESPUES` | Si (opcional) |
| Tipo de foto | `FOT ALE / FOT COM` | Si (opcional) |
| Mini-mapa | Mapa con marcador de ubicacion | Si (opcional) |

---

# 4. Manual del Administrador

## 4.1 Panel de administracion: vista general

Al acceder como administrador, veras el **Dashboard** con un resumen de la actividad de tu empresa.

### Barra de navegacion

La barra superior incluye:
- **Logo FotoGPS.app** con nombre de la empresa
- **Busqueda global** (Ctrl+K): Busca infraestructuras, inspecciones y usuarios
- **Campana de alertas:** Muestra inspecciones en fase "Durante" en las ultimas 24h
- **Nombre y rol** del usuario
- **Boton Instalar:** Para instalar la PWA en tu dispositivo
- **Cerrar sesion**

El menu lateral incluye las siguientes secciones:

| Seccion | Descripcion |
|---|---|
| Dashboard | Resumen de estadisticas y actividad |
| Infraestructuras | Timeline de inspecciones por infraestructura |
| Fotos | Galeria de fotos |
| Mapa | Mapa interactivo de todos los registros |
| Informes | Generacion de informes |
| Trabajos | Gestion de tipos de trabajo |
| Unidades | Gestion de unidades de obra |
| Usuarios | Gestion de operadores, supervisores y admins |
| Campos | Constructor de campos de formulario dinamicos |
| Capas | Gestion de capas GeoJSON de infraestructuras |
| Puntos | Gestion de puntos personalizados en el mapa |
| Ajustes | Configuracion de la empresa |

## 4.2 Dashboard

El dashboard muestra:

### Tarjetas de estadisticas
- **Infraestructuras:** Numero total de infraestructuras activas
- **Inspecciones:** Total de registros fotograficos, con tendencia de los ultimos 7 dias
- **Operadores activos:** Numero de operadores con cuenta activa
- **Durante (24h):** Registros en fase "Durante" en las ultimas 24 horas (resaltado si hay alguno)

### Grafico de actividad (14 dias)
Grafico de barras apiladas mostrando la actividad diaria por situacion:
- **Azul:** Fotos "Antes"
- **Ambar:** Fotos "Durante"
- **Verde:** Fotos "Despues"

### Ultimas inspecciones
Lista de los 10 registros mas recientes con:
- Nombre de infraestructura y codigo
- Situacion (badge de color)
- Operador y fecha/hora

### Registros en curso
Tarjetas con los registros en fase "Durante" mas recientes, mostrando infraestructura, operador, fecha y observaciones.

### Accesos rapidos
Botones para acceder rapidamente a: Infraestructuras, Usuarios, Mapa y Gestion de infraestructuras.

## 4.3 Infraestructuras (Timeline de inspecciones)

Vista detallada del historial de inspecciones por infraestructura.

### Panel izquierdo: Lista de infraestructuras
- Muestra todas las infraestructuras activas
- Cada una muestra: codigo (azul), nombre, punto de color segun ultimo estado y numero de inspecciones
- Pulsa una para cargar su timeline

### Panel derecho: Timeline de la infraestructura seleccionada

#### Cabecera
Muestra el nombre, codigo, coordenadas GPS, tipo y botones de accion:

| Boton | Funcion |
|---|---|
| **Comparar** | Vista de fotos comparativas lado a lado |
| **CSV** | Exportar inspecciones a archivo CSV |
| **ZIP** | Descargar todas las fotos en un archivo ZIP |
| **GPX** | Descargar waypoints GPS de fotos comparativas |
| **PDF** | Generar informe PDF de la infraestructura |

#### Filtros
- **Desde / Hasta:** Rango de fechas
- **Situacion:** Todos / Antes / Durante / Despues
- **Operador:** Filtrar por operador especifico
- Boton **Filtrar** y boton **Limpiar filtros**

#### Modos de vista
- **Timeline:** Lista cronologica con tarjetas detalladas (fecha, operador, situacion, foto, GPS, observaciones)
- **Galeria:** Cuadricula de miniaturas con overlay de informacion

#### Visor de fotos (Lightbox)
Al pulsar una foto se abre un visor a pantalla completa con:
- Imagen grande
- Metadatos (fecha, situacion, operador, observaciones)
- Flechas de navegacion (izquierda/derecha)
- Boton de descarga
- Boton para abrir en nueva ventana
- Contador de posicion (ej: "5 / 15")
- Cierre con X, clic fuera o tecla Escape
- Navegacion con flechas del teclado

## 4.4 Gestion de Infraestructuras

Pagina CRUD completa para gestionar infraestructuras.

### Crear nueva infraestructura
Pulsa **Nueva Infraestructura** para abrir el formulario:

| Campo | Descripcion |
|---|---|
| Nombre* | Nombre de la infraestructura (obligatorio) |
| Codigo unico | Identificador unico (auto-generado si se deja vacio) |
| Tipo | Clasificacion (torre, poste, etc.) |
| Provincia | Provincia |
| Municipio | Municipio |
| Monte | Monte o zona forestal |
| Latitud | Coordenada GPS (7 decimales) |
| Longitud | Coordenada GPS (7 decimales) |
| Descripcion | Texto descriptivo opcional |

Opciones para establecer coordenadas:
- **Usar mi ubicacion:** Captura las coordenadas GPS del dispositivo
- **Elegir en mapa:** Abre un mapa interactivo donde puedes hacer clic para colocar el marcador

### Importar infraestructuras
Tres modos de importacion masiva:

1. **Importar Excel:** Sube un archivo CSV/XLSX con columnas: nombre, lat_teorica, lon_teorica (y opcionalmente: codigo_unico, tipo, provincia, municipio, monte, descripcion)
2. **Importar KML:** Sube un archivo KML con puntos georeferenciados. Los nombres y coordenadas se extraen automaticamente.
3. **KML + Excel:** Combinacion donde el KML aporta las coordenadas y el Excel aporta los datos detallados. Se emparejan por nombre.

### Tarjetas de infraestructura
Cada infraestructura se muestra como tarjeta con:
- **Borde lateral de color** segun ultimo estado de inspeccion (azul=antes, ambar=durante, verde=despues, gris=sin inspecciones)
- Nombre, codigo, tipo, ubicacion, coordenadas GPS
- Numero de inspecciones y fecha de la ultima
- Botones: **Ver timeline**, **Editar**, **Activar/Desactivar**, **Eliminar**

### Exportar
- **Exportar CSV:** Descarga todas las infraestructuras en formato CSV compatible con Excel

## 4.5 Gestion de Usuarios

### Crear usuario
Pulsa **Nuevo Usuario** y completa:

| Campo | Descripcion |
|---|---|
| Nombre completo* | Nombre del usuario |
| Email | Email (opcional si tiene telefono) |
| Telefono | Telefono (opcional si tiene email) |
| Rol* | Operador / Supervisor / Administrador |
| Contrasena* | Minimo 12 caracteres |

Al crear un operador, se genera automaticamente un **enlace de acceso directo** que puedes copiar y enviar al operador por WhatsApp o SMS.

### Tabla de usuarios
Muestra todos los usuarios con:
- Nombre, email, telefono
- Rol (badge de color)
- Estado (Activo/Inactivo)
- Enlace de acceso (para operadores)
- Ultimo login
- Acciones: **Cambiar contrasena**, **Activar/Desactivar**, **Eliminar**

### Estadisticas
- Total de usuarios
- Usuarios activos
- Limite del plan
- Plazas disponibles

## 4.6 Mapa Interactivo

Mapa a pantalla completa con todas las fotos geoposicionadas y las infraestructuras de la empresa.

### Filtros (barra superior)
- Operador
- Unidad de obra
- Infraestructura
- Tipo de foto (Todos / Aleatorio / Comparativo)
- Estado/Situacion (Todos / Antes / Durante / Despues)

> En movil, los filtros se ocultan y se acceden mediante el boton de embudo en la esquina inferior derecha.

### Estadisticas del mapa
Pastillas de colores en la esquina superior derecha mostrando:
- Total de fotos
- Fotos comparativas
- Fotos en fase "durante"
- Infraestructuras unicas con fotos

### Marcadores
- **Infraestructuras:** Marcadores en coordenadas teoricas, coloreados por ultimo estado. Al pulsar se abre popup con informacion y enlace al timeline.
- **Fotos:** Marcadores individuales en coordenadas reales (donde se tomo la foto). Al pulsar se muestra miniatura, fecha, operador y metadatos.
- **Agrupacion (clustering):** Los marcadores cercanos se agrupan automaticamente para mejorar el rendimiento.

### Capas
- **Capas KML:** Archivos KML subidos por el administrador para mostrar limites o areas
- **Capas de infraestructuras (GeoJSON):** Datos geoespaciales vinculados a infraestructuras

## 4.7 Informes PDF

Para generar un informe PDF de una infraestructura:

1. Ve a la seccion **Infraestructuras** (timeline)
2. Selecciona una infraestructura
3. Pulsa el boton **PDF**
4. Se abrira una nueva pestana con el informe en formato imprimible
5. Pulsa **Imprimir** (o Ctrl+P) y selecciona **"Guardar como PDF"**

El informe incluye:
- Datos de la infraestructura (codigo, nombre, empresa, tipo)
- Resumen estadistico (total inspecciones, desglose por situacion y tipo de foto)
- Fotos comparativas agrupadas por visita
- Fotos aleatorias con sus metadatos
- Cada foto muestra: imagen, fecha/hora, coordenadas GPS, situacion, operador y observaciones

## 4.8 Descarga masiva de fotos (ZIP)

Para descargar todas las fotos de una infraestructura:

1. Ve a **Infraestructuras** y selecciona una
2. Pulsa el boton **ZIP**
3. Se descargara un archivo ZIP con todas las fotos

El archivo ZIP:
- Nombre: `CODIGO_fotos_YYYYMMDD.zip`
- Cada foto nombrada como: `CODIGO_FECHA_SITUACION_001.jpg`
- Limite de seguridad: maximo 500 fotos por descarga

## 4.9 Exportacion CSV

Para exportar los datos de inspecciones:

1. Ve a **Infraestructuras** y selecciona una
2. Pulsa el boton **CSV**
3. Se descargara un archivo CSV compatible con Excel

El CSV incluye: fecha, situacion, operador, coordenadas GPS, tipo de foto, nombre de archivo, observaciones y campos dinamicos.

## 4.10 Exportacion GPX (Waypoints)

Para descargar los waypoints GPS de las fotos comparativas:

1. Ve a **Infraestructuras** y selecciona una
2. Pulsa el boton **GPX**
3. Se descargara un archivo GPX con los puntos de cada foto comparativa

El archivo GPX es compatible con software de GPS y cartografia (Google Earth, QGIS, Garmin, etc.) y contiene: nombre del waypoint (CODIGO_W1, CODIGO_W2...), coordenadas, fecha y metadatos.

## 4.11 Unidades de Obra

Gestiona las categorias de unidades de obra que los operadores seleccionan al tomar fotos.

### Crear unidad de obra
- **Nombre:** Nombre de la unidad (ej: "Cimentacion")
- **Codigo:** Codigo opcional (ej: "UO-001")
- **Descripcion:** Descripcion opcional

### Acciones
- Editar, Activar/Desactivar, Eliminar

## 4.12 Ajustes de Empresa

Pagina de configuracion dividida en tres secciones:

### Formato de nombre de fotos

Tres opciones para el formato del nombre de archivo:

| Opcion | Formato | Ejemplo |
|---|---|---|
| 1 | Codigo + N | `INF-001_001` |
| 2 | Codigo + Situacion + N | `INF-001_Antes_001` |
| 3 | Codigo + Situacion + Tipo + N | `INF-001_Antes_Aleatoria_001` |

Se muestra una previsualizacion en tiempo real del formato seleccionado.

### Campos visibles para el operador

Activa o desactiva las opciones que ven los operadores en su app:

| Opcion | Descripcion |
|---|---|
| Empresa | Muestra el nombre de la empresa en el formulario |
| Infraestructura (info detalle) | Muestra detalles adicionales (tipo, coordenadas) |
| Situacion de la obra | Muestra selector Antes / Durante / Despues |
| Mapa de localizacion | Muestra boton de mapa interactivo |
| Capas de infraestructuras | Permite ver capas GeoJSON en el mapa del operador |

Si activas el mapa, puedes configurar la **escala de zoom**:
- 1:500.000 (zoom 9, por defecto)
- 1:250.000 (zoom 10)
- 1:100.000 (zoom 12)
- 1:50.000 (zoom 13)

### Marca de agua en fotos

Configura que informacion aparece superpuesta en cada foto capturada.

**Tamano del texto:**
- Pequeno (discreto)
- Mediano (por defecto)
- Grande (50% mas grande)
- Muy grande (doble tamano)

**Informacion base** (activada por defecto):
- Fecha y hora (zona horaria Madrid)
- Coordenadas UTM
- Orientacion (grados + punto cardinal)
- Municipio, provincia y CP
- Pais
- Brujula grafica (rosa de los vientos)

**Informacion adicional** (desactivada por defecto):
- Codigo de infraestructura
- Situacion de la obra (Antes/Durante/Despues)
- Tipo de foto (FOT ALE / FOT COM)
- Mini-mapa de localizacion (con opciones de escala y tamano)

## 4.13 Campos de Formulario Dinamicos

Permite crear campos personalizados que aparecen en el formulario del operador.

### Tipos de campo disponibles

| Tipo | Descripcion | Ejemplo |
|---|---|---|
| Texto corto | Campo de texto de una linea | Nombre del tecnico |
| Numero | Campo numerico | Temperatura ambiente |
| Desplegable | Lista de opciones predefinidas | Material (Acero/Madera/Hormigon) |
| Casilla Si/No | Checkbox | Acceso con vehiculo |
| Texto largo | Area de texto multilinea | Descripcion detallada |
| Fecha | Selector de fecha | Fecha de instalacion |

### Gestion de campos
- **Crear:** Define nombre, tipo, opciones (para desplegables), orden y si es obligatorio
- **Reordenar:** Arrastra y suelta los campos para cambiar su orden
- **Editar:** Modifica cualquier propiedad del campo
- **Eliminar:** Desactiva el campo (no se borra, se oculta)

### Importar/Exportar
- **Exportar CSV:** Descarga la configuracion de campos en formato CSV
- **Importar CSV:** Sube un archivo CSV para crear campos masivamente
- **Campos por defecto:** Boton para crear 6 campos estandar automaticamente

### Vista previa
En la columna derecha se muestra una previsualizacion de como vera el operador los campos en su formulario.

---

# 5. Preguntas frecuentes

**P: Las fotos que tomo sin conexion, se pierden?**
R: No. Se guardan localmente en el dispositivo y se sincronizan automaticamente cuando recuperes conexion a internet. Mientras tanto, veras un indicador de "fotos pendientes".

**P: Puedo usar la app en iPhone?**
R: Si. Funciona en Safari en iPhone. Puedes instalarla desde el menu "Compartir > Anadir a pantalla de inicio".

**P: Que precision tiene el GPS?**
R: La precision depende del dispositivo y las condiciones. En exterior con buena senal suele ser de 3-10 metros. Las coordenadas se muestran en formato UTM ETRS89.

**P: Puedo cambiar la situacion de una foto despues de tomarla?**
R: Si. Desde "Mis Visitas", pulsa sobre la foto y podras editar la situacion, unidad de obra y observaciones.

**P: Cuantas fotos puedo descargar en el ZIP?**
R: El limite por descarga es de 500 fotos. Si una infraestructura tiene mas, se descargaran las 500 mas recientes.

**P: Que formato tienen los archivos GPX?**
R: Los archivos GPX contienen waypoints con coordenadas WGS84, compatibles con Google Earth, QGIS, Garmin y otros programas de cartografia.

**P: El ghost/fantasma funciona sin conexion?**
R: Si, si previamente has pulsado "Precargar fotos offline" para esa infraestructura. Las fotos se almacenan localmente para uso sin conexion.

**P: Que navegadores son compatibles?**
R: Chrome (Android), Safari (iOS), Edge y Firefox recientes. Se recomienda Chrome en Android para la mejor experiencia.

---

*Manual generado para FotoGPS.app (INFOCAMPO) - Marzo 2026*
