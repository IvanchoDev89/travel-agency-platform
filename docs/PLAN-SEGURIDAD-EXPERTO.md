# Plan de seguridad — Visión experta (Fase 0)

> **Estado:** ✅ Completado (2026-11-05)

## 1. Modelo de amenazas (STRIDE)

### Activos críticos
- Reservas y pagos (dinero real).
- Datos personales (GDPR/privacidad).
- Comisiones y ledger.
- Credenciales y sesiones.
- Archivos subidos (imágenes).

### Actores
- **Anónimo**: scraping, spam, abuse de endpoints públicos.
- **Cliente malicioso**: manipulación de precios, IDOR en reservas, self-referral.
- **Agencia maliciosa**: acceso a reservas de otras agencias, manipulación de inventario, fraude de comisiones.
- **Insider (admin)**: escalación, exfiltración.
- **Bot/scraper**: rate abuse, credential stuffing.

---

## 2. Mapeo OWASP Top 10 (2021)

### A01 — Broken Access Control
**Riesgo alto.** Verificar:
- IDOR en `booking_id`, `listing_id`, `agency_id`, `room_id`.
- Capability checks en AJAX (no solo nonce).
- Ownership checks (`agency_owns_listing`, `agency_owns_booking`).
- Escalación de privilegios (cliente → agencia → admin).
- Acceso a exports (CSV) sin filtro de agencia.

**Archivos a auditar:**
- `class-ajax.php` (update_booking_status, cancel_booking, etc.)
- `class-ajax-listing.php` (save_listing, delete_room, toggle_publish)
- `class-ajax-dash.php`
- `class-ajax-public.php`
- `class-front-dash.php` (exports, CSV)
- `class-ajax-commerce.php`

### A02 — Cryptographic Failures
- HTTPS obligatorio (HSTS).
- Secrets en wp-config (no hardcodeados).
- Cookies: Secure, HttpOnly, SameSite (✅ parcial).
- Passwords: WordPress hashing (OK).

### A03 — Injection
- **SQLi**: buscar interpolación en SQL, `LIKE` sin esc_like, `ORDER BY` con input.
- **XSS**: echo de metas sin escape, shortcodes, templates.
- **Command injection**: sideload de URLs (SSRF).

### A04 — Insecure Design
- Lógica de negocio: double booking, price manipulation, referral fraud.
- Falta de rate limiting en endpoints críticos.
- Falta de validación de invariantes (precios > 0, fechas válidas).

### A05 — Security Misconfiguration
- Debug activo en producción.
- Headers de seguridad (✅ parcial).
- Permisos de archivos.
- `wp-config` con `DISALLOW_FILE_EDIT`.

### A06 — Vulnerable Components
- `composer audit`.
- Plugins de terceros.
- Versiones de WP/PHP.

### A07 — Identification & Authentication Failures
- Brute force en login.
- Session fixation.
- Password reset flow.
- Rate limiting en login.

### A08 — Software & Data Integrity
- Uploads: MIME real, tamaño, ownership, path traversal.
- Deserialización insegura (unserialize de input).
- Sideload de URLs (SSRF).

### A09 — Security Logging & Monitoring
- Logs de acciones sensibles (✅ audit_log).
- Alertas de anomalías (pendiente).
- Monitoreo de errores (debug.log no en producción).

### A10 — SSRF
- `sideload_image($url)`: validar URL, bloquear IPs privadas (✅ ya implementado).
- Verificar que no haya otros fetch de URLs controladas por usuario.

---

## 3. Plan de acción por prioridad

### P0 — Crítico (esta semana)
1. **IDOR en bookings**: verificar ownership en todos los handlers.
2. **Uploads**: verificar MIME real y path traversal.
3. **SQL injection**: revisar todas las queries con variables.
4. **SSRF**: verificar sideload y otros fetch.

### P1 — Alto (próxima semana)
5. **XSS**: revisar templates y shortcodes.
6. **Rate limiting**: login, booking, AJAX público.
7. **Headers**: completar CSP, HSTS.
8. **CSRF**: verificar admin-post handlers.

### P2 — Medio (2 semanas)
9. **Logging**: alertas de anomalías.
10. **Dependency audit**: composer audit, actualizar.
11. **Tests de seguridad**: ampliar suite.
12. **Documentación**: reporte final.

---

## 4. Checklist de revisión por archivo

### class-ajax.php
- [ ] `create_booking`: rate limit ✅, nonce ✅, validación de guests ✅.
- [ ] `cancel_booking`: ownership? verificar.
- [ ] `update_booking_status`: ownership ✅.
- [ ] `chat_client_send`: rate limit? verificar.
- [ ] `submit_lead`: rate limit? verificar.

### class-ajax-listing.php
- [ ] `agency_save_listing`: ownership ✅, capability ✅.
- [ ] `agency_save_room`: ownership? verificar.
- [ ] `agency_delete_room`: ownership? verificar.
- [ ] `agency_toggle_publish`: ownership? verificar.

### class-ajax-public.php
- [ ] `search_services`: rate limit? verificar.
- [ ] `calculate_booking_total`: nonce ✅.
- [ ] `search_suggestions`: rate limit? verificar.
- [ ] `get_rooms`: validación? verificar.

### class-ajax-dash.php
- [ ] Todos los handlers: nonce + capability + ownership.

### class-ajax-commerce.php
- [ ] PayPal order creation: validación de montos.
- [ ] Subscription: ownership.

### class-agency-media.php
- [ ] Upload: MIME real ✅, tamaño ✅, ownership ✅.
- [ ] Sideload: SSRF guard ✅.

### Templates
- [ ] `single-service.php`: escape de metas.
- [ ] `single-tap_tour.php`: escape de metas.
- [ ] `single-tap_accommodation.php`: escape de metas.

---

## 5. Herramientas de testing

### Estáticas
- `make audit-security` (phpcs security).
- `make analyse` (PHPStan).
- `make ajax-nonce-check`.
- `make includes-check`.

### Dinámicas
- OWASP ZAP (spider + active scan).
- Burp Suite (manual).
- SQLmap (en endpoints con input).
- XSStrike (XSS).

### Manuales
- Pruebas de IDOR (cambiar IDs en requests).
- Pruebas de CSRF (sin nonce).
- Pruebas de rate limiting (flood).
- Pruebas de upload (archivos maliciosos).

---

## 6. Métricas de éxito
- 0 vulnerabilidades críticas/altas.
- `audit-security` sin errores en código propio (ignorar vendor).
- Suite de seguridad con >= 30 casos.
- Headers de seguridad verificados (securityheaders.com).
- Rate limiting en todos los endpoints públicos.

---

## 7. Entregables
1. Reporte de auditoría (`docs/AUDIT-SEGURIDAD.md`).
2. Fixes aplicados.
3. Suite de seguridad ampliada.
4. Headers configurados.
5. Documentación de hardening.

---

## 8. Estimación
- Auditoría manual: 3-4 días.
- Fixes: 3-5 días.
- Tests: 2-3 días.
- Hardening: 1-2 días.
- Documentación: 1 día.
- **Total: 2-2.5 semanas.**

## Próximo paso
Ejecutar `composer audit` y revisar handlers de bookings para IDOR.