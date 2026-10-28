# Plan de mejoras de mercado — Travel Agency Platform

> Fecha: 2026-10-28
> Estado: propuesta (no implementado)
> Prioridad: negocio + UX + monetización

---

## Visión

Convertir la plataforma en un **marketplace de viajes completo** con revenue management, crecimiento orgánico (referidos), fidelización de viajeros, pagos locales y analytics avanzado, manteniendo la calidad de código (PHPStan, tests, i18n) y el panel unificado.

---

## Fase 0 — Fundaciones (prerequisitos técnicos)

Antes de cualquier feature de mercado, necesitamos:

| # | Tarea | Esfuerzo | Dependencias |
|---|-------|----------|--------------|
| 0.1 | Migrar a multi-moneda (tabla `tap_currencies`, meta `_tap_*_currency` ya existe) | 2 semanas | — |
| 0.2 | Sistema de eventos de dominio (event bus) para notificaciones, analytics, automations | 1 semana | — |
| 0.3 | Webhook/queue para tareas asíncronas (emails, sync) | 1 semana | — |
| 0.4 | API REST v2 (versionada, con auth por token) | 2 semanas | — |

**Entregable**: infraestructura para escalar.

---

## Fase 1 — Yield Pricing / Revenue Management (4-6 semanas)

### Objetivo
Maximizar ingresos por servicio mediante precios dinámicos.

### Tareas
1. **Extender `tap_daily_pricing`** a tours y transport (hoy solo rooms).
   - Tabla: `tap_daily_pricing` con `service_type`, `service_id`, `date`, `price`, `min_stay`, `is_blocked`, `capacity`.
2. **Reglas de precio**: early bird (reserva con X días de anticipación), last minute (X días antes), temporada alta/baja.
   - Nueva tabla: `tap_pricing_rules` (service_type, service_id, rule_type, condition, modifier, priority).
3. **UI en editor**: calendario de precios por fecha (ya existe para rooms; replicar para tours/transport).
4. **API de cotización**: `TAP_Pricing::quote($type, $id, $date)` retorna precio final aplicando reglas.
5. **Tests**: unit tests para cada regla, integración con booking flow.

### Métricas de éxito
- +15% revenue por servicio (A/B test).
- 0 errores de precio (validación en checkout).

---

## Fase 2 — Programa de referidos (3-4 semanas)

### Objetivo
Crecimiento orgánico mediante referidos de agencias.

### Tareas
1. **Código de referido** por agencia: `tap_referral_code` (unique, 8 chars).
2. **Tracking**: tabla `tap_referrals` (referrer_agency, referred_agency, status, commission_earned).
3. **Comisión por referido**: % de la comisión del referido durante 12 meses.
4. **Dashboard de referidos** en el panel: link compartible, métricas (clicks, registros, conversiones, comisiones).
5. **Email de invitación**: plantilla editable.
6. **Landing page** `/referidos/` con pitch y formulario.

### Métricas de éxito
- 20% de nuevas agencias vía referido en 6 meses.
- CAC reducido 30%.

---

## Fase 3 — Fidelización de viajeros (4-5 semanas)

### Objetivo
Retención y repetición de compra.

### Tareas
1. **Sistema de puntos**: tabla `tap_loyalty_points` (user_id, points, source, expires_at).
   - Ganar: 1 punto por cada $1 gastado (configurable).
   - Canjear: 100 puntos = $1 descuento.
2. **Niveles**: Bronce (0-499), Plata (500-1999), Oro (2000+). Beneficios por nivel (comisión reducida, prioridad soporte, upgrades).
3. **UI en checkout**: mostrar puntos a ganar y opción de canjear.
4. **Dashboard de fidelidad** en /mi-cuenta/: puntos, nivel, historial.
5. **Email de cumpleaños**: descuento especial.
6. **Expiración de puntos**: 12 meses de inactividad.

### Métricas de éxito
- +25% tasa de repetición de compra.
- NPS +10 puntos.

---

## Fase 4 — Multi-moneda y pagos locales (3-4 semanas)

### Objetivo
Reducir fricción de pago para mercados locales.

### Tareas
1. **Tabla `tap_currencies`**: code, name, symbol, rate_to_usd, is_active.
2. **Conversión automática**: todos los precios se guardan en USD; se muestran en la moneda del usuario.
3. **Selector de moneda** en header y checkout.
4. **Pasarelas**:
   - SINPE Móvil (CR) — webhook de confirmación.
   - Stripe — tarjetas internacionales.
   - MercadoPago — LATAM.
5. **Pago parcial**: depósito + saldo (configurable por agencia).
6. **Factura en moneda local**.

### Métricas de éxito
- +40% conversión en checkout (CR).
- Reducción de abandono de carrito 20%.

---

## Fase 5 — Analytics avanzado (3-4 semanas)

### Objetivo
Dar visibilidad a agencias para optimizar su negocio.

