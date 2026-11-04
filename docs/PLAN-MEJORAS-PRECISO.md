# Plan de mejoras — Travel Agency Platform (2026)

## Objetivo
Convertir la plataforma en un producto rentable, seguro y profesional para B2B (agencias) y B2C (viajeros), con crecimiento orgánico (referidos) y alta conversión.

## Principios
- Seguridad primero (OWASP, nonce, capability, prepared statements).
- Medición (funnel, CAC, LTV, conversión).
- UX clara y mobile-first.
- Código probado (PHPStan 3, PHPCS, E2E).

---

## Fase 0 — Estabilización y seguridad base (1-2 semanas)
**Prioridad: Crítica**

1. **Auditoría de seguridad completa**
   - Revisar `class-ajax.php`, `class-ajax-listing.php`, `class-ajax-public.php`, `class-ajax-dash.php` (nonce + capability + ownership).
   - Revisar uploads (`class-agency-media.php`): MIME, tamaño, ownership.
   - Revisar SQL (prepared statements, `LIKE` con wildcards).
   - Revisar XSS en templates (echo de metas sin escape).
   - Revisar CSRF en admin-post handlers.
   - Entregable: reporte + fixes + `audit-security` en verde.

2. **Hardening de infraestructura**
   - Headers de seguridad (CSP, HSTS, X-Frame-Options) — ya hay doc.
   - Rate limiting global (login, booking, AJAX).
   - Logs de auditoría para acciones sensibles (ya existe `tap_audit_log`).

3. **Tests de seguridad**
   - Suite `suite_security` ampliada con casos de IDOR, XSS, CSRF.

---

## Fase 1 — Crecimiento: Referidos y afiliados (2-3 semanas) ✅ Parcial
**Prioridad: Alta**

1. **Anti-fraude avanzado** (pendiente)
   - Fingerprinting de dispositivo (cookie + hash UA/IP).
   - Límite por mes por referidor (ej. 20).
   - Aprobación manual de puntos > umbral (ej. 1000 pts).
   - Blacklist de emails/IPs.
   - Cron de expiración de puntos (✅ implementado).

2. **Analytics de referidos** (✅ implementado básico)
   - Dashboard admin con métricas y top referidores (✅).
   - Gráficos (Chart.js) y cohortes por mes (pendiente).
   - Export CSV (✅).

3. **Notificaciones**
   - Email al referidor (✅).
   - Notificación in-app (badge) (pendiente).
   - Recordatorio a invitado que no completó reserva (pendiente).

4. **Gamificación**
   - Leaderboard público (pendiente).
   - Badges (primer referido, 10 referidos, etc.) (pendiente).

---

## Fase 2 — Conversión B2C (2-3 semanas)
**Prioridad: Alta**

1. **Checkout optimizado**
   - Stepper visual (datos → pago → confirmación).
   - Guardado de tarjeta (PayPal vaulting).
   - Apple Pay / Google Pay.
   - Cupones de descuento (además de referidos).

2. **Confianza**
   - Reviews con foto y video.
   - Badge "Reserva verificada" en reviews.
   - Garantía de mejor precio visible.
   - Chat en vivo (ya hay chatbot).

3. **Upsells**
   - Bundles (tour + seguro + transporte).
   - Seguro de viaje (ya existe módulo).
   - Fotos del tour post-experiencia.
   - Upgrade a tour privado.

4. **Recuperación de carritos**
   - Email automático si abandona checkout (ya hay automations).
   - Recordatorio 24h antes.

---

## Fase 3 — Experiencia de agencia (B2B) (2 semanas)
**Prioridad: Media-Alta**

1. **Onboarding guiado**
   - Wizard de primer tour con checklist.
   - Tooltips contextuales.
   - Video tutoriales embebidos.

2. **Dashboard de agencia mejorado**
   - KPIs en tiempo real (reservas hoy, ingresos mes, ocupación).
   - Gráficos de tendencia.
   - Alertas (baja ocupación, reviews negativas).

3. **Herramientas de marketing**
   - Widget embebible para sitios web.
   - Código QR por tour (✅ en referidos, extender).
   - Landing page por agencia (ya existe agency profile).
   - Email marketing integrado (ya existe campaigns).

