# Node Bulk Delete

Este módulo permite la eliminación masiva de nodos en Drupal 10 según el tipo de contenido y un rango de fechas seleccionado. Incluye una interfaz administrativa para seleccionar los filtros y ejecutar la eliminación o una simulación (dry run) con generación de archivos CSV.

## Características

- **Eliminación masiva por filtros**: Selecciona tipo de contenido y rango de fechas
- **Modo simulación (Dry Run)**: Permite previsualizar qué nodos se eliminarían sin ejecutar la eliminación
- **Generación de archivos CSV**: Crea archivos CSV con ID y path de los nodos procesados
- **Protección de contenido**: Excluye automáticamente el tipo de contenido "noticias"
- **Contadores en tiempo real**: Muestra cantidad de nodos totales y a eliminar mediante AJAX

## Estructura

- `node_bulk_delete.info.yml`: Información del módulo
- `node_bulk_delete.routing.yml`: Definición de rutas
- `node_bulk_delete.links.menu.yml`: Enlaces del menú administrativo
- `node_bulk_delete.services.yml`: Definición de servicios
- `src/Form/NodeBulkDeleteForm.php`: Formulario administrativo principal
- `src/Service/NodeBulkDeleteService.php`: Lógica de consulta y eliminación de nodos

## Requerimientos

- Drupal 10.x
- PHP 8.1+
- Permisos de escritura en el directorio `files/` (archivos públicos)

## Instalación

1. Copie la carpeta `node_bulk_delete` en `web/modules/custom/`
2. Active el módulo desde la interfaz de administración de Drupal o usando Drush:
   ```bash
   drush en node_bulk_delete
   ```

## Uso

### Acceso al formulario
Acceda al formulario en `/admin/content/node-bulk-delete` o navegue a:
**Administración** → **Contenido** → **Eliminación masiva de nodos**

### Pasos para usar el módulo

1. **Seleccionar tipo de contenido**: Elija el tipo de contenido que desea procesar
2. **Configurar rango de fechas**: Establezca fecha de inicio y fecha fin
3. **Revisar contadores**: El sistema mostrará automáticamente:
   - Cantidad total de nodos del tipo seleccionado
   - Cantidad de nodos que se eliminarían con los filtros aplicados
4. **Ejecutar acción**:
   - **Simular eliminación (Dry Run)**: Genera CSV sin eliminar nodos
   - **Eliminar nodos**: Elimina nodos y genera CSV con los procesados

### Archivos CSV generados

Los archivos CSV se generan automáticamente en la carpeta pública de Drupal (`sites/default/files/`) con la siguiente estructura:

#### Formato del archivo
- **Columnas**: Node ID, Path
- **Nombre**: `[prefijo]_YYYY-MM-DD_HH-MM-SS.csv`
- **Prefijos**:
  - `dry_run_nodes_`: Para simulaciones (generado antes del procesamiento)
  - `deleted_nodes_`: Para eliminaciones reales (generado antes de eliminar)

#### Momento de generación
- **Simulación (Dry Run)**: El CSV se genera **inmediatamente** al iniciar el batch
- **Eliminación real**: El CSV se genera **antes** de comenzar la eliminación para garantizar la captura de datos

#### Ejemplo de contenido CSV
```csv
Node ID,Path
123,/articulo/mi-articulo
124,/pagina/mi-pagina
125,/evento/mi-evento
```

## Consideraciones técnicas

### Filtros de fecha
- Las fechas se basan en el campo `created` (fecha de creación) de los nodos
- La fecha de inicio se procesa con hora 00:00:00
- La fecha de fin se procesa con hora 23:59:59
- Todas las fechas se convierten a timestamp UTC

### Protecciones implementadas
- **Exclusión automática**: El tipo de contenido "noticias" está excluido por defecto
- **Validación de datos**: Se validan todos los campos antes de procesar
- **Procesamiento por lotes**: Utiliza Batch API para evitar timeouts y errores de memoria

### Rendimiento y optimización
- **Batch API**: Procesa nodos en lotes para manejar grandes volúmenes sin timeouts
- **Eliminación optimizada**: Utiliza consultas SQL directas en lugar de Entity API para máximo rendimiento
- **Tamaño de lotes configurables**:
  - **20 nodos por lote** para eliminación real (balanceando velocidad y estabilidad)
  - **50 nodos por lote** para simulación (más rápido al no eliminar realmente)
- **Limpieza completa**: Elimina automáticamente revisiones, datos de campo y cache relacionado
- **Monitoreo en tiempo real**: Muestra tiempo de ejecución por lote en milisegundos

### Arquitectura del procesamiento
- **Eliminación directa por SQL**: Evita hooks lentos y carga innecesaria de entidades
- **Limpieza manual de tablas**: Elimina datos de `node`, `node_revision` y tablas de campo
- **Reset de cache**: Limpia automáticamente el cache de nodos para consistencia
- **Manejo de errores**: Try-catch por lote con logging detallado

### Permisos requeridos
- Acceso a las páginas de administración de contenido
- Permisos para eliminar nodos del tipo seleccionado
- Permisos de escritura en el directorio de archivos públicos

## Solución de problemas

### Error "502 Bad Gateway"
- Verifique que el servidor tenga suficiente memoria y tiempo de ejecución
- Considere procesar menos nodos por lote

### CSV no se genera
- Verifique permisos de escritura en `sites/default/files/`
- Confirme que el directorio público esté configurado correctamente

### Contadores no se actualizan
- Verifique que JavaScript esté habilitado
- Confirme que las consultas AJAX no estén siendo bloqueadas

## Desarrollo

### Extender funcionalidad
Para agregar nuevos filtros o modificar el comportamiento:

1. Modifique `NodeBulkDeleteService.php` para nuevas consultas
2. Actualice `NodeBulkDeleteForm.php` para nuevos campos de formulario
3. Ajuste las validaciones según sea necesario

### Logging
El módulo incluye logging detallado para depuración. Los logs se escriben en el canal `node_bulk_delete`.