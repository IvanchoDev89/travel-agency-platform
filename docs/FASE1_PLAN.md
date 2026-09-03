# FASE 1 — Registro público multiagencia & flujo de conversión
## Plan técnico detallado (basado en auditoría real del código, v1.3.0)

> Estado del documento: PLAN (para ejecutar tras aprobación).
> Anexa a [`INTEGRATION_PLAN.md`](INTEGRATION_PLAN.md) (estrategia de implantación).

---

## 0. Resumen ejecutivo

El objetivo de producto: convertir el sitio de catálogo manual de una sola agencia en un
**marketplace multiagencia autoservicio** donde los proveedores se registran solos, publican
su catálogo y pagan. Esto elimina la carga manual de tarifas/tours y multiplica el catálogo
e ingresos sin trabajo adicional.

La auditoría revela que **una buena parte ya existe** (registro de agencia, dashboard,
suscripciones, comisiones, destacados, pagos). Lo que bloquea el modelo es:

1. **BUG CRÍTICO de conversión**: el nonce del formulario de reserva no coincide con el que
   verifica el servidor → **todas las reservas front-end fallan**. Hay que arreglarlo primero.
2. **Falta completar el autoservicio**: el gestor de listings solo cubre alojamientos.
3. **Falta registro de clientes / guest checkout**: no hay público que reserve sin cuenta.
4. **Falta monetización por lead** (contacto a agencias).

---

## 1. Prioridades (orden de ejecución)

### 🔴 P1 — Bug crítico de nonce en reservas (BLOQUEANTE)
Ruta de negocios principal = reserva → pago. Sin esto, todo el flujo monetario está roto.

**Archivos:**
- `includes/class-ajax.php:6` — `create_booking()` usa `check_ajax_referer('tap_booking_nonce', 'nonce')`
- `includes/class-ajax.php:60` — `cancel_booking()` igual
- `includes/class-shortcodes.php:~613` — el JS envía `&nonce=' + tap_ajax.nonce`

**Diagnóstico (verificado con PHP):**
- Servidor espera `$_POST['nonce']` generado con acción `tap_booking_nonce`.
- JS envía `tap_ajax.nonce` = `wp_create_nonce('tap_nonce')` → NO verifica.

**Fix propuesto (2 opciones):**
- Opción A (mínima y segura): que `create_booking`/`cancel_booking` verifiquen contra la
  acción del nonce que realmente se envía: cambiar a `check_ajax_referer('tap_nonce', 'nonce')`.
- Opción B (consistente con el resto): en el JS, enviar un nonce con acción
  `tap_booking_nonce`. Pero el JS de booking usa `tap_ajax.nonce` compartido; la opción A es
  la más limpia y no rompe otros usos.

**Verificación:** test AJAX real que simule la petición de reserva y compruebe que pasa el
nonce. Añadir a `suite_bookings.php` un test que golpee `TAP_Ajax::create_booking()` con el
nonce correcto.

---

### 🟠 P2 — Completar el autoservicio de proveedores
El proveedor debe poder dar de alta **todos** los tipos de servicio (tour, transport, coche,
barco, paquete, alojamiento), no solo alojamiento.

**Archivos:**
- `includes/class-shortcodes.php`: `render_manage_*` de `[tap_agency_manage]` (solo
  `tap_accommodation` hoy)
- `includes/class-ajax.php`: `agency_save_listing`, `agency_save_room`, `agency_delete_room`
  (validan tipo → ampliar a los 6 tipos)
- `includes/class-metaboxes.php`: reutilizar los `field_group` por tipo para el editor público

**Alcance propuesto:**
- Editorial público genérico por tipo de servicio (campos base + específicos por tipo).
- Carga de imágenes/gallery ya soportada (`upload_files` presente en rol).
- Límites según plan (conteo de listings publicados vs. cuota del plan).

---

### 🟠 P3 — Captación de clientes: reserva accesible
Para que el marketplace genere comisiones, **cualquier visitante debe poder reservar** sin
fricción.

