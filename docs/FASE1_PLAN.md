# FASE 1 — Registro público multiagencia & flujo de conversión
## Plan técnico detallado (basado en auditoría real del código, v1.3.0)

> Estado del documento: **EN EJECUCIÓN — P1 y P2 COMPLETADOS (verificado en travel e ivanchodev)**.
> Anexa a [`INTEGRATION_PLAN.md`](INTEGRATION_PLAN.md) (estrategia de implantación).
>
> Estado por prioridad:
> - ✅ **P1** — BUG de nonce de reservas: **COMMIT `1d55b17`**, cubierto en `suite_bookings`.
> - ✅ **P2** — Autoservicio de proveedores para los 6 tipos: **implementado** + `suite_agency_manage` verde en ambos sitios.
> - ⬜ **P3** — Guest checkout (siguiente).
> - ⬜ **P4** — Monetización por lead.

---

## 0. Resumen ejecutivo

El objetivo de producto: convertir el sitio de catálogo manual de una sola agencia en un
**marketplace multiagencia autoservicio** donde los proveedores se registran solos, publican
su catálogo y pagan. Esto elimina la carga manual de tarifas/tours y multiplica el catálogo
e ingresos sin trabajo adicional.

La auditoría revela que **una buena parte ya existe** (registro de agencia, dashboard,
suscripciones, comisiones, destacados, pagos). Lo que bloquea el modelo era:

1. ✅ **BUG de conversión**: el nonce del formulario de reserva no coincidía con el que
   verifica el servidor → todas las reservas front-end fallaban. **Arreglado.**
2. ✅ **Falta completar el autoservicio**: el gestor de listings solo cubría alojamientos;
   ahora cubre los 6 tipos. **Completado.**
3. ⬜ **Falta registro de clientes / guest checkout**: no hay público que reserve sin cuenta.
4. ⬜ **Falta monetización por lead** (contacto a agencias).

---

## 1. Prioridades (orden de ejecución)

### 🔴 P1 — Bug crítico de nonce en reservas (BLOQUEANTE) ✅ COMPLETADO
Ruta de negocios principal = reserva → pago. Sin esto, todo el flujo monetario está roto.

**Implementado (commit `1d55b17`):** `create_booking()` ahora acepta `tap_nonce` **o**
`tap_booking_nonce` (había dos formularios: el shortcode `[tap_booking_form]` envía
`tap_nonce`; el template `single-tap_accommodation.php` envía `tap_booking_nonce`).
`cancel_booking` se mantuvo con `tap_booking_nonce` (su JS lo usa correctamente).

**Verificación:** tests de regresión en `suite_bookings` (ambos nonces aceptados + nonce
falso rechazado). Verde en travel e ivanchodev.

---

### 🟠 P2 — Completar el autoservicio de proveedores ✅ COMPLETADO
El proveedor debe poder dar de alta **todos** los tipos de servicio (tour, transport, coche,
barco, paquete, alojamiento), no solo alojamiento.

**Implementado:**
- **Editor público genérico** en `render_manage_editor()` basado en
  `TAP_Metaboxes::get_fields($type)` (con lazy-load `$loaded` añadido a
  `class-metaboxes.php`). Cubre los 6 tipos; `tap_room` queda excluido como creable por
  proveedores.
- **Handler de guardado** extraído a un núcleo testable `TAP_Ajax::save_listing_data()` que
  nunca hace `wp_die` (el wrapper AJAX `agency_save_listing` lo envuelve con JSON). Valida
  propiedad (`agency_owns_listing`, generalizado), respeta el límite del plan
  (`tap_listing_limit` filtrable, `<0` = ilimitado) y persiste campos con sanitización por
  tipo, más el mapeo legacy de nombres cortos del formulario de alojamiento.
- **Prefix de metadatos centralizado**: nueva fuente única
  `TAP_Post_Types::meta_prefix()` (`acc`, `tour`, `trans`, `car`, `boat`, `pkg`) aplicada en
  ajax, shortcodes, API, dashboard y promotions. `TAP_Promotions::prefix_for_type()` delega
  en ella. **Corrige un bug latente** donde los prefijos derivados (`str_replace('tap_','')`)
  rompían el owner / `_is_active` de accommodation, transport, car-rental y package.

**Verificación:** nuevo suite `suite_agency_manage` (create/edit/ownership en los 6 tipos +
mapeo legacy + rechazo de otra agencia), **agnóstico al sitio** (descubre la agencia/usuario;
crea una agencia temporal si el sitio solo tiene una). Verde en travel e ivanchodev. Es la
suite #9 del runner.

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

1. ✅ `suite_bookings` verde y test que cubre el nonce de reserva front-end (P1) — **hecho**, commit `1d55b17`.
2. ✅ Un usuario puede registrarse como agencia (ya funcionaba) y publicar un servicio de cada
   tipo desde el front-end (P2) — **hecho**, `suite_agency_manage` verde en travel e ivanchodev.
3. ⬜ Un visitante puede completar una reserva sin cuenta (P3) — siguiente.
4. ⬜ Un cliente puede contactar a una agencia y esto genera un lead visible en el dashboard (P4).
5. ✅ Todas las suites pasan en travel e ivanchodev (9/9 cada uno); CHANGELOG actualizado.
   Commit de P2 pendiente.

---

## 7. Riesgos

| Riesgo | Mitigación |
|---|---|
| El fix de nonce podría colisionar con otros usos de `tap_ajax.nonce` | Revisar usos existentes de `tap_ajax.nonce` (paypal, favorites) antes de cambiar |
| Ampliar el gestor a 6 tipos aumenta complejidad de validación | Metadata por tipo ya mapeada en `get_price_key` / `field_group`; reutilizar |
| Guest checkout mal implementado (reservas sin rastrear usuario) | Asegurar `guest_email` y código de reserva como llave; emails de confirmación |
| Verificación manual de agencias = cuello de botella | Automatizar con cola + notificaciones, luego robustecer |