4. **Gestión de equipo**
   - Roles granulares (ya existe).
   - Asignación de reservas a empleados.

---

## Fase 4 — Frontend y mobile (2 semanas)
**Prioridad: Media**

1. **Diseño responsive avanzado**
   - Mobile-first para ficha de tour (✅ parcial).
   - Widget de reserva sticky en mobile.
   - Calendario táctil.

2. **PWA**
   - Manifest + service worker.
   - Offline para fichas vistas.
   - Push notifications (ya existe módulo push).

3. **Performance**
   - Lazy loading de imágenes (✅ parcial).
   - WebP/AVIF.
   - Cache de páginas (WP Rocket / LiteSpeed).
   - CDN para assets.

4. **Accesibilidad (a11y)**
   - ARIA labels, contraste, navegación por teclado.
   - Lighthouse score > 90.

---

## Fase 5 — SEO y contenido (1-2 semanas)
**Prioridad: Media**

1. **SEO técnico**
   - Schema.org (TouristAttraction ✅, Event, Review).
   - Sitemap (ya existe).
   - Meta tags dinámicos (ya existe SEO).
   - Canonical URLs.

2. **Contenido**
   - Blog con guías de destino (ya existe blog).
   - Landing pages por destino.
   - FAQ por tour.

3. **Indexación**
   - Google Search Console integration.
   - Rich snippets para reviews.

---

## Fase 6 — Internacionalización (1-2 semanas)
**Prioridad: Media-Baja**

1. **Multi-idioma**
   - WPML/Polylang integration.
   - Traducción de metas y taxonomías.
   - Monedas por región (ya existe multi-currency).

2. **Localización**
   - Formatos de fecha/hora por locale.
   - Números y monedas (ya existe).

---

## Fase 7 — Analytics y BI (1-2 semanas)
**Prioridad: Media**

1. **Dashboard de plataforma (admin)**
   - GMV, reservas, agencias activas, tours publicados.
   - Funnel de conversión.
   - Cohortes de usuarios.

2. **Reportes**
   - Export CSV/PDF (CSV ✅).
   - Reportes programados por email.

3. **Atribución**
   - UTM tracking.
   - Fuente de tráfico por reserva.

---

## Fase 8 — Monetización avanzada (2-3 semanas)
**Prioridad: Alta (rentabilidad)**

1. **Comisiones escalonadas**
   - Por volumen de ventas.
   - Por rating de agencia.
   - Bonos por temporada alta.

2. **Featured listings**
   - Tours destacados pagados (ya existe promos).
   - Posicionamiento en resultados.

3. **Suscripciones de agencia**
   - Planes con límites (ya existe subscriptions).
   - Add-ons (más fotos, analytics premium).

4. **Afiliados externos**
   - API para afiliados externos.
   - Comisión por click/reserva.

---

## Métricas de éxito (KPIs)
- **Conversión**: visita → reserva > 3%, reserva → pago > 70%.
- **CAC**: < $15 por reserva.
- **LTV**: > $200 por usuario.
- **Retención**: 30% de usuarios repiten en 6 meses.
- **NPS**: > 50.
- **Uptime**: 99.9%.
- **Lighthouse**: > 90 en mobile.

## Riesgos y mitigaciones
| Riesgo | Mitigación |
|--------|------------|
| Fraude en referidos | Anti-fraude avanzado, aprobación manual |
| Sobrecarga de DB | Índices, cache, CDN |
| Regresiones | Suite E2E + PHPStan en CI |
| Adopción lenta | Onboarding guiado, soporte |

## Orden de ejecución recomendado
0 → 1 → 2 → 8 → 3 → 4 → 5 → 7 → 6

## Estimación total
- Fase 0: 1-2 sem
- Fase 1: 2-3 sem (parcial ✅)
- Fase 2: 2-3 sem
- Fase 3: 2 sem
- Fase 4: 2 sem
- Fase 5: 1-2 sem
- Fase 6: 1-2 sem
- Fase 7: 1-2 sem
- Fase 8: 2-3 sem
- **Total: 14-21 semanas**

## Próximo paso recomendado
Ejecutar **Fase 0** (seguridad) y completar **Fase 1** (anti-fraude avanzado) antes de invertir en marketing.
PY