**Alcance:**
- **Guest checkout**: permitir reserva sin login, pidiendo nombre/email/teléfono en el
  formulario, y crear un usuario cliente automáticamente (o almacenar datos de huésped ya
  soportados: `guest_name/email/phone` en la tabla de booking).
- Actualmente `client_id` = `get_current_user_id()`; para guest, `client_id` puede ser 0 y
  usar `guest_email` para localizar la reserva.
- **Registro de cliente**: formulario simple de cuenta de viajero que agilice futuras reservas.

---

### 🟢 P4 — Monetización por lead (contacto a agencias)
Nueva fuente de ingresos: cuando un cliente envía un mensaje/presupuesto a una agencia, se
registra un **lead** y opcionalmente se cobra/basura su visibilidad.

**Alcance (nuevo):**
- Tabla `tap_leads` (o reutilizar patrón de `tap_payment_orders`).
- Formulario de contacto/presupuesto en la página de agencia y en el detalle de servicio.
- Dashboard de agencia: ver y descargar sus leads.
- Modelo de cobro: lead por contacto / paquete de leads (decisión de negocio a fijar).

---

## 2. Dependencias y orden de clases

El plugin carga clases en orden (travel-agency-platform.php `includes()`). Una nueva clase o
lógica debe insertarse respetando:

- `TAP_Ajax` (prioridad 10 en init? ya registrado) — para los maneadores AJAX nuevos.
- `TAP_Shortcodes` — para form/páginas públicas.
- `TAP_Installer` — para nuevas tablas/migraciones (ej. `tap_leads`).

---

## 3. Seguridad (no negociable)

- **Nonces** en TODA acción AJAX de escritura (ya hay `tap_nonce`, `tap_agency_nonce`,
  `tap_register_nonce`, `tap_agency_listing_nonce`, `tap_plan_nonce`).
- Sanitización ya presente en `agency_register` (good). Replicar en los nuevos editores.
- Acciones de escritura de agencia deben verificar **propiedad**: solo el admin de la agencia
  (o el admin global) puede editar los posts de SU agencia. Existe `agency_owns_accommodation`
  como patrón a generalizar.
- Honeypot `tap_hp` ya presente en formularios.

---

## 4. Estados de verificación de agencia

- Agencia nueva = **no verificada** (`_tap_agency_verified` vacío), **no aparece** en el
  directorio público `[tap_agencies]`.
- **Cola de moderación**: el admin revisa nuevos registros (hook `tap_agency_registered` ya
  existe) y marca verificado. Decisión pendiente: ¿activación automática tras email confirmado
  o aprobación manual? Se recomienda **doble opt-in por email + aprobación admin** para
  calidad del marketplace.

---

## 5. Fuera de alcance de esta fase (siguientes)

- Multilingüe EN (Fase 3): traducciones y contenido bilingüe.
- IA/chatbot de atención y moderación (Fase 4).
- Importación/automatización de catálogos externos.

---

## 6. Criterios de aceptación (Definition of Done)

1. `suite_bookings` verde y NUEVO test que cubra el nonce de reserva front-end (P1).
2. Un usuario puede registrarse como agencia (ya funciona) y publicar al menos un servicio de
   cada tipo desde el front-end (P2).
3. Un visitante puede completar una reserva sin cuenta (P3).
4. Un cliente puede contactar a una agencia y esto genera un lead visible en el dashboard (P4).
5. Todas las suites pasan en travel y ivanchodev; docs y CHANGELOG actualizados; commit.

---

## 7. Riesgos

| Riesgo | Mitigación |
|---|---|
| El fix de nonce podría colisionar con otros usos de `tap_ajax.nonce` | Revisar usos existentes de `tap_ajax.nonce` (paypal, favorites) antes de cambiar |
| Ampliar el gestor a 6 tipos aumenta complejidad de validación | Metadata por tipo ya mapeada en `get_price_key` / `field_group`; reutilizar |
| Guest checkout mal implementado (reservas sin rastrear usuario) | Asegurar `guest_email` y código de reserva como llave; emails de confirmación |
| Verificación manual de agencias = cuello de botella | Automatizar con cola + notificaciones, luego robustecer |
