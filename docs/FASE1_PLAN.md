# FASE 1 — Registro público multiagencia & flujo de conversión
## Plan técnico detallado (basado en auditoría real del código, v1.3.0)

> Estado del documento: **EN EJECUCIÓN — P1–P4 COMPLETADOS; deuda técnica (Fase B) y batería de crítica (Fase C) CERRADAS**.
> Verificado en travel e ivanchodev (17/17 suites verdes, 0 residuos).
> **Post-auditoría UI/UX (lotes 1–6, commits `a326f3b`→`305c9d6`)**: batería ampliada a 18 suites,
> verificada en ambos sitios (18/18 verdes, 0 residuos) tras cada lote. Véase CHANGELOG.
> Anexa a [`INTEGRATION_PLAN.md`](INTEGRATION_PLAN.md) (estrategia de implantación).
>
> Estado por prioridad:
> - ✅ **P1** — BUG de nonce de reservas: **COMMIT `1d55b17`**, cubierto en `suite_bookings`.
> - ✅ **P2** — Autoservicio de proveedores para los 6 tipos: **COMMIT `75f6aec`** + `suite_agency_manage` verde en ambos sitios.
> - ✅ **P3** — Guest checkout: **COMMIT `882b926`**, cubierto en `suite_guest_checkout`.
> - ✅ **P4** — Monetización por lead: **COMMIT `a7a1847`**, cubierto en `suite_leads`.
>
> Suplementario:
> - ✅ **Fase B** — Deuda técnica cobrada: **COMMIT `9d842a3`** (+ `suite_bugs`).
> - ✅ **Fase C** — Batería de crítica (reserva, pricing, PayPal, REST, reviews): **COMMIT `b68ba9a`** (+5 suites, fix REST propietario).

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

### 🟠 P3 — Captación de clientes: reserva accesible ✅ COMPLETADO
Para que el marketplace genere comisiones, **cualquier visitante debe poder reservar** sin
fricción.

**Implementado (commit `882b926`):**
- **Guest checkout**: el formulario captura `guest_name`/`guest_email`/`guest_phone`, y la
  reserva se guarda con `client_id = 0` + datos de huésped. Las acciones públicas
  (`tap_booking_create`, `tap_cancel_booking`, voucher `[tap_booking_detail]`, checkout
  `[tap_checkout]`) aceptan huéspedes verificando `?code=` + email.
- **Cierre por email**: el voucher y el botón de pago solo se muestran cuando el email
  usado coincide con `guest_email` de la reserva; canje erróneo → "no coincide".
- **Cancelación de huésped**: `TAP_Booking::client_cancel_request($id, 0, $guest_email)`
  valida el email; un registrado no puede cancelar reserva guest (`forbidden`) y un guest
  no puede cancelar la de un registrado (`no_user`).
- **Rate limiting**: `TAP_Ajax::guest_book_rate_bump()` / `guest_book_rate_blocked()`
  (5 reservas/hora/email) protegen el checkout.
- `guest_email_required` rechaza booking sin email válido.

**Verificación:** `suite_guest_checkout` (flujo happy path, validaciones de email,
permisos de cancelación cruzados, tokens de pago guest, rate limit). Verde en travel e
ivanchodev. *Nota de higiene:* la suite registra ambos bookings (guest y cliente logueado)
en su cleanup — antes filtraba 1 fila por ejecución (fix en commit `b68ba9a`).

---

### 🟢 P4 — Monetización por lead (contacto a agencias) ✅ COMPLETADO
Nueva fuente de ingresos: cuando un cliente envía un mensaje/presupuesto a una agencia, se
registra un **lead** y opcionalmente se cobra/basura su visibilidad.

**Implementado (commit `a7a1847`):**
- Tabla `tap_leads` + clase `TAP_Leads` con `submit()` CLI-safe (sin `wp_send_json`/`wp_die`):
  valida nombre/email/mensaje, agencia activa, pertenencia del servicio (`invalid_service`)
  y rate limiting (`lead_rate_limit`; 5 emails / 10 IPs por hora; limpieza vía
  `DATE_SUB(NOW(), INTERVAL 1 HOUR)`).
- Shortcode público `[tap_lead_form]` en el perfil de agencia y detalle de servicio; alerta
  por email a la agencia (`tap_lead_created`) con `TAP_Emails::send_lead_notification()`.
- Dashboard de agencia **Mensajes**: contador y últimos 20 leads + **Exportar CSV**
  (`TAP_Leads::export_csv()`, nonce `tap_export_leads_{user}` vía `admin-post`).

**Verificación:** `suite_leads` (submit feliz + todos los rechazos + rate limit + CSV y
notificación). Verde en travel e ivanchodev.

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
3. ✅ Un visitante puede completar una reserva sin cuenta (P3) — **hecho**, `suite_guest_checkout` verde en ambos sitios (commit `882b926`).
4. ✅ Un cliente puede contactar a una agencia y esto genera un lead visible en el dashboard (P4) — **hecho**, `suite_leads` verde en ambos sitios (commit `a7a1847`).
5. ✅ Todas las suites pasan en travel e ivanchodev (17/17 cada uno); CHANGELOG actualizado. Commits de P2 (`75f6aec`), P3 (`882b926`), P4 (`a7a1847`), Fase B (`9d842a3`) y Fase C (`b68ba9a`).

---

## 7. Riesgos

| Riesgo | Mitigación |
|---|---|
| El fix de nonce podría colisionar con otros usos de `tap_ajax.nonce` | Revisar usos existentes de `tap_ajax.nonce` (paypal, favorites) antes de cambiar |
| Ampliar el gestor a 6 tipos aumenta complejidad de validación | Metadata por tipo ya mapeada en `get_price_key` / `field_group`; reutilizar |
| Guest checkout mal implementado (reservas sin rastrear usuario) | Asegurar `guest_email` y código de reserva como llave; emails de confirmación |
| Verificación manual de agencias = cuello de botella | Automatizar con cola + notificaciones, luego robustecer |
