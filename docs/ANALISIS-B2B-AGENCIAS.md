# Análisis riguroso — Funciones de agencias (B2B)

## 1. Onboarding y registro

### Estado actual
- Registro de agencia vía `TAP_Ajax::agency_register`.
- Rate limiting (5/hora por IP).
- Email de bienvenida + notificación admin.
- Aprobación manual (KYC).
- Checklist de primeros pasos (✅ implementado).

### Hallazgos
- **Falta stepper visual** en el formulario de registro (3 pasos).
- **No hay validación de documentos** (KYC) en el front-end (solo campos de texto).
- **Tiempo de aprobación no comunicado** claramente (solo en email).
- **No hay onboarding interactivo** (tooltips, videos).

### Recomendaciones
1. Stepper visual en registro (datos → agencia → confirmación).
2. Upload de documentos KYC (ID, licencia, seguro) con preview.
3. Mensaje claro de tiempo de aprobación (24-48h) en UI.
4. Tour guiado interactivo en primer login.
5. Email drip (día 1, 3, 7) con tips y recordatorios.

---

## 2. Gestión de listados

### Estado actual
- Wizard de 5 pasos para tours (URL-based).
- Wizard de 5 pasos para alojamientos.
- CRUD de habitaciones.
- Toggle de publicación.
- Galería de imágenes.

### Hallazgos
- **No hay duplicación de listados** (copiar un tour existente).
- **No hay importación masiva** (CSV/Excel).
- **No hay versionado** (historial de cambios).
- **No hay preview** antes de publicar (solo en paso 5).
- **No hay bulk actions** (publicar/despublicar/eliminar varios).

### Recomendaciones
1. Botón "Duplicar listado" en el panel.
2. Importador CSV para tours/alojamientos.
3. Preview en iframe (como landing page).
4. Bulk actions en el grid de listados.
5. Historial de cambios (audit log ya existe, exponerlo).

---

## 3. Precios y disponibilidad

### Estado actual
- Precios por persona (adulto/niño/senior/estudiante).
- Yield pricing (`tap_pricing_rules`).
- Calendario de disponibilidad para tours (slots).
- Daily pricing para rooms.

### Hallazgos
- **No hay precios dinámicos por demanda** (occupancy-based).
- **No hay descuentos por volumen** (grupos grandes).
- **No hay precios por temporada** (alta/baja) configurables fácilmente.
- **No hay sincronización con OTAs** (Booking.com, Expedia).
- **No hay overbooking configurable por servicio** (solo meta genérico).

### Recomendaciones
1. Reglas de pricing por ocupación (ej. +10% si >80% lleno).
2. Descuentos por grupo (ej. 5% si >10 personas).
3. Calendario de temporadas con precios base.
4. Integración iCal bidireccional (ya hay import, falta export).
5. Overbooking % configurable por servicio.

---

## 4. Reservas y operaciones

### Estado actual
- Reservas con código único.
- Chat por reserva (guest + agency).
- Cancelación con reglas.
- Reembolsos automáticos (PayPal).
- Export CSV.

### Hallazgos
- **No hay asignación de reservas a empleados**.
- **No hay notas internas** por reserva.
- **No hay etiquetas/tags** para clasificar reservas.
- **No hay vista de calendario** (solo lista).
- **No hay check-in/check-out tracking** (para tours: asistencia).

### Recomendaciones
1. Asignación de reservas a empleados.
2. Notas internas y tags.
3. Vista de calendario (FullCalendar).
4. Check-in de asistencia para tours.
5. Exportación a PDF (voucher, factura).

---

## 5. Finanzas y comisiones

### Estado actual
- Comisión por agencia (configurable).
- Comisiones escalonadas por volumen (✅ implementado).
- Ledger de comisiones.
- Pagos de comisión (admin).
- Export CSV.

### Hallazgos
- **No hay dashboard financiero** con gráficos de tendencia.
- **No hay proyección de ingresos**.
- **No hay alertas de pagos pendientes**.
- **No hay conciliación bancaria**.
- **No hay facturación automática** (PDF).

### Recomendaciones
1. Dashboard financiero con gráficos (Chart.js).
2. Proyección de ingresos (basado en reservas futuras).
3. Alertas de pagos pendientes (>7 días).
4. Facturación automática (PDF con logo).
5. Conciliación con extractos bancarios (CSV import).

---

## 6. Equipo y permisos

### Estado actual
- Roles: admin, employee.
- Invitación de empleados.
- Permisos granulares por sección.

### Hallazgos
- **No hay auditoría de acciones por empleado**.
- **No hay límite de sesiones concurrentes**.
- **No hay 2FA**.
- **No hay IP whitelist** (opcional).

### Recomendaciones
1. Audit log por usuario (ya existe tap_audit_log, exponerlo).
2. 2FA (TOTP) para admins y agencias.
3. Límite de sesiones concurrentes.
4. IP whitelist opcional para agencias.

---

## 7. Marketing y promociones

### Estado actual
- Promociones destacadas (featured listings).
- Email campaigns.
- Referidos de agencia (B2B).
- Leads capturados.

### Hallazgos
- **No hay A/B testing** de listings.
- **No hay remarketing** (Facebook/Google Ads integration).
- **No hay programa de fidelidad B2B** (solo B2C).
- **No hay cupones de descuento** (solo referidos).

### Recomendaciones
1. A/B testing de títulos/fotos.
2. Integración con Facebook/Google Ads (pixel).
3. Programa de fidelidad para agencias (puntos por volumen).
4. Cupones de descuento configurables.

---

## 8. Analytics y reportes

### Estado actual
- Booking funnel (✅ implementado).
- Reportes de referidos (✅ implementado).
- Stats básicos (reservas, ingresos, comisiones).

### Hallazgos
- **No hay dashboard de plataforma** (admin) con GMV, agencias activas, etc.
- **No hay cohortes** (retención por mes de registro).
- **No hay alertas** (caída de reservas, reviews negativas).
- **No hay exportación a PDF**.

### Recomendaciones
1. Dashboard de plataforma con KPIs globales.
2. Cohortes de usuarios y agencias.
3. Alertas automáticas (email/Slack).
4. Exportación a PDF de reportes.

---

## 9. Soporte y comunicación

### Estado actual
- Chat por reserva (guest + agency).
- Chatbot (básico).
- Leads con formulario.

### Hallazgos
- **No hay tickets de soporte** (sistema formal).
- **No hay base de conocimiento** (FAQ).
- **No hay notificaciones push** para nuevas reservas (módulo existe pero no integrado).
- **No hay SLA** de respuesta.

### Recomendaciones
1. Sistema de tickets (integrar con email).
2. Base de conocimiento (FAQ por categoría).
3. Push notifications para nuevas reservas.
4. SLA de respuesta (ej. 24h).

---

## 10. Seguridad y cumplimiento

### Estado actual
- Headers de seguridad (✅ HSTS/CSP).
- Rate limiting (✅ parcial).
- Audit log (✅ existe).
- Privacidad (GDPR) (✅ existe).

### Hallazgos
- **No hay 2FA**.
- **No hay IP whitelist** (opcional).
- **No hay backup automático**.
- **No hay monitoring** (uptime, errores).

### Recomendaciones
1. 2FA (TOTP) para admins y agencias.
2. Backup automático (diario) con retención.
3. Monitoring (UptimeRobot, Sentry).
4. IP whitelist opcional.

---

## Priorización (impacto vs esfuerzo)

| # | Área | Impacto | Esfuerzo | Prioridad |
|---|------|---------|----------|-----------|
| 1 | Onboarding guiado | Alto | Medio | P0 |
| 2 | Dashboard financiero | Alto | Medio | P0 |
| 3 | Vista de calendario | Medio | Medio | P1 |
| 4 | 2FA | Alto | Medio | P1 |
| 5 | Exportación PDF | Medio | Bajo | P1 |
| 6 | Duplicación de listados | Medio | Bajo | P1 |
| 7 | Precios dinámicos | Alto | Alto | P2 |
| 8 | A/B testing | Medio | Alto | P2 |
| 9 | Tickets de soporte | Medio | Alto | P2 |
| 10 | Internacionalización | Medio | Alto | P2 |

## Próximos pasos
Implementar P0 (onboarding guiado + dashboard financiero) en la próxima iteración.
PY