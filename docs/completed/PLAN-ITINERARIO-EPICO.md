# Plan: Itinerario épico (planificador de viajes)

> **Estado:** ✅ Completado (2026-11-05)

## Visión
Convertir el planificador de itinerarios en un **generador de reservas grupales** que permita armar un viaje completo (tours + alojamientos + transporte) y reservarlo todo en un solo checkout, con colaboración y compartición.

## Estado actual
- Shortcode `[tap_itinerary_builder]` con wizard de intereses/destinos/precio.
- Genera link compartible `?itinerary=...`.
- No reserva servicios directamente.
- No persiste borradores.

## Fases

### Fase 1 — Simplificación del flujo (1 semana)
1. **Flujo en 3 pasos**:
   - Paso 1: Elegir fechas y destino.
   - Paso 2: Agregar actividades (tours/alojamientos/transporte) con drag & drop.
   - Paso 3: Revisar y reservar.
2. **Búsqueda inline**: buscar servicios por tipo/fecha y añadir al día.
3. **Presupuesto en tiempo real**: total estimado actualizado al agregar/quitar.
4. **Persistencia**: guardar borrador en localStorage + user meta si logueado.

### Fase 2 — Integración con reservas (1 semana)
1. **Reserva grupal**: al confirmar, crear múltiples bookings (uno por servicio) vinculados por un `trip_id`.
2. **Checkout unificado**: un solo pago para todos los servicios.
3. **Voucher unificado**: un código de viaje que agrupa todos los bookings.
4. **Cancelación grupal**: cancelar todo el viaje con un click.

### Fase 3 — Colaboración y compartición (1 semana)
1. **Link compartible** con preview (imagen, resumen, precio).
2. **CTA "Reservar todo"** en la vista compartida.
3. **Colaboración**: invitar amigos por email para votar/agregar actividades.
4. **Comentarios** por actividad.

### Fase 4 — Exportación y extras (0.5 semana)
1. **Exportar a PDF** (itinerario completo).
2. **Exportar a Google Calendar / iCal**.
3. **Compartir en redes sociales** (WhatsApp, Facebook).
4. **Mapa interactivo** con todas las paradas.

### Fase 5 — IA y sugerencias (1 semana)
1. **Sugerencias automáticas**: "Si te gusta X, también Y".
2. **Optimización de ruta**: ordenar actividades por distancia.
3. **Presupuesto inteligente**: alertar si se excede el presupuesto.

## Arquitectura

### Modelo de datos
- Nueva tabla `tap_trips` (id, user_id, title, start_date, end_date, budget, share_code, created_at).
- Nueva tabla `tap_trip_items` (id, trip_id, service_type, service_id, day, sort_order, booking_id).
- `tap_bookings` gana columna `trip_id` (nullable).

### Flujo de reserva grupal
1. Usuario crea trip → items.
2. Al reservar: para cada item, llamar `TAP_Booking::create()` con `trip_id`.
3. Pago único: sumar todos los totales + fee.
4. Al pagar: marcar todos los bookings como paid.
5. Voucher: un código `TRIP-XXXX` que agrupa todos.

### Colaboración
- `tap_trip_collaborators` (trip_id, user_id, role: owner/editor/viewer).
- Invitar por email → link con token.
- Votación por actividad (upvote/downvote).

## KPIs
- % de usuarios que completan el itinerario.
- % de itinerarios que se convierten en reserva.
- Valor promedio de reserva grupal vs. individual.
- Tasa de compartición.

## Riesgos
- Complejidad de reserva grupal (múltiples servicios, disponibilidad).
- UX denso en mobile.
- Performance (muchas queries).

## Mitigaciones
- Limitar a 10 items por viaje.
- Cache de disponibilidad.
- Mobile-first con vista de lista.

## Estimación total
- Fase 1: 1 sem
- Fase 2: 1 sem
- Fase 3: 1 sem
- Fase 4: 0.5 sem
- Fase 5: 1 sem
- **Total: 4.5 semanas**

## Próximo paso
Implementar Fase 1 (simplificación del flujo) si se aprueba.