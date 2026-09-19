# Guía del Usuario (Viajeros)

Manual práctico para **viajeros, visitantes y clientes** que usan el **Travel Agency Platform**: buscar servicios, reservar, pagar, cancelar, reseñar y ejercer sus derechos de privacidad.

> Si usted es una **agencia de turismo**, consulte [`AGENCY_GUIDE.md`](AGENCY_GUIDE.md).
> Si usted es el **administrador** del sitio, consulte [`ADMIN_GUIDE.md`](ADMIN_GUIDE.md).

---

## Contents

- [Crear una cuenta e iniciar sesión](#crear-una-cuenta-e-iniciar-sesión)
- [Buscar y descubrir servicios](#buscar-y-descubrir-servicios)
- [Páginas de detalle de servicios](#páginas-de-detalle-de-servicios)
- [SEO y resultados enriquecidos](#seo-y-resultados-enriquecidos)
- [Favoritos (lista de deseos)](#favoritos-lista-de-deseos)
- [Reservar un servicio](#reservar-un-servicio)
- [Checkout como invitado](#checkout-como-invitado)
- [Estados y modos de reserva](#estados-y-modos-de-reserva)
- [Gestionar mis reservas](#gestionar-mis-reservas)
- [Cancelar una reserva](#cancelar-una-reserva)
- [Vouchers (comprobante)](#vouchers-comprobante)
- [Dejar una reseña](#dejar-una-reseña)
- [Chat asistente de viajes](#chat-asistente-de-viajes)
- [Constructor de itinerarios](#constructor-de-itinerarios)
- [Idiomas: español e inglés](#idiomas-español-e-inglés)
- [Privacidad y datos personales](#privacidad-y-datos-personales)

---

## Crear una cuenta e iniciar sesión

1. Abra el sitio y seleccione **Registrarse** (o el enlace de alta) o deje un **formulario de contacto** de agencia sin registrarse.
2. Complete el formulario y envíelo; el nuevo usuario recibe el rol de **cliente** (`tap_client`).
3. Inicie sesión para usar funciones personalizadas (favoritos, panel `tap_dashboard`, reseñas, etc.).

> Los formularios públicos incluyen un campo **anti-spam** invisible: si una automatización lo rellena, la petición se descarta en silencio. Los formularios de **contacto a agencias** además exigen **consentimiento** explícito — sin él no se envían (ver [Privacidad](#privacidad-y-datos-personales)).

## Buscar y descubrir servicios

- Use el **buscador** por destino/keyword — mientras escribe, aparecen **sugerencias en vivo** (destinos y servicios).
- La búsqueda aterriza en **Resultados de Búsqueda**: lista los servicios con los **destacados ★ primero**, después los más nuevos, cada uno con tipo, precio, ciudad, foto y enlace a su página.
- Refine con la **barra de filtros** (se abre desde los resultados):
  - **Tipo de servicio** (Tours, Alojamientos, Transporte, Alquiler de Autos, Alquiler de Botes, Paquetes, Equipos y Alquileres)
  - **Ubicación** (destino/ciudad)
  - **Rango de precios** (mín / máx)
- **Ordenar** resultados por: Relevancia (los ★ siempre primero), Más nuevos, Precio ↑, Precio ↓, Mejor valoración.
- Use **Aplicar** para aplicar filtros y **Limpiar** para resetearlos. Filtros y orden se aplican al instante.

## Páginas de detalle de servicios

- **Migas de pan** (breadcrumbs) muestran su ruta (Inicio › Tours y Excursiones › …) y permiten volver.
- Aparecen el **badge tipo** (p. ej. "Tours y Excursiones"), el badge **★ Destacado** y la **valoración** (promedio + nº de reseñas).
- Cuando el listado pertenece a una agencia, la línea **"Operado por"** enlaza al perfil de esa agencia (con la marca **✓ Verificada** si procede).
- El **precio** se destaca, seguido de la ficha de metadatos y el **formulario de reserva**.

## SEO y resultados enriquecidos

Las páginas de servicio y archivos se optimizan automáticamente:

- Títulos limpios, meta descripciones, **canónicas**, **Open Graph** y **Twitter Cards** para compartir en redes.
- **Datos estructurados** (JSON-LD) de breadcrumbs, FAQ, datos de contacto, hoteles, tours y agencias de viajes — candidatos a **rich results** de Google.
- **Sitemap** (`/wp-sitemap.xml`) extendido para cubrir todos los tipos de servicio y categorías; `robots.txt` lo referencia.

## Favoritos (lista de deseos)

Con sesión iniciada:

1. Abra cualquier tarjeta de servicio.
2. Haga clic en el **corazón (♥)**.
3. Acceda a sus guardados desde la página **Favoritos** (`[tap_favorites]`).

## Reservar un servicio

1. Abra la página de detalle del servicio.
2. Elija fechas y número de huéspedes.
   - **Alojamientos**: seleccione una **habitación**.
   - **Tours**: seleccione una **fecha** (se respeta la capacidad por fecha).
3. Añada **nombre**, **email** de contacto del titular y (opcional) **teléfono**.
4. Revise el **desglose de precio** en vivo: noches, precio por noche, subtotal, **descuentos** (Early Bird, Last Minute, Long Stay — con ahorro `savings`), booking fee cuando aplique, y **total**.
5. Confirme: **no se le cobra hasta completar el pago**.
6. Siga el **checkout** para finalizar (cuando aplique).

> La plataforma rechaza reservas a fechas pasadas, rangos inválidos y fechas sin capacidad/inventario. Según la configuración de la agencia, su reserva será **normal** (confirmación inmediata al pagar) o **request** (la agencia debe confirmarla; queda en espera) — ver [Estados y modos de reserva](#estados-y-modos-de-reserva).

### Cómo se paga

- El gate de pago actual es **PayPal** (cuando el administrador lo habilita). Tras el pago, su reserva queda **confirmada**.
- Si el pago no está habilitado o usted prefiere otro canal, la reserva se registra como **pendiente** y la agencia/administrador la confirma.
- **Métodos de transferencia directa**: coordínelo con la agencia; la plataforma reembolsa a PayPal automáticamente si se cancela un pago.

## Checkout como invitado

**No necesita cuenta para reservar.** Al confirmar creará una reserva de **guest** (huésped):

- Recibirá su **booking code** (`TAP-XXXXXXXX-XXXXXX`) en pantalla y por email.
- El voucher público (`/booking-detail/?code=…`) le permite ver y gestionar la reserva sin sesión.
- El sistema busca de forma **verificada** el booking por código (sin exponer datos de terceros).

## Estados y modos de reserva

**Estados del booking:** `pending` (pendiente de pago/confirmación), `request` (solicitada), `confirmed` (confirmada), `completed` (completada), `cancelled` (cancelada), `refunded` (reembolsada).

**Modo de reserva** (`booking_mode`):

- **Normal**: con auto-confirm activo, al pagar queda `confirmed` de inmediato.
- **Request**: deja una `request`; la agencia la confirma o la rechaza. Mientras tanto, queda en estado `request` a la espera. Las reservas **pending** (normal sin pagar) se cancelan automáticamente si no se confirman en la ventana configurada (24 h por defecto).

**Automatizaciones automáticas** (por cron): recordatorios de pago, mensaje pre-arrival, solicitud de reseña; las reservas **pagadas** completadas se marcan `completed` automáticamente.

## Gestionar mis reservas

- **Mis reservas** (`[tap_my_bookings]`) muestra sus reservas actuales y pasadas con estado, fechas, servicio y total.
- Abra una reserva para ver su **voucher**.
- Con sesión iniciada, su panel **Mi cuenta** (`/mi-cuenta`) ofrece las pestañas **Resumen**, **Mis reservas**, **Favoritos** y **Mis reseñas**.

## Cancelar una reserva

Se ofrece la acción **Cancelar** cuando la reserva puede cancelarse:

- Estado **Pending**, **Request** o **Confirmed** y la fecha de check-in no ha pasado.

Para cancelar:

1. Abra **Mis reservas** (o el voucher de la reserva).
2. Seleccione **Cancelar** y confirme.

**Sin cuenta (invitado):** desde el voucher puede cancelar introduciendo su **código de reserva + email de contacto** (`booking_code + email`). Para reservas antiguas sin código también se acepta el **ID de reserva + email**.

Tras la cancelación, la reserva queda **Cancelled** (o **Refunded** cuando el reembolso se ejecutó) y, si tenía un **pago realizado**, se **reembolsa por PayPal** según la **política de cancelación** del servicio; los reembolsos que fallen se reintentan a diario. No puede cancelar reservas ajenas, ni un alojamiento ya iniciado. La restricción de *“solo administradores”* se aplica a los **operadores de agencias** (que no pueden cancelar reservas pagadas); el propio cliente sí cancela su reserva pagada y recibe el reembolso que corresponda.

> La **política de cancelación** (y en su caso la penalidad) se aplica según la configuración de cada servicio.

## Vouchers (comprobante)

Cada reserva tiene un **código de reserva** y una página de voucher con:

- Código y estado
- Detalle del servicio y desglose de precio (con líneas itemizadas)
- **Titular** (titular de la cuenta) y **contacto** del huésped (nombre, email, teléfono)
- Información de contacto de la agencia

Preséntelo en el check-in. Cualquier cambio debe coordinarse con la agencia.

## Dejar una reseña

Las reseñas solo se pueden dejar cuando usted tiene una reserva **confirmada o completada** y **pagada** (verificación real de la experiencia):

1. Abra el servicio y seleccione **Dejar reseña ★** (o desde **Mis reseñas** en el panel).
2. Elija una **valoración** (1–5 estrellas), un **título** y un **comentario**.
3. Envíe. La reseña aparece públicamente una vez el administrador la **aprueba**; mientras tanto puede estar en moderación o bloquearse (abuso, spam, datos personales). Las reseñas aprobadas de reservas **confirmadas o completadas** y **pagadas** lucen el sello **Verificada**.

Las agencias no responden directamente a las reseñas: la **plataforma** (administrador) puede añadir una **respuesta oficial** junto a la reseña, y usted puede **eliminar su propia reseña** desde **Mis reseñas** en `/mi-cuenta`.

## Chat asistente de viajes

El asistente integrado (Fase 4) responde sobre **reservas, disponibilidad, pagos, cancelaciones, agencias, favoritos y recomendaciones** en español e inglés (use el **EN switcher** del sitio para inglés).

1. Abra el bloque **chat** y haga clic en su cabecera para expandirlo; se muestran 5 preguntas rápidas sugeridas.
2. Escriba, por ejemplo, *"¿cómo reservo un alojamiento?"* y pulse **Enviar**.
3. El asistente contesta en lenguaje claro con enlaces a las secciones relevantes (o 2–3 servicios publicados recomendados para *"recomiéndame…"*).

Notas:

- Límite de uso: **12 mensajes por 10 minutos**.
- El asistente es offline (basado en reglas): si no cubre su consulta, le invita a usar el formulario de contacto de una agencia. **Nunca pide ni registra datos personales.**

## Constructor de itinerarios

El **constructor de itinerarios** (`/armar-mi-viaje`, shortcode `[tap_itinerary_builder]`) le permite armar un plan de viaje combinando los servicios del catálogo (alojamiento + tours + transporte…) y guardarlo/exportarlo para su viaje.

## Idiomas: español e inglés

El tema ofrece un selector de idioma **ES / EN**. Con el switcher, las secciones principales del catálogo y el asistente responden en el idioma seleccionado.

## Privacidad y datos personales

- **Consentimiento**: todo formulario de contacto exige su consentimiento explícito (checkbox); se guarda un registro con finalidad (`booking`, `agency_registration`, `lead`). Sin consentimiento, el envío se rechaza.
- **Derechos (Ley 8968)**: en la página **Privacidad** (`[tap_privacy]`) puede ejercer **Acceso, Rectificación, Actualización, Supresión** y **Oposición**. Su solicitud se envía al administrador, que la resuelve.
- **Exportar / Borrar**: la plataforma integra los **exportadores/borradores** de WordPress (Herramientas → Exportar datos personales / Borrar datos personales). Le permiten exportar o eliminar sus **reservas, leads, consentimientos y reseñas**.
- **Guest bookings**: si reservó como invitado, su reserva se localiza por **email de huésped** y puede borrarse/anonimizarse igualmente.
- Los mensajes de contacto a agencias se **enmascaran** públicamente (nombre y email) hasta que el contactante genere una reserva **confirmada** — entonces se muestran en claro a la agencia. Si un mensaje entra en **moderación**, el administrador lo audita desde *Travel Platform → Moderation*; los envíos marcados como abuso/spam se **rechazan en el acto**. Ver también [Reseñas](#dejar-una-reseña).

---

> Para detalles técnicos (arquitectura, hooks, APIs, shortcodes), vea [`docs/DEVELOPER_GUIDE.md`](DEVELOPER_GUIDE.md).
> Historias y changelog completos: [`docs/CHANGELOG.md`](CHANGELOG.md).