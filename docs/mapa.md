# Cómo funciona el mapa (Panel → Mapa)

## Qué muestra

- **Cada punto es un edificio** de tu empresa. No hay otros tipos de marcador.
- **Dónde está el punto:** en la dirección cargada en el edificio. Ascento la
  convierte en coordenadas una sola vez (geocodificación con Geoapify) y las
  guarda. Si la dirección no alcanza, el admin marca el lugar a mano
  ("Corregir ubicación" o "Marcar en el mapa"). La ventana del punto dice cuál
  de los dos casos es.
- **Círculo con número:** son varios edificios cercanos juntos. Al tocarlo o
  acercarte se separan.
- **Color**, según "Colorear por":
  - **color asignado**: el que elegís en cada edificio;
  - **estado del mes**: si el mantenimiento del mes está hecho, pendiente, no
    realizado o sin técnico (sale de la agenda de mantenimientos).
- **Técnico:** el **asignado** al edificio para mantenimiento o inspección.
  **No** es su posición.
- **Zona:** un texto libre que cargás en el edificio para agrupar la cartera
  (por ejemplo "Zona Norte"). Ascento no inventa zonas.

## Qué NO muestra

- **No hay seguimiento GPS ni posiciones en vivo de técnicos.** Ascento no
  recolecta la ubicación de nadie. Sumarlo sería una decisión de producto y de
  privacidad aparte.
- No se actualiza solo. Los datos son los del momento en que abriste la
  página; la excepción son los edificios que se están ubicando, que aparecen a
  medida que se encuentran.

## Filtros

Buscar (nombre, dirección, cliente, zona o técnico), cliente, zona, técnico
asignado y estado del mes. Los edificios sin coordenadas aparecen abajo, en
"Edificios sin ubicar", para corregirlos.

## Quién lo ve

Solo los admins de la empresa (o el SuperAdmin dentro de una empresa) y solo
los edificios de esa empresa. Los técnicos y los clientes del portal no
tienen acceso.
