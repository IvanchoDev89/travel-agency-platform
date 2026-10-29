# Plan: Tours y Experiencias — entrada completa para agencias

## Objetivo
Permitir a las agencias publicar **cualquier tipo de actividad** (retiros de yoga, kayak, senderismo, etc.) con:
- Tarifas con calendario de disponibilidad (fechas y horas).
- Tours grupales con capacidad, mínimo/máximo de participantes.
- Servicios extra (add-ons) con precio.
- Términos y condiciones, política de privacidad, waiver (descargo de responsabilidad) con firma digital.
- Mapa del punto de encuentro / salida del tour.
- Enfoque en ecoturismo (dificultad, impacto ambiental, leave-no-trace).

## Estado actual (2026-10-29)
- Post types `tap_tour` y `tap_experience` existen con metaboxes enriquecidos (Fase 1 ✅).
- Multi-step wizard (6 pasos) para entrada de tours/experiencias ✅.
- `tap_daily_pricing` es solo para rooms (room_id). No soporta tours.
- `tap_pricing_rules` soporta service_type/service_id (yield pricing) ✅.
- Map picker (Leaflet) ya existe para ubicación ✅.
- T&C/privacidad/waiver existen como campos de texto ✅.
- **Falta**: calendario de disponibilidad para tours, waiver digital con firma, extras en checkout, frontend del tour.

---

## Diseño arquitectónico (decisión de ingeniería senior)

### Principio rector
> **Una sola fuente de verdad para disponibilidad y precio por fecha.**
> Reusar `tap_daily_pricing` extendiéndolo con `service_type`/`service_id`/`time_slot`,
> en lugar de crear una tabla paralela. Esto evita divergencia de datos y simplifica
> reportes, yield pricing y el booking flow.

### 1. Modelo de datos — extensión de `tap_daily_pricing`

Columnas nuevas (migración idempotente):

| Columna | Tipo | Propósito |
|---------|------|-----------|
| `service_type` | VARCHAR(50) NOT NULL DEFAULT 'tap_accommodation' | Tipo de servicio (tap_tour, tap_experience, tap_accommodation) |
| `service_id` | BIGINT UNSIGNED NOT NULL DEFAULT 0 | ID del post del servicio |
| `time_slot` | TIME NULL | Hora de inicio del slot (NULL = todo el día, para rooms) |
| `time_end` | TIME NULL | Hora de fin del slot |
| `capacity` | INT UNSIGNED NOT NULL DEFAULT 0 | Capacidad del slot (0 = ilimitada / usa la del servicio) |
| `booked` | INT UNSIGNED NOT NULL DEFAULT 0 | Reservas confirmadas en el slot |
| `label` | VARCHAR(100) (ya existe) | Etiqueta (ej. "Mañana", "Tarde") |

**Migración:**
1. `ALTER TABLE ... ADD COLUMN IF NOT EXISTS ...`
2. Backfill: `UPDATE tap_daily_pricing SET service_type='tap_accommodation', service_id=room_id WHERE service_type IS NULL OR service_type=''`
3. Índice: `CREATE INDEX idx_service_date ON tap_daily_pricing(service_type, service_id, date, time_slot)`
4. Mantener `room_id` por compatibilidad (deprecated pero no eliminado).

**Semántica:**
- Un tour con horario fijo (ej. 8:00-12:00) crea un slot con time_slot=08:00:00, time_end=12:00:00.
- Un tour "todo el día" crea un slot con time_slot=NULL.
- Un tour recurrente (cada martes/jueves) crea slots para cada fecha del rango.
- Capacidad: si capacity=0, se usa `_tap_tour_capacity` del servicio.
- Precio: si price IS NULL, se usa el precio base del servicio (price_adult). Si price tiene valor, es override para esa fecha/slot.

### 2. Clase `TAP_Tour_Availability`

Responsabilidades:
- `slots_for($service_type, $service_id, $from, $to)` — lista slots en rango.
- `get_slot($id)` — un slot.
- `create_slot($data)` — crear slot.
- `update_slot($id, $data)` — actualizar.
- `delete_slot($id)` — eliminar.
- `book_slot($slot_id, $adults)` — incrementar booked (con lock).
- `release_slot($slot_id, $adults)` — decrementar (cancelación).
- `is_available($slot_id, $adults)` — verificar disponibilidad.
- `generate_recurring($service_id, $rule)` — generar slots recurrentes.

