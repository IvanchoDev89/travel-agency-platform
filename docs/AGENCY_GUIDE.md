# Guía de la Agencia

Manual de uso del **Travel Agency Platform** para **agencias de turismo**: titulares (`tap_agency_admin`) y empleados (`tap_agency_employee`).

> Para administradores vaya a [`ADMIN_GUIDE.md`](ADMIN_GUIDE.md); para viajeros y visitantes a [`USER_GUIDE.md`](USER_GUIDE.md).

---

## Contents

- [Registro y aprobación (KYC)](#registro-y-aprobación-kyc)
- [Roles dentro de la agencia](#roles-dentro-de-la-agencia)
- [Publicar inventario](#publicar-inventario)
- [Precios, disponibilidad y promociones](#precios-disponibilidad-y-promociones)
- [Panel de la agencia (`tap_agency_manage`)](#panel-de-la-agencia)
- [Reservas y operaciones](#reservas-y-operaciones)
- [Estados y modos de reserva](#estados-y-modos-de-reserva)
- [Leads (mensajes de contacto)](#leads-mensajes-de-contacto)
- [Reseñas](#reseñas)
- [Finanzas: comisiones y disputas](#finanzas-comisiones-y-disputas)
- [Planes y destacados](#planes-y-destacados)
- [Liquidaciones (payouts)](#liquidaciones-payouts)
- [Privacidad y consentimiento](#privacidad-y-consentimiento)
- [Descuentos](#descuentos)

---

## Registro y aprobación (KYC)

1. Complete el **formulario de registro de agencia** (`[tap_agency_register]`): razón social, usuario, email y contraseña, teléfono/WhatsApp, sitio web, dirección, ciudad, país, descripción y datos legales (`legal_name`, `doc_type`, `doc_number`, `legal_tax_id`).
2. El sistema crea su **cuenta de usuario** (rol `tap_agency_admin` o, si ya tiene cuenta, se añaden los capabilities de agencia) y su **perfil de agencia** (`tap_agency`) con estado **Pendiente**.
3. Un **administrador revisa el KYC** en Travel Platform → Agencies y la **Aproba** o la **Rechaza**.
   - **Aprobada**: su agencia se activa y sus listados se publican en catálogo, búsqueda, mapa y home.
   - **Rechazada / pendiente**: sus servicios quedan ocultos del front-end y **no pueden crear reservas** (`agency_pending`).
4. Ya aprobada puede publicar servicios. El administrador puede además **verificar** la agencia (aparece el badge **✓ Verificada** en el perfil público) y definir una **comisión % propia**.

> Con el rol de **empleado** (`tap_agency_employee`) solo podrá **editar** listados ya publicados; no podrá publicar nuevos, borrarlos ni gestionar pagos. Con el rol de **titular** podrá publicar, editar y borrar el inventario de la agencia.

---

## Roles dentro de la agencia

| Capacidad | Titular (`tap_agency_admin`) | Empleado (`tap_agency_employee`) |
| --- | --- | --- |
| Crear/editar servicios (6 tipos) | Sí | Editar sí / Crear+publicar **no** |
| Borrar servicios | Sí | No |
| Subir imágenes | Sí (`upload_files`) | Sí |
| Ver/acceder al panel `/mi-cuenta` | Sí (completo) | Sí (solo **Resumen** y **Operaciones** en solo-lectura) |
| Responder reseñas | No (las respuestas oficiales las añade el admin en *Travel Platform → Reviews*) | No |
| Solicitar plan / destacados / pagos | Sí | No |

---

## Publicar inventario

Publica bajo el perfil de su agencia cualquiera de los 6 tipos de contenido:

| Tipo | Slug CPT |
| --- | --- |
| Alojamiento | `tap_accommodation` |
| Tour | `tap_tour` |
| Transporte | `tap_transport` |
| Alquiler de autos | `tap_car_rental` |
| Alquiler de botes | `tap_boat` |
| Paquete | `tap_package` |

> Cada listado se asigna a su agencia con el metadato `_tap_agency_id`; el panel y el front-end solo exponen contenido de la **propia** agencia. Las capacidades de rol de agencia cubren estos 6 tipos de servicio (la plataforma registra además los CPT internos `tap_room`, habitaciones del alojamiento, y `tap_equipment`, alquiler de equipos, gestionados por el administrador del sitio).

- Cada listado se asigna a su agencia (metadato `_tap_agency_id`); el panel y el front-end solo exponen contenido de la **propia** agencia.
- Los listados de agencias **rechazadas/desactivadas** se excluyen automáticamente de búsqueda, catálogo, mapa, REST y sitemap.
- El **detalle** de cada servicio (`tap_service_detail`) muestra slots de precios, disponibilidad y el botón de reserva (o de **Solicitar reserva** según configuración).

### Alojamiento: habitaciones, precios y descuentos

- Gestione **habitaciones** con precio, capacidad, contenido incluido y extras.
- Configure tarifas, disponibilidad por fechas y las reglas de **Early Bird / Last Minute / Long Stay** (por alojamiento o heredando los globales del admin). Los descuentos se muestran desglosados al cliente en el checkout (`savings`).

---

## Panel de la agencia

**Ruta front:** `/mi-cuenta/` (si no hay sesión se muestra el prompt de login). El panel es la vista unificada del shortcode público `[tap_dashboard]`: para **titulares** (`tap_agency_admin`) y **empleados** (`tap_agency_employee`) se activa la vista de agencia, y para administradores del sitio se muestra un aviso que enlaza al admin de WordPress.

Pestanas del panel de agencia:

- **Resumen**: actividad y métricas de la agencia (reservas por estado, próximas, ingresos). Para empleados muestra una versión reducida (sin datos financieros).
- **Operaciones** (back office de reservas): tabla de reservas de la agencia con acciones por estado — ver [Reservas y operaciones](#reservas-y-operaciones). Los **empleados** la ven en **solo lectura**.
- **Finanzas**: saldo **Por cobrar** (`owed`) / **En disputa** (`disputed`) / **Cobrado** (`paid`) + historial de liquidaciones. Solo titular.
- **Listados**: gestión del inventario (`tap_agency_manage`). Solo titular.
- **Leads de contacto** (bloque en la barra lateral): mensajes de clientes con **exportación CSV** — ver [Leads](#leads-mensajes-de-contacto). Solo titular.

> Los administradores del sitio (`manage_options`) pueden operar reservas de cualquier agencia (Confirmar, Completar, **Marcar pagada** y Cancelar) al no estar atados a una agencia propia.

---

## Reservas y operaciones

Desde **Operaciones** del panel (AJAX con nonce `tap_front_dash_nonce`):

- **Confirmar**: una `request` o `pending` (→ `confirmed`). Una solicitud `request` puede también **rechazarse** desde el flujo de la agencia (→ `cancelled`).
- **Completar**: una reserva `confirmed` (→ `completed`), activando la ventana de reseña.
- **Marcar pagada**: reserva `confirmed` con pago `pending`/`partial`/`failed`. ⚠️ **Solo un administrador del sitio** puede marcarla pagada (no las agencias).
- **Cancelar**: reservas `request`/`pending`/`confirmed` cuyo check-in no haya pasado. Se aplica la **política de cancelación** (y, si procede, la **penalidad**).
- ⚠️ **Precaución**: si la reserva está **pagada**, la cancelación NO está permitida para empleados ni agencias — solo un **administrador** puede cancelar reservas pagadas (ejecuta el reembolso PayPal real). Se muestra: *“Solo los administradores pueden cancelar reservas pagadas”*.
- Los **empleados** ven la tabla de reservas en **solo lectura** (sin botones de acción).

El sistema, por cron, **auto-completa** las reservas pasadas **pagadas** y **cancela automáticamente** las `pending` tras la ventana configurada (24 h por defecto).

---

## Estados y modos de reserva

**Estados:** `pending` (pendiente de pago/confirmación), `request` (solicitada), `confirmed` (confirmada/pagada), `completed` (completada), `cancelled` (cancelada), `refunded` (reembolsada).

**Modo de reserva** (`booking_mode`):

- **Normal**: con auto-confirm activo, el pago del cliente confirma la reserva inmediatamente.
- **Request**: el cliente deja una `request` y **usted debe confirmarla** o rechazarla desde el panel. Queda en estado `request` hasta que la agencia actúa.
- **Pending** (reserva normal sin pagar): si no se paga/confirma dentro de la ventana configurada (24 h por defecto), el sistema la cancela automáticamente.

Los bookings quedan ligados por `service_id` + agencia; el panel solo muestra reservas de la propia agencia.

---

## Leads (mensajes de contacto)

- Los formularios **Contactar agencia** requieren **consentimiento explícito** (GDPR / Ley 8968). Un envío sin consentimiento se rechaza (`lead_consent_required`).
- **Rate limits** anti-spam: **5 correos/hora** por email y **10/hora** por IP.
- El motor de moderación del sitio puede **revisar** (URLs, texto sin sentido, PII) o **bloquear** (abuso, spam, ≥ 3 URLs) un lead; un envío **bloqueado** se rechaza en el acto (`lead_blocked`) y no llega a guardarse.
- **Privacidad de atribución**: el **nombre y el email de los leads se muestran enmascarados** hasta que el lead "pertenezca" a su agencia, es decir, hasta que exista una reserva **confirmada (pagada)** con ese email (huésped o cuenta) — entonces se muestran en claro en el panel y el CSV. La moderación no interviene en el enmascarado.
- Exporte sus leads a **CSV** con blindaje contra inyección de fórmulas (nonce `tap_export_leads`); la exportación aplica las mismas reglas de enmascarado.
- La **atribución** de leads a su agencia se confirma cuando el email del lead produce una reserva `confirmed` (`Reports → atribución de leads`).

---

## Reseñas

- Los viajeros reseñan por servicio (rating 1–5 + comentario) **solo cuando la reserva está `confirmed` o `completed` y `paid`**, y pueden **eliminar su propia reseña** desde su panel.
- La moderación del sitio decide si la reseña es **visible inmediatamente** (*ok*), entra a **revisión** o se **bloquea**. Solo la reseña **aprobada** luce el sello **Verificada**.
- Las respuestas a reseñas (autor + fecha) son **oficiales de la plataforma**: las añade el administrador desde *Travel Platform → Reviews* (cap `tap_manage_reviews`). Usted verá la respuesta publicada junto a la reseña en su listado, pero desde el panel de agencia no hay acción de respuesta.

---

## Finanzas: comisiones y disputas

- Cada reserva confirmada/pagada genera una **comisión** para la plataforma calculada sobre el subtotal del servicio (según su plan o el % fijado por el admin).
- Su pestaña **Finanzas** distingue:
  - **Por cobrar** (`owed`): comisiones aún no liquidadas por el admin.
  - **En disputa** (`disputed`): comisiones retenidas por una disputa abierta.
  - **Cobrado** (`paid`): comisiones liquidadas (el admin registra el pago y usted recibe **email de comprobante** — véase Bookings · Comisiones en la guía del admin).
- Pestañas del **libro de comisiones** por estado. Una comisión **cancelada/void** no se paga (por ejemplo, cuando la disputa se resuelve en contra suya).

### Disputas

- Puede **abrir una disputa** (Travel Platform las resuelve, pero la iniciativa suele venir de usted o del cliente) sobre reservas **confirmadas o completadas** y **pagadas**, con motivos: *Calidad del servicio, Publicidad engañosa, Cancelación o modificación, Otro*.
- Al abrirse, la comisión queda retenida. El administrador resuelve:
  - **A favor de la agencia** → la comisión se restaura.
  - **Contra la agencia** → la comisión se anula (`void`).
  - **Retirada** → se libera.

---

## Planes y destacados

### Suscripción

- Elija un plan (`tap_plans`) desde su área:
  - **Gratis** ($0): 3 listados, 0 destacados.
  - **Básico** ($9): 10 listados, 1 destacado, comisión 8%.
  - **Pro** ($19): ilimitados, 3 destacados, comisión 5%.
- **Pagar online** (PayPal) activa la suscripción al instante, o el admin la **marca pagada** manualmente (1–24 meses).
- Las suscripciones **expiran**; tendrá **warnings** por cron antes del vencimiento.

### Destacados (Promotions)

- Solicite meses de **★ Destacado** para un listado. Precio por mes: tarifa de destacado (default **$5**).
- El admin la **marca activa** (meses ≤ 24) desde *Travel Platform → Promotions*, o con PayPal configurado el pago online la activa al instante. Mientras esté activa: badge ★ y prioridad en archivos, búsqueda y home; al expirar se retira automáticamente.

---

## Liquidaciones (payouts)

- Desde el panel (pestaña **Finanzas**, solo titular) pulse **Solicitar liquidación** (AJAX `tap_dash_agency_payout`): su comisión por cobrar queda agrupada en una sola solicitud en estado **`pending`**.
- El administrador la **confirma** (Commissions) o la **cancela** (revierta `paid → owed`); al confirmarla, recibe el email de comprobante.
- Protección de concurrencia: un `GET_LOCK` garantiza que una liquidación pendiente de la misma agencia no pueda generarse dos veces.

---

## Privacidad y consentimiento

- Recolecta datos de clientes solo con **consentimiento** (checkbox normalizado `[tap_privacy_consent]`).
- Los leads se guardan con estado de moderación y **enmascarados** públicamente si la atribución está bloqueada.
- Los **exportadores/borradores** de WordPress (WP Privacy) exportan o borran leads, reservas, consentimientos y reseñas de un usuario; la supresión de datos personales afectará a los leads de su agencia cuando el usuario lo solicite.
- En la página **Privacidad** del sitio existen los derechos **Acceso, Rectificación, Actualización, Supresión y Oposición** (Ley 8968), gestionados por el admin desde *Travel Platform → Privacidad*.

---

## Descuentos

- **Early Bird**: % por plazos de anticipación.
- **Last Minute**: % dentro de la ventana previa al check-in.
- **Long Stay**: % por tramos de noches.
- Son configurables **por alojamiento** (override `_tap_acc_promos`) o se heredan de los **globales** del sitio (Travel Platform → Descuentos y Promociones). El cliente ve el desglose de descuentos en el checkout.