### Tareas
1. **Dashboard de analytics** en /mi-cuenta/: vistas, clicks, reservas, ingresos, conversión.
2. **Comparativa vs período anterior** (MoM, YoY).
3. **Top servicios** por vistas, conversión, revenue.
4. **Mapa de calor de búsquedas** (qué buscan los viajeros).
5. **Cohort analysis**: retención de clientes por mes de primera reserva.
6. **Exportación** a CSV/PDF.

### Métricas de éxito
- 80% de agencias activas usan analytics semanalmente.
- +10% revenue por agencia que usa analytics.

---

## Fase 6 — Email marketing automatizado (2-3 semanas)

### Objetivo
Re-engagement y nurturing.

### Tareas
1. **Newsletter semanal**: destinos destacados, ofertas.
2. **Campañas de re-engagement**: "Hace 6 meses viajaste a X".
3. **Segmentación**: por preferencias (aventura, relax, familia), por destino visitado.
4. **A/B testing** de asuntos y contenido.
5. **Plantillas editables** desde el panel admin.

### Métricas de éxito
- Open rate > 25%.
- +5% revenue atribuido a email.

---

## Fase 7 — Gestión de inventario avanzada (3-4 semanas)

### Objetivo
Optimizar ocupación y reducir overbooking.

### Tareas
1. **Overbooking controlado**: % configurable por agencia.
2. **Waitlist**: lista de espera cuando no hay disponibilidad; notificación automática cuando se libera.
3. **Sincronización iCal**: importar/exportar disponibilidad con OTAs (Booking.com, Airbnb).
4. **Bulk update de disponibilidad**: bloquear rangos de fechas.

### Métricas de éxito
- Ocupación +8%.
- 0 overbookings no intencionales.

---

## Fase 8 — Reviews y UGC (2-3 semanas)

### Objetivo
Aumentar confianza y contenido generado por usuarios.

### Tareas
1. **Fotos en reseñas**: uploader de imágenes en el form de review.
2. **Q&A**: preguntas y respuestas por servicio.
3. **Insignias**: "Top reviewer", "Fotógrafo", "Viajero verificado".
4. **Moderación**: cola de aprobación para fotos.

### Métricas de éxito
- +30% reseñas con foto.
- +15% conversión en servicios con fotos de viajeros.

---

## Fase 9 — Notificaciones push (2 semanas)

### Objetivo
Engagement en tiempo real.

### Tareas
1. **Web Push API**: suscripción en el navegador.
2. **Notificaciones**: nueva reserva, mensaje de chat, promoción.
3. **Preferencias**: el usuario elige qué recibir.
4. **Fallback a email** si push no disponible.

### Métricas de éxito
- 40% de usuarios suscritos.
- +20% engagement (clicks en notificaciones).

---

## Fase 10 — Seguro de viaje integrado (3-4 semanas)

### Objetivo
Revenue adicional por comisión de seguros.

### Tareas
1. **Integración con aseguradora** (Allianz o similar).
2. **UI en checkout**: opción de agregar seguro.
3. **Comisión por póliza** para la plataforma y la agencia.
4. **Factura de seguro**.

### Métricas de éxito
- 15% de reservas incluyen seguro.
- Revenue adicional $X por mes.

---

## Fase 11 — Transferencias compartidas / shuttle matching (2-3 semanas)

### Objetivo
Optimizar costos y ocupación de transportes.

### Tareas
1. **Matching de pasajeros** por ruta y fecha.
2. **Precio dinámico** según ocupación.
3. **Notificación** cuando se completa el shuttle.

### Métricas de éxito
- +25% ocupación de shuttles.
- -15% costo por pasajero.

---

## Fase 12 — Marketplace de experiencias (2-3 semanas)

### Objetivo
Ampliar el catálogo con experiencias cortas.

### Tareas
1. **Nuevo tipo `tap_experience`**: duración corta, capacidad pequeña, precio por persona.
2. **Editor y listado** (reusar patrón de tours).
3. **Filtros** por duración, precio, tipo de experiencia.

### Métricas de éxito
- 50+ experiencias en 3 meses.
- +10% revenue de experiencias.

---

## Fase 13 — Multi-idioma de contenido (2-3 semanas)

### Objetivo
Atraer viajeros internacionales.

### Tareas
1. **Campos de traducción** por servicio (español/inglés).
2. **Selector de idioma** en el front-end.
3. **Fallback** a español si no hay traducción.

### Métricas de éxito
- 30% de servicios con traducción.
- +20% tráfico internacional.

---

## Fase 14 — Facturación electrónica (2-3 semanas)

### Objetivo
Cumplimiento fiscal (CR).

### Tareas
1. **Generar factura PDF** por reserva.
2. **Integración con Hacienda** (API de facturación electrónica CR).
3. **Envío automático** por email.

### Métricas de éxito
- 100% de reservas con factura.
- 0 multas por incumplimiento.

---

## Fase 15 — Gestión de equipo avanzada (2 semanas)

### Objetivo
Roles granulares y audit log.

### Tareas
1. **Roles**: recepcionista, guía, contador (además de admin/empleado).
2. **Permisos por sección**: cada rol ve solo lo que necesita.
3. **Audit log**: registro de acciones (quién hizo qué, cuándo).

### Métricas de éxito
- 0 incidentes de acceso no autorizado.
- +30% adopción de roles granulares.

---

## Fase 16 — Integración con calendarios (1-2 semanas)

### Objetivo
Sincronización bidireccional de disponibilidad.

### Tareas
1. **Exportar iCal** de disponibilidad por servicio.
2. **Importar iCal** de OTAs (Booking.com, Airbnb).
3. **Sync automático** cada X horas.

### Métricas de éxito
- 0 double bookings.
- +50% agencias usando sync.

---

## Fase 17 — Comunidad / foro (3-4 semanas)

### Objetivo
Engagement y UGC.

### Tareas
1. **Foro por destino**: preguntas, tips, fotos.
2. **Moderación**: cola de aprobación.
3. **Reputación**: puntos por contribución.

### Métricas de éxito
- 100+ posts por mes.
- +10% tiempo en sitio.

---

## Fase 18 — Itinerario colaborativo (2 semanas)

### Objetivo
Compartir y votar itinerarios.

### Tareas
1. **Compartir itinerario** por link.
2. **Votación** de actividades por amigos/familia.
3. **Comentarios** en el itinerario.

### Métricas de éxito
- 20% de itinerarios compartidos.
- +15% reservas desde itinerarios compartidos.

---

## Resumen de esfuerzo y prioridad

| Fase | Nombre | Esfuerzo | Prioridad | Impacto en revenue |
|------|--------|----------|-----------|-------------------|
| 0 | Fundaciones | 6 sem | 🔴 Crítica | Base |
| 1 | Yield pricing | 4-6 sem | 🔴 Alta | +15% |
| 2 | Referidos | 3-4 sem | 🔴 Alta | CAC -30% |
| 3 | Fidelización | 4-5 sem | 🔴 Alta | Repetición +25% |
| 4 | Multi-moneda/pagos | 3-4 sem | 🔴 Alta | Conversión +40% |
| 5 | Analytics | 3-4 sem | 🟡 Media | Retención +10% |
| 6 | Email marketing | 2-3 sem | 🟡 Media | Revenue +5% |
| 7 | Inventario avanzado | 3-4 sem | 🟡 Media | Ocupación +8% |
| 8 | Reviews/UGC | 2-3 sem | 🟡 Media | Conversión +15% |
| 9 | Push notifications | 2 sem | 🟡 Media | Engagement +20% |
| 10 | Seguro de viaje | 3-4 sem | 🟢 Baja | Revenue adicional |
| 11 | Shuttle matching | 2-3 sem | 🟢 Baja | Ocupación +25% |
| 12 | Experiencias | 2-3 sem | 🟢 Baja | Catálogo + |
| 13 | Multi-idioma contenido | 2-3 sem | 🟢 Baja | Tráfico +20% |
| 14 | Facturación electrónica | 2-3 sem | 🟢 Baja | Compliance |
| 15 | Equipo avanzado | 2 sem | 🟢 Baja | Seguridad |
| 16 | Calendarios | 1-2 sem | 🟢 Baja | 0 double bookings |
| 17 | Comunidad | 3-4 sem | 🟢 Baja | Engagement |
| 18 | Itinerario colaborativo | 2 sem | 🟢 Baja | Reservas +15% |

**Total estimado**: ~50-60 semanas de desarrollo (1 developer full-time).

---

## Recomendación de roadmap (12 meses)

### Q1 (meses 1-3): Fundaciones + Revenue
- Fase 0 (fundaciones)
- Fase 1 (yield pricing)
- Fase 4 (multi-moneda/pagos locales)

### Q2 (meses 4-6): Crecimiento
- Fase 2 (referidos)
- Fase 3 (fidelización)
- Fase 5 (analytics)

### Q3 (meses 7-9): Engagement
- Fase 6 (email marketing)
- Fase 7 (inventario avanzado)
- Fase 9 (push notifications)

### Q4 (meses 10-12): Expansión
- Fase 8 (reviews/UGC)
- Fase 10 (seguro)
- Fase 12 (experiencias)
- Fase 13 (multi-idioma)

---

## KPIs globales (12 meses)

| Métrica | Hoy | Objetivo 12m |
|---------|-----|--------------|
| Agencias activas | ~? | +50% |
| Servicios publicados | ~? | +100% |
| Reservas/mes | ~? | +200% |
| Revenue/mes | ~? | +300% |
| Tasa de conversión | ~? | +50% |
| NPS | ~? | +20 pts |
| Churn de agencias | ~? | -30% |

---

## Próximos pasos inmediatos

1. **Decidir prioridad**: ¿empezamos con Fase 0 (fundaciones) o saltamos a Fase 1 (yield pricing) asumiendo que la infraestructura actual es suficiente?
2. **Estimar recursos**: ¿1 developer full-time? ¿2? ¿equipo?
3. **Validar con usuarios**: entrevistar 5-10 agencias para confirmar prioridades.
4. **Definir métricas baseline**: medir el estado actual para comparar.

---

*Plan generado el 2026-10-28. Revisar y ajustar según feedback.*
