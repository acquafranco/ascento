# Rendimiento: qué se midió y qué se cambió (Etapa 3)

## Cómo se midió

Prueba temporal con MySQL 8 local y una empresa del tamaño máximo del plan
Empresa:
- 300 edificios con coordenadas;
- 420 clientes;
- 25 técnicos, uno de ellos asignado a los 300 edificios (el peor caso);
- 1500 reportes con 4500 fotos, 1500 órdenes, 150 visitas con remito y 200
  presupuestos;
- 300 empresas más en la plataforma.

Por pantalla se contaron las consultas, los modelos cargados, el tiempo, la
memoria y el tamaño de la respuesta.

Los tiempos son de un entorno de desarrollo (con depuración) y sirven para
comparar antes y después, no como tiempos de producción. El volumen es
exagerado a propósito: todo cae dentro de los últimos 90 días.

## Resultados

| Pantalla | Modelos (antes → después) | Consultas | Tiempo | HTML |
|---|---|---|---|---|
| Centro de atención | 17.436 → 2.706 | 77 → 35 | 1227 → 365 ms | = |
| Indicadores | 15.051 → 4.231 | 159 → 64 | 1211 → 454 ms | = |
| Agenda | 3.310 → 1.505 | 24 → 17 | 373 → 206 ms | 610 → 170 KB |
| Visitas del técnico (300 edificios) | 976 → 85 | 17 → 16 | 118 → 48 ms | = |
| Mapa | 2.412 → 2.111 | 29 | 498 → 261 ms | = |
| Listados del panel (edificios, clientes, reportes…) | ~320 → 13-72 | = | ~10-30 % menos | = |
| Clientes (vista vieja `/{empresa}/clients`) | 423 → 32 | 4 → 5 | 71 → 44 ms | 169 → 30 KB |

## Qué se cambió

- **Agenda de mantenimientos (`MaintenanceAgenda`).** Edificios, visitas y
  contratos se cargan una sola vez por solicitud, por empresa y por mes.
  - Antes, el Centro de atención la recalculaba 9 veces, Indicadores 7 y el
    mapa 1, cada vez con todos los edificios.
  - La memoria vive en la solicitud web: en consola, colas o llamadas
    directas siempre se consulta de nuevo.
  - La agenda de un técnico consulta solo sus edificios (asignados o
    visitados ese mes), no los de toda la empresa.
- **Agenda (página).** Pagina de a 50 filas. El resumen por estado y el orden
  "primero lo urgente" siguen calculándose sobre el mes completo. Cambiar un
  filtro vuelve a la página 1.
- **Indicadores.** La actividad por técnico se calcula con 3 consultas en
  total; antes eran 3 por técnico.
- **Análisis de fallas.** La misma ventana de 90 días se carga una vez por
  solicitud, con filas simples en lugar de modelos.
- **Centro de atención.** Las fichas de ascensor incompletas se cuentan en la
  base (`Elevator::incomplete()`). El filtro "Ficha incompleta" del listado
  usa la misma regla: ahora también cuenta los campos de texto vacíos, así el
  número del aviso y la lista coinciden.
- **Visitas del técnico.** Las máquinas asignadas, hechas y pendientes se
  suman en la base. Antes se traían tres veces todos los edificios asignados,
  más las visitas con su edificio.
- **Guía de bienvenida.** La lista de primeros pasos, que se arma en cada
  página del panel, usaba los ids de todos los edificios en un `IN`. Ahora
  son dos consultas `exists`.
- **Fotos.** La tabla de reportes del panel, la ficha del reporte y la
  pestaña de fotos muestran miniaturas por la misma ruta autenticada; la
  foto original se abre al tocarla. Antes la tabla mostraba la original (de
  hasta 2000 px) en 60 px. La app del técnico y el portal ya usaban
  miniaturas.
- **Clientes (vista vieja).** Paginada de a 30, solo con las columnas que
  muestra.

## Lo que se decidió no cambiar

- **Mapa: se siguen mandando todos los marcadores.** El plan más grande
  admite 400 edificios (300 cuando se midió): unos 200 KB sin comprimir, mucho menos comprimido.
  Los filtros de color, zona, cliente, técnico y estado trabajan en el
  navegador sobre todos los marcadores. Cargar por zona del mapa los rompería
  y agregaría esperas al mover el mapa. Si algún día se superan los ~1000
  edificios, conviene pasar a cargar por zona.
- **Resúmenes del SuperAdmin.** Cargan todas las empresas con su última
  suscripción y su dueño en 3 consultas, sin N+1. El estado se calcula con
  las mismas reglas de acceso que la app; pasarlo a SQL duplicaría esas
  reglas. Crece con la cantidad de clientes de Ascento, no con los datos de
  cada uno.
- **Índices.** Las mediciones no mostraron consultas lentas por falta de
  índice: lo que pesaba era cargar de más o repetir cargas. No se agregaron
  migraciones.
- **Listados de Filament y de la app del técnico.** Ya paginaban en el
  servidor y la prueba de N+1 existente los cubre.

## Pruebas

`tests/Feature/Performance/ScreenLoadTest.php`, además de la prueba de N+1
que ya existía. Cubren:
- que las consultas no crecen con la cantidad de edificios o técnicos;
- que la agenda se carga una vez por solicitud y nunca queda vieja fuera de
  una;
- que los totales del técnico siguen las mismas reglas;
- paginación, filtros y búsqueda;
- miniaturas con su control de acceso;
- aislamiento entre empresas.