**Reglas de negocio:**
- No se puede reservar más de la capacidad disponible.
- Un slot bloqueado (is_blocked=1) no se puede reservar.
- Cancelación libera el slot.
- Overbooking configurable (porcentaje, como rooms).

### 3. UI de calendario (admin + agencia)

**Para el admin (admin-post o metabox):**
- Calendario mensual (reusar el componente de rooms).
- Click en fecha → modal para crear/editar slot (hora inicio, hora fin, capacidad, precio override, bloquear).
- Vista de capacidad por slot (booked/capacity).
- Generador de recurrencia: "cada martes y jueves a las 8:00 por 3 meses".

**Para la agencia (front-dash):**
- Misma UI pero scoped a sus servicios.
- Solo puede editar slots de sus tours.

### 4. Integración en booking flow

En `TAP_Booking::create()`:
- Si service_type es tap_tour o tap_experience:
  - Verificar que el slot existe y tiene capacidad.
  - Llamar `TAP_Tour_Availability::book_slot()`.
  - Guardar `slot_id` en `tap_bookings` (nueva columna `slot_id`).
- En cancelación: liberar slot.

### 5. Waiver digital (Fase 3)

**Tabla `tap_waivers`:**
- id, booking_id, user_id, guest_email, waiver_version, waiver_text_hash, signed_at, signature_data (base64 canvas), ip_address, user_agent.

**Flujo:**
1. Al crear reserva de tour con waiver_required=1, marcar booking como `pending_waiver`.
2. Mostrar página de firma (canvas HTML5 + checkbox de aceptación).
3. Al firmar, guardar waiver y cambiar booking a `pending` (listo para pago).
4. Admin puede ver waivers firmados.

**Versionado:** cada waiver tiene una versión (hash del texto). Si el texto cambia, el waiver anterior queda inválido.

### 6. Servicios extra (Fase 4)

**Tabla `tap_service_extras`:**
- id, service_type, service_id, name, description, price, currency, is_per_person, is_required, sort_order, is_active.

**UI:** repetidor en metabox (agregar/quitar/reordenar extras).

**Checkout:** mostrar extras opcionales, sumar al total, registrar en `tap_booking_items`.

### 7. Frontend del tour (Fase 5)

**Template `single-tap_tour.php`:**
- Hero con imagen, título, categoría, dificultad, duración, rating.
- Calendario de disponibilidad (slots clickeables).
- Mapa (Leaflet) con meeting point.
- Inclusiones/exclusiones/qué traer.
- Extras disponibles.
- T&C, privacidad, waiver (colapsables).
- Reviews.
- Schema.org TouristAttraction / Event.

### 8. Tests (Fase 6)
- Suite para disponibilidad (crear slot, reservar, capacidad, bloqueo, cancelación).
- Suite para waiver (firma, versionado, validación).
- Suite para extras (precio, per-person, required).
- Suite para frontend (render, calendar).

## Orden de implementación recomendado

```
Fase 2a: Migración DB (add columns) ──┐
Fase 2b: Clase TAP_Tour_Availability  │── Secuencia (dependencias)
Fase 2c: Integración booking flow      │
Fase 2d: UI calendario (admin+agencia) ─┘

Fase 3: Waiver digital (depende de Fase 2)
Fase 4: Servicios extra (independiente, puede ir en paralelo con 3)
Fase 5: Frontend (depende de 2, 3, 4)
Fase 6: Tests (continuo)
```

## Estimación
- Fase 2: 2-3 semanas
- Fase 3: 1-2 semanas
- Fase 4: 1 semana
- Fase 5: 2 semanas
- Fase 6: 1 semana (continuo)
- **Total: 7-9 semanas**

## Decisiones pendientes
1. ¿Extender tap_daily_pricing o tabla nueva? → Recomendación: extender.
2. ¿Waiver con firma canvas o solo checkbox? → Recomendación: canvas (más legal) con fallback a checkbox.
3. ¿Extras con inventario o solo precio? → Recomendación: solo precio (inventario = fase futura).
4. ¿Calendario de recurrencia o solo fechas manuales? → Recomendación: ambos (manual + generador de recurrencia).

## Criterios de aceptación
- Agencia puede crear un tour de yoga con fechas, horas, capacidad, extras, T&C, waiver, mapa.
- Cliente puede ver disponibilidad, reservar, firmar waiver, pagar.
- Admin puede ver waivers firmados y disponibilidad.
- PHPStan 3, tests, i18n, phpcs en verde.
- No regresiones en suites existentes.
PY