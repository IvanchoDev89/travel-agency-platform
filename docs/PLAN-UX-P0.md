# Plan de implementación — UX P0 (bajo esfuerzo, alto impacto)

## Objetivo
Mejorar la conversión y claridad en el flujo de reserva de tours con cambios pequeños y seguros.

## Tareas

### 1. Calendario seleccionable con feedback
**Archivo:** `single-tap_tour.php`
- Añadir clase `is-selected` al día clickeado.
- Mostrar un panel "Salidas del día seleccionado" debajo del calendario con los horarios y precio.
- Al seleccionar un día, actualizar el widget de reserva (ya implementado) y hacer scroll suave al widget.
- CSS: borde activo, sombra, cursor pointer (ya parcial).

**Riesgo:** bajo. Solo visual/JS.

### 2. Desglose de precio en tiempo real
**Archivo:** `single-tap_tour.php` (JS `calculateTotal`)
- Añadir fila "Adultos × precio" y "Niños × precio" en el widget (ya existe `bw-price-summary`, pero se muestra solo después de seleccionar slot).
- Mostrar el desglose siempre que haya slot seleccionado, incluyendo extras seleccionados.
- Añadir fila "Extras" si hay seleccionados.

**Riesgo:** bajo. Solo visual.

### 3. Empty states con CTA
**Archivos:** templates y dashboard
- Favoritos vacío: ilustración + "Explora tours" → link a búsqueda.
- Reservas vacías: "Aún no tienes reservas" + "Buscar tours".
- Referidos vacío: "Comparte tu enlace" (ya tiene CTA).
- Listados vacío en agencia: "Crea tu primer tour" → link a nuevo.

**Riesgo:** bajo.

## Estimación
- Tarea 1: 0.5 día.
- Tarea 2: 0.5 día.
- Tarea 3: 0.5 día.
- **Total: 1.5 días.**

## Criterios de aceptación
- Calendario clickeable con feedback visual.
- Desglose de precio visible en tiempo real.
- Empty states con CTA.
- PHPStan, i18n, tests en verde.
- No regresiones en suites.
PY