# Cort Elementor Global Styles 1.0.0

Desarrollo Digital MX · WordPress 6.4+ · PHP 7.4+

El plugin analiza una plantilla JSON de Elementor o un documento guardado. Genera tokens de color y tipografía y propuestas para los ajustes nativos del Kit activo. La aplicación y la conversión opcional de widgets son acciones separadas con vista previa y respaldo.

## Instalación y análisis

1. En WordPress, abre **Plugins → Añadir plugin → Subir plugin**. Instala el archivo `cort-elementor-global-styles-v1.0.0.zip` y actívalo.
2. Abre **Elementor → Cort Global Styles**. Si el panel carece del menú Elementor, abre **Ajustes → Cort Global Styles**.
3. Sube el JSON original de la portada y pulsa **Analizar JSON**, o elige una página o plantilla guardada y pulsa **Analizar documento**. El archivo debe estar en formato de exportación de Elementor y pesar hasta 8 MiB. Si el servidor limita las subidas a menos, aplica ese límite.
4. Abre los detalles del inventario y la propuesta. **Descargar reporte JSON** conserva la evidencia original de la referencia. El análisis de un documento existente resuelve sus referencias desde el Kit activo. Un archivo de otro sitio necesita incluir los valores de sus tokens para que el inventario pueda resolverlos.

## Aplicar Global Colors, Global Fonts y Theme Style

1. Revisa las tablas y modifica los valores que necesites. Los tokens personalizados extraídos se preseleccionan. Marca manualmente cada token de sistema, estilo de Theme Style o inferencia que quieras aplicar. Un token con igual nombre y otro valor se conserva cuando pertenece al sitio.
2. Pulsa **Vista previa de cambios**. Verifica los valores actuales, nuevos y omitidos. Los controles que la versión instalada no registra aparecen como omitidos.
3. **Crear respaldo** guarda el estado previo para esa vista. **Aplicar estilos globales** crea un respaldo automáticamente cuando todavía hace falta y guarda los cambios en el Kit activo.
4. Abre **Site Settings** en el editor para comprobar **Global Colors**, **Global Fonts**, **Theme Style** y **Layout Settings**. Elementor reconstruye el CSS cuando se vuelven a cargar las páginas. **Regenerar CSS de Elementor** invalida esos archivos de nuevo si necesitas repetir la operación.

Theme Style establece valores predeterminados. Los widgets pueden conservar colores, tipografías y espaciados locales. Los ajustes globales de sistema pueden afectar a otras plantillas que ya heredan esos tokens. Revisa esos cambios y el ancho global en staging antes de aplicarlos en producción. La configuración de Elementor sobre fuentes y colores predeterminados se gestiona desde su panel habitual.

## Vincular widgets

1. En **Vincular widgets a estilos globales**, elige la portada o una plantilla y pulsa **Vista previa de vínculos**.
2. Revisa los elementos y controles que coinciden con un token del mismo uso. Las tipografías requieren coincidencia exacta de familia, tamaño, peso, línea y valores responsive explícitos. Una coincidencia ambigua, un valor distinto o una referencia existente se omiten.
3. Pulsa **Aplicar vínculos al documento**. El plugin agrega referencias `__globals__` y conserva los controles locales como respaldo. La operación escribe únicamente el documento seleccionado. Repite para otras plantillas si corresponde.

## Restaurar

Abre **Respaldos e informes**, identifica la operación y pulsa **Restaurar ajustes anteriores**. Si un ajuste cambió después de la aplicación, se omite y queda registrado. Si un token nuevo tiene referencias en otros documentos, restaura esos vínculos antes de intentar retirarlo. Los respaldos se conservan al desactivar o eliminar el plugin. El Kit, las fuentes, los colores y los vínculos aplicados también se conservan.

## Compatibilidad y persistencia

- Las operaciones de Kit requieren Elementor **3.24 a 4.x**, un Kit activo y un usuario administrador con permiso para editarlo. El análisis por archivo funciona con Elementor inactivo. La UI informa el estado de Elementor Pro. Los estilos de widgets Pro dependen de que dichos widgets estén disponibles en el sitio.
- La herramienta lee las APIs de documento y Kit de Elementor y valida cada escritura contra sus controles registrados. Los ajustes y elementos se guardan en los metadatos nativos `_elementor_page_settings` y `_elementor_data` mediante las APIs de WordPress. Se utilizan claves verificadas, comprobación del estado previo, respaldo y verificación posterior. La escritura acotada evita que el guardado general de documentos regenere el contenido de WordPress.
- Se usa el gestor de archivos de Elementor para invalidar la caché. Los kits exportados por Elementor sirven como respaldo alternativo del sitio desde sus propias herramientas. Este plugin modifica el Kit activo y no importa un kit completo.
- Se analizan los controles clásicos compatibles. Se omiten referencias dinámicas sin resolver, unidades CSS arbitrarias y controles no reconocidos. Los estilos del frontend quedan en controles y referencias nativos de Elementor.

[Global Styles](https://developers.elementor.com/docs/data-structure/global-styles/) · [Theme Style](https://elementor.com/help/theme-style-global-settings/) · [Exportar un kit](https://elementor.com/help/import-and-export-elementor-website-templates/)

## Pruebas

Se instaló y activó el ZIP en WordPress **7.1.5** y PHP **8.3** con Elementor **4.4.1**. Se utilizó **PRO Elements 4.4.0** para comprobar los controles de widgets Pro en el entorno de prueba.

La referencia produjo 18 valores de color y 14 perfiles tipográficos. La prueba aplicó 16 colores, 14 fuentes y 98 controles de Theme Style y Layout. Elementor generó el CSS nativo. También se verificó que 126 referencias de color y 37 referencias tipográficas coincidieran con sus estilos locales. La prueba de restauración conservó una edición ajena al plan y protegió los tokens con referencias activas.

También se probó el recorrido del panel con una sesión de administrador. Se verificaron la subida de JSON, el análisis de una página, la descarga, las vistas previas, la creación y restauración de respaldos, la vinculación y el acceso de solo análisis con Elementor desactivado. WordPress rechazó una solicitud con nonce inválido.

El control global de color de placeholder no estuvo disponible en el Kit probado. El inventario sí conserva el valor del buscador y la vista previa indica el control omitido. La revisión visual del sitio y la prueba con la instalación licenciada de destino quedan pendientes.

El archivo `tests/analyze.php` contiene una prueba de solo lectura que puedes ejecutar con WP-CLI desde una instalación con el plugin activo.

```sh
wp eval-file wp-content/plugins/cort-elementor-global-styles/tests/analyze.php /ruta/crm-knowledge-base-homepage.json
```
