# Guía del Administrador

Manual de uso del **Travel Agency Platform** para el rol de **administrador del sitio** (`administrator` de WordPress). Esta persona gobierna la plataforma completa: agencias, catálogo, reservas, dinero, moderación, analíticas y configuración.

> Para agencias vaya a [`AGENCY_GUIDE.md`](AGENCY_GUIDE.md); para viajeros y visitantes a [`USER_GUIDE.md`](USER_GUIDE.md).

---

## Contents

- [Roles y permisos](#roles-y-permisos)
- [El menú Travel Platform](#el-menú-travel-platform)
- [Dashboard](#dashboard)
- [Bookings (Reservas)](#bookings-reservas)
- [Commissions (Comisiones)](#commissions-comisiones)
- [Reviews (Reseñas)](#reviews-reseñas)
- [Moderation (Moderación)](#moderation-moderación)
- [Disputes (Disputas)](#disputes-disputas)
- [Agencies (Agencias)](#agencies-agencias)
- [Reports (Reportes)](#reports-reportes)
- [Analytics (Analíticas)](#analytics-analíticas)
- [Plans, Subscriptions y Promotions](#plans-subscriptions-y-promotions)
- [Settings (Ajustes)](#settings-ajustes)
- [Descuentos y Promociones](#descuentos-y-promociones)
- [Privacidad y datos personales](#privacidad-y-datos-personales)
- [Apéndice: integridad del dinero](#apéndice-integridad-del-dinero)

---

## Roles y permisos

La plataforma registra tres roles de usuario propios (`class-roles.php`):

| Rol | Quién lo usa | Qué puede hacer |
| --- | --- | --- |
| `tap_agency_admin` | Titular de una agencia | Publicar y editar inventario de los 6 tipos de servicio, gestionar su panel, responder leads de contacto, solicitar planes/promociones y liquidaciones. |
| `tap_agency_employee` | Empleados de agencia | Editar listados de su agencia (`edit_{tipo}` y `edit_{tipo}s`); **no** publicar, **no** borrar, **no** gestionar pagos. |
| `tap_client` | Viajeros registrados | Reservar, guardar favoritos, reseñar, acceder a su panel. |

El rol `administrator` recibe automáticamente las capacidades de gestión:

- `tap_manage_bookings`, `tap_manage_agencies`, `tap_manage_commissions`, `tap_manage_reviews`, `tap_manage_disputes`, `tap_view_reports`, `tap_manage_settings`.
- Capacidades completas (`edit_/publish_/delete_`, singular y plural correcto) sobre los CPT `tap_agency`, `tap_accommodation`, `tap_tour`, `tap_transport`, `tap_car_rental`, `tap_boat`, `tap_package`.

Al desactivar el plugin los roles propios se eliminan; al activarlo se recrean (idempotente).

---

## El menú Travel Platform

Todos los submenús cuelgan del ítem **Travel Platform** (posición 25 del admin).

| Página | Slug | Cap necesaria |
| --- | --- | --- |
| Dashboard | `travel-platform` | `manage_options` |
| Bookings | `tap-bookings` | `tap_manage_bookings` |
| Commissions | `tap-commissions` | `tap_manage_commissions` |
| Reviews | `tap-reviews` | `tap_manage_reviews` |
| Moderation | `tap-moderation` | `manage_options` |
| Disputes | `tap-disputes` | `tap_manage_disputes` |
| Agencies | `tap-agencies` | `tap_manage_agencies` |
| Reports | `tap-reports` | `tap_view_reports` |
| Analytics | `tap-analytics` | `tap_view_reports` |
| Plans | `tap-plans` | `manage_options` |
| Subscriptions | `tap-subscriptions` | `manage_options` |
| Promotions | `tap-promotions` | `manage_options` |
| Settings | `tap-settings` | `tap_manage_settings` |
| Privacidad | `tap-privacy` | `tap_manage_settings` |

Página adicional fuera del menú Travel Platform:

| Página | Slug | Cap necesaria |
| --- | --- | --- |
| Ajustes → Descuentos y Promociones | `tap-discounts` | `manage_options` |

> Las acciones de reservas del back office se ejecutan por AJAX con nonce `tap_admin_booking` (`assets/js/admin.js`).

---

## Dashboard

Vista resumen del negocio:

- Tarjetas con ingresos, reservas, comisiones y agencias.
- Gráficos (Chart.js): ingresos mensuales, distribución por estado, reservas por tipo de servicio.
- Tabla de las 8 reservas más recientes.

---

## Bookings (Reservas)

**Ruta:** Travel Platform → Bookings (`tap-bookings`, cap `tap_manage_bookings`).

- Listado paginado (20/página) con filtros por keyword, estado, tipo de servicio y agencia.
- Cada reserva muestra código (`TAP-XXXXXXXX-XXXXXX`), servicio, fechas, huésped, total y enlace público al voucher (`/booking-detail/?code=…`).
- **Cambio de estado** mediante el selector de estado (respeta estrictamente la máquina de estados de `TAP_Booking::transitions`).

**Estados de reserva:** `pending`, `request`, `confirmed`, `completed`, `cancelled`, `refunded`.

**Reglas de integridad del dinero:**

- **Marcar como pagada** (`mark_paid`) es una operación **solo de administrador**: exige reserva `confirmed`, pago no reembolsado y se ejecuta como una sola actualización atómica condicional. Una reserva ya pagada se ignora (no re-dispara `tap_payment_completed`).
- Cancelar una reserva **pagada** dispara el reembolso PayPal (vía `apply_cancellation`/`execute_refund`). Las agencias y sus empleados no pueden cancelar reservas pagadas: solo un administrador (mensaje: *"Solo los administradores pueden cancelar reservas pagadas"*).
- Los reembolsos pendientes se reintentan a diario por cron (`tap_refund_retry_hook`).
- **Exportar CSV** de reservas (nonce `tap_export_bookings`): fichero con BOM UTF-8 y blindaje contra inyección de fórmulas de hojas de cálculo.

---

## Commissions (Comisiones)

**Ruta:** Travel Platform → Commissions (`tap-commissions`, cap `tap_manage_commissions`).

Pestanas del libro de comisiones:

- **Todas / Por cobrar / En disputa / Pagadas / Canceladas.**

**Estados de comisión:** `owed` (por cobrar), `waiting`, `paid` (cobrada), `disputed` (en disputa), `void` (anulada).

Acciones:

- **Pago masivo**: seleccione reservas con comisión **por cobrar** y registre el pago (método + nota). Solo se pagan comisiones `owed`, pagadas, no canceladas/refunded y sin payout pendiente.
- **Liquidar individual** (`tap_settle_booking`): modal con método + nota para una reserva concreta.
- **Historial de liquidaciones**: cada período registrado con **Marcar pagada** (si quedó en `owed`) o **Cancelar** (revierta `paid → owed`). Los comprobantes muestran el código de reserva, no el id interno.

> Las comisiones se calculan sobre el subtotal del servicio (antes del booking fee del cliente). Ver [Reports](#reports-reportes).

---

## Reviews (Reseñas)

**Ruta:** Travel Platform → Reviews (`tap-reviews`, cap `tap_manage_reviews`).

- Pestanas **Todas / Pendientes / Aprobadas**.
- Acciones: **Aprobar**, **Desaprobar**, **Eliminar**, y **Responder** en nombre de la plataforma (nonce `tap_review_reply_{rid}`; la respuesta se muestra con autor y fecha junto a la reseña). Solo usuarios con `tap_manage_reviews` (administradores) acceden a la página.
- Badge **Verificada**: solo se consideran elegibles las reseñas de reservas **confirmadas o completadas y pagadas**; el texto de verificación lo aclara al viajero. Los clientes pueden **eliminar su propia reseña** desde su panel.

---

## Moderation (Moderación)

**Ruta:** Travel Platform → Moderation (`tap-moderation`, cap `manage_options`).

Cola de moderación de **reseñas y leads (mensajes de contacto)** cuyo `mod_status != ok`.

Motivos detectados por el motor (`TAP_Moderation::assess`):

| Detección | Reseñas | Leads (contacto) |
| --- | --- | --- |
| `abuse` (insultos/abusos) | **BLOCK** (bloqueado) | **BLOCK** |
| `pii` (email/teléfono) | **BLOCK** (`pii`) | **REVIEW** (`links`) |
| `spam` | **BLOCK** (`spam`) | **BLOCK** (`spam`) |
| ≥ 3 URLs | **BLOCK** (`spam`) | **BLOCK** (`spam`) |
| 1–2 URLs | **REVIEW** (`links`) | **REVIEW** (`links`) |
| `gibberish` (texto sin sentido) | **REVIEW** (`gibberish`) | **REVIEW** (`gibberish`) |

Acciones del admin: **Aprobar**, **Bloquear**, **Eliminar** (nonces `tap_mod_{action}_{kind}_{id}`). Desde el panel puede ver el contenido íntegro de los leads señalados (la página pública de la agencia los muestra enmascarados mientras la atribución esté bloqueada).

---

## Disputes (Disputas)

**Ruta:** Travel Platform → Disputes (`tap-disputes`, cap `tap_manage_disputes`).

Las disputas solo se pueden abrir sobre reservas **confirmadas o completadas** y **pagadas** (motivos: *Calidad del servicio, Publicidad engañosa, Cancelación o modificación, Otro*).

Al abrir una disputa, **la comisión queda retenida** (`disputed`). Resolución:

- **A favor de la agencia** (`for_agency`): se restaura la comisión según su valor retenido (`prev_commission`).
- **Contra la agencia** (`against_agency`): la comisión pasa a `void`.
- **Retirada** (`withdrawn`): se libera la comisión.

Cada acción notifica el evento `tap_dispute_resolved` y registra la resolución.

---

## Agencies (Agencias)

**Ruta:** Travel Platform → Agencies (`tap-agencies`, cap `tap_manage_agencies`).

Gestión de todo el ciclo de vida de una agencia:

- **Aprobar / Rechazar** la agencia (estado `pending` → `approved`/`rejected`). Al aprobarla se activa (`_tap_agency_is_active = 1`) y sus listados dejan de estar ocultos. Al rechazarla se desactiva y sus servicios vuelven a ocultarse en el front-end, búsquedas, REST y sitemap.
- **Verificar / Quitar verificación**: añade la marca ✓ (**Verificada**) en el perfil público.
- **Activar / Desactivar** la cuenta.
- **Comisión %** editable inline (override por agencia sobre el default global).
- **Eliminar** la agencia: desvincula sus listados y la desactiva en el registro.
- Vista del **KYC** (datos legales: `legal_name`, `doc_type`, `doc_number`, `legal_tax_id`) presentados por la agencia.

> Las agencias con estado **no aprobado** no pueden crear reservas (`agency_pending`) y sus listados se excluyen de búsqueda/catálogo/mapa/sitemap (también aplicación retroactiva a agencias heredadas sin estado).

---

## Reports (Reportes)

**Ruta:** Travel Platform → Reports (`tap-reports`, cap `tap_view_reports`).

- KPIs de negocio: **GMV**, comisiones, booking fees y destacados confirmados.
- Tabla mensual de los últimos 12 meses.
- **Atribución de leads**: un lead "pertenece" a la agencia hasta que exista una reserva **confirmada (pagada)** cuyo email de huésped o de cuenta coincida; mientras tanto los datos del cliente se mantienen enmascarados en el front-end, el panel y el CSV.

---

## Analytics (Analíticas)

**Ruta:** Travel Platform → Analytics (`tap-analytics`, cap `tap_view_reports`).

- Filtro **Desde / Hasta** (formato `YYYY-MM`): KPIs, tablas y exports respetan el filtro.
- **10 tarjetas KPI**: ingresos de la plataforma (12 meses), MRR de suscripciones, GMV filtrado, valor de destacados activos, reservas vigentes, ticket promedio, vistas, **conversión vistas→reservas**, agencias activas y listados publicados.
- Gráfico apilado de 12 meses por fuente: **Comisiones, Booking fees, Suscripciones, Destacados** + tabla de desglose por fuente.
- **Top agencias** (reservas, GMV, comisión de plataforma, fees) y **Top listados** (reservas, GMV, **Vistas**, **Conversión**).
  - `record_view` cuenta vistas de páginas individuales con throttle de 5 minutos por visitante y escritura diferida a `shutdown` (no bloquea la página); el agregado `listing_views` devuelve hasta los 1000 listados con más vistas.
- **Exports CSV** (nonce `tap_analytics_export`): **Exportar reservas** (nivel de detalle con fee, comisión y neto) y **Exportar resumen** (resumen financiero mensual con suscripciones, promociones y vistas).

---

## Plans, Subscriptions y Promotions

### Plans (`tap-plans`)

Edición inline de cada plan: nombre, precio, **comisión %**, límite de listados (`-1` = ilimitado), **slots de destacados**, features y estado activo.

Planes por defecto:

| Plan | Precio | Comisión | Listados | Destacados |
| --- | --- | --- | --- | --- |
| Gratis | $0 | — | 3 | 0 |
| Básico | $9 | 8% | 10 | 1 |
| Pro | $19 | 5% | Ilimitado | 3 |

### Subscriptions (`tap-subscriptions`)

- Estado de cada agencia: `pending` (solicitada), `active`, `expired`.
- **Marcar pagado**: seleccione meses (1–24). Idempotente (no duplica duración); activa la nueva suscripción y **expira las demás activas** de la agencia. Notifica a la agencia por email (`[Plan activado]`).
- **Expirar**: termina la suscripción anticipadamente.
- Con PayPal configurado, el pago online activa la suscripción automáticamente sin intervención manual.

### Promotions (`tap-promotions`)

- Solicitudes de destacado (`featured`): precio = meses × `tap_featured_price` (default **5.00**).
- **Marcar activo** (meses ≤ 24) o **Expirar**. La promoción activa marca el listado como **★ Destacado** (`_tap_{tipo}_is_featured = 1`) y lo antepone ordenado en archivos, búsqueda y home; al expirar, el badge se retira automáticamente.
- Con PayPal, la agencia paga online y se activa al instante.

---

## Settings (Ajustes)

**Ruta:** Travel Platform → Settings (`tap-settings`, cap `tap_manage_settings`).

| Ajuste | Descripción |
| --- | --- |
| Comisión default | % de comisión de plataforma por defecto (default 10). |
| Moneda | Símbolo y reglas de decimales (p. ej. USD, CRC, EUR, GBP, JPY…) vía `TAP_Currency`. |
| Auto-confirm | Modo de confirmación automática de reservas (ver [modos de reserva](USER_GUIDE.md#estados-y-modos-de-reserva)). |
| Horas pendientes | Ventana (default 24 h) tras la cual las reservas `pending` se cancelan por cron. |
| Página de términos | Página enlazada en el checkout/contacto. |
| Gateway | Método de pago (actualmente **PayPal**). |
| Booking fee | `none`, `fixed` o `percent` (tarifa del cliente sobre el servicio). |
| Políticas de cancelación | Por tipo de servicio, sanitizadas a `flexible`. |
| Auto-completar | Habilitar `complete_past_bookings` (solo completa reservas **pagadas**). |
| Automatizaciones | Toggles de los 6 automatismos cron: recordatorio de pago, mensaje pre-arrival, solicitud de reseña, warnings de expiración, etc. |
| **PayPal** | Habilitar, modo **Sandbox/Live**, **Client ID**, **Secret** y **Webhook ID**. |

**Webhook PayPal** a registrar en el panel de PayPal: `https://<su-sitio>/wp-json/tap/v1/paypal-webhook`.

El ledger es la fuente de verdad de pagos: cada orden PayPal se registra en `tap_payment_orders` (upsert por `paypal_order_id`), y los eventos de captura y reembolso se sincronizan de forma **idempotente** (eventos repetidos no re-marcan pagos ni re-generan comisiones).

---

## Descuentos y Promociones

**Ruta:** Ajustes → Descuentos y Promociones (`tap-discounts`, cap `manage_options`).

Tres familias de descuentos globales, aplicadas en el cálculo del total del alojamiento:

- **Early Bird**: días de anticipación + % de descuento.
- **Last Minute**: ventana previa al check-in + % de descuento.
- **Long Stay**: descuentos por tramos de noches.
- **Overrides por alojamiento** (`_tap_acc_promos`): cada acomodación puede sobreescribir su configuración.

El desglose se refleja en el breakout de precios que ve el cliente antes de confirmar (`base_total`, `discounts[]`, `total`, `savings`).

---

## Privacidad y datos personales

### Página de privacidad (Ley 8968)

- Página pública **Privacidad** (`[tap_privacy]`) donde el usuario ejerce sus derechos: **Acceso, Rectificación, Actualización, Supresión y Oposición** (formulario que genera una solicitud guardada en `tap_privacy_requests`).
- Panel **Privacidad y Datos Personales (Ley 8968)** (`tap-privacy`) para revisar las solicitudes leídas desde el admin.

### Exportadores y borradores de WordPress

`TAP_Privacy` registra integraciones nativas de **WP Privacy** (`wp_privacy_personal_data_exporters` y `wp_privacy_personal_data_erasers`):

- **Exporta**: reservas (por `client_id` o email de huésped), leads, consentimientos y reseñas del usuario.
- **Borra**: leads, consentimientos y reseñas del usuario; elimina reservas de **guest** (sin cuenta) del email; para reservas vinculadas a cuenta **anonimiza** `guest_name`/`guest_email` en lugar de borrarlas.

### Consentimientos

Todo formulario de contacto/lead exige consentimiento explícito (`[tap_privacy_consent]`); se registra con scope en `tap_consents` (`booking`, `agency_registration`, `lead`). Un lead sin consentimiento se rechaza con `lead_consent_required`.

---

## Apéndice: integridad del dinero

Garantías implementadas en v1.5.7 (ver [`CHANGELOG.md`](CHANGELOG.md)):

- **Captura idempotente**: una orden PayPal capturada no se vuelve a cargar; capturas con moneda/monto inconsistentes se auto-reembolsan y marcan `failed`.
- **Ledger `tap_payment_orders`**: registro único por `paypal_order_id` para reservas, suscripciones y promociones.
- **Refunds con reintento**: los reembolsos fallidos quedan `payment_status = refunded` pendientes de sync y un cron diario los reintenta.
- **Cancelación de pagadas**: solo administradores; revierte comisión y ejecuta reembolso real PayPal.
- **Settlement**: los pagos masivos/individuales pasan `owed → paid`; “Cancelar” en el historial quiere decir revertir `paid → owed`, nunca borrar dinero.
- **Uninstall**: al eliminar el plugin, `uninstall.php` borra las 17+ tablas `tap_%`, los crons (mantenimiento, automatizaciones, refunds), las opciones/transients y los roles propios, y elimina el contenido de los CPT tras confirmación (o lo conserva si `tap_uninstall_keep_content = 1`) — ver [`INSTALLATION.md`](INSTALLATION.md).