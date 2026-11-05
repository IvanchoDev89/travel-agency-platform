# Análisis de funciones de administración (Admin)

## Menú principal: Travel Platform

### 1. Bookings (Reservas)
- **Funciones**: listar, filtrar, cambiar estado, cancelar, exportar CSV.
- **Mejoras**:
  - Vista de calendario (FullCalendar).
  - Filtros avanzados (rango de fechas, agencia, servicio).
  - Acciones masivas (confirmar/cancelar varias).
  - Exportación a PDF (voucher, factura).
  - Notas internas por reserva.

### 2. Commissions (Comisiones)
- **Funciones**: ledger, pagos, disputas.
- **Mejoras**:
  - Dashboard con gráficos de tendencia.
  - Conciliación bancaria (importar extracto CSV).
  - Alertas de pagos pendientes (>7 días).
  - Facturación automática (PDF).

### 3. Reviews (Reseñas)
- **Funciones**: listar, aprobar/rechazar, responder.
- **Mejoras**:
  - Filtro por rating, fecha, servicio.
  - Moderación automática (ya existe) + cola de revisión.
  - Respuesta con template.
  - Análisis de sentimiento (NLP básico).

### 4. Moderation (Moderación)
- **Funciones**: moderar contenido, bloquear usuarios.
- **Mejoras**:
  - Queue con prioridad (abuso primero).
  - Historial de moderación.
  - Reglas configurables (keywords, links).

### 5. Disputes (Disputas)
- **Funciones**: gestionar disputas de reservas.
- **Mejoras**:
  - SLA de respuesta (24h).
  - Escalación automática.
  - Integración con reembolsos.

### 6. Agencies (Agencias)
- **Funciones**: aprobar/rechazar, KYC, comisión, verificación.
- **Mejoras**:
  - Vista de pipeline (pendiente → aprobada → activa).
  - KYC con upload de documentos.
  - Scoring de agencia (rating, volumen, cancelaciones).
  - Comunicación in-app (chat).

### 7. Reports (Reportes)
- **Funciones**: reportes de reservas, ingresos, comisiones.
- **Mejoras**:
  - Gráficos interactivos.
  - Exportación PDF.
  - Reportes programados (email).
  - Cohortes (✅ implementado en analytics).

### 8. Analytics (Analytics)
- **Funciones**: funnel, KPIs, top agencias/listados.
- **Mejoras**:
  - Dashboard de plataforma (GMV, agencias activas, conversión).
  - Cohortes (✅ implementado).
  - Alertas automáticas.
  - Atribución UTM (✅ implementado).

### 9. Invoices (Facturas)
- **Funciones**: listar facturas.
- **Mejoras**:
  - Generación automática de PDF.
  - Envío por email.
  - Integración con contabilidad.

### 10. Email campaigns (Campañas de email)
- **Funciones**: crear, programar, enviar campañas.
- **Mejoras**:
  - Segmentación avanzada (por agencia, por servicio).
  - A/B testing de asunto.
  - Métricas (open rate, click rate).
  - Templates visuales (drag & drop).

### 11. Plans (Planes)
- **Funciones**: gestionar planes de suscripción.
- **Mejoras**:
  - Comparativa de planes (tabla).
  - Upgrade/downgrade con prorrateo.
  - Trial period.

### 12. Subscriptions (Suscripciones)
- **Funciones**: listar suscripciones activas.
- **Mejoras**:
  - Alertas de renovación.
  - Churn analysis.
  - MRR/ARR dashboard.

### 13. Promotions (Promociones)
- **Funciones**: featured listings, activar/expirar.
- **Mejoras**:
  - Analytics de conversión por destacado.
  - A/B testing de posición.
  - Auto-renovación.

### 14. Settings (Configuración)
- **Funciones**: configuración general.
- **Mejoras**:
  - Sección por categoría (pagos, email, seguridad, etc.).
  - Validación de campos.
  - Import/export de configuración.

### 15. Destinations (Destinos)
- **Funciones**: gestionar destinos y spots.
- **Mejoras**:
  - Mapa interactivo de destinos.
  - SEO por destino.
  - Contenido editorial por destino.

### 16. Landing Page (Landing)
- **Funciones**: builder con drag & drop, preview, secciones.
- **Mejoras**:
  - Más secciones (testimonios, FAQ, CTA).
  - A/B testing de landing.
  - Analytics de conversión.

### 17. Market tools (Herramientas de mercado)
- **Funciones**: herramientas de marketing.
- **Mejoras**:
  - Widget embebible.
  - Código QR por tour.
  - Integración con redes sociales.

### 18. Security headers / Referrals / Email templates (nuevos)
- **Mejoras**:
  - Dashboard de seguridad (intentos de login, rate limits).
  - Reportes de referidos (✅ implementado).
  - Editor de emails (✅ implementado).

---

## Priorización (impacto vs esfuerzo)

| # | Área | Impacto | Esfuerzo | Prioridad |
|---|------|---------|----------|-----------|
| 1 | Dashboard financiero (admin) | Alto | Medio | P0 |
| 2 | Facturación automática (PDF) | Alto | Medio | P0 |
| 3 | Vista de calendario (bookings) | Medio | Medio | P1 |
| 4 | KYC con upload de documentos | Alto | Medio | P1 |
| 5 | Alertas automáticas | Medio | Bajo | P1 |
| 6 | A/B testing de landing | Medio | Alto | P2 |
| 7 | Email campaigns (métricas) | Medio | Medio | P2 |
| 8 | 2FA | Alto | Medio | P1 |

## Próximos pasos
Implementar P0 (dashboard financiero admin + facturación PDF).