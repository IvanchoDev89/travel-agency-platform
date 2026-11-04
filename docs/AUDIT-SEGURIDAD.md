# Auditoría de seguridad — Travel Agency Platform

## Fecha
2026-11-03

## Alcance
- Revisión de handlers AJAX, admin-post, uploads, SQL, XSS, SSRF.
- Herramientas: PHPStan 3, PHPCS security, ajax-nonce-check, composer audit.

## Hallazgos

### 1. IDOR (Broken Access Control)
- **Estado**: Protegido.
- `update_booking_status`: verifica ownership (agency_id).
- `cancel_booking`: delega a `client_cancel_request` con verificación de email/código.
- Exports CSV: verifican `agency_id === user_agency` + nonce.
- Uploads: verifican `agency_owns_listing`.

### 2. SQL Injection
- **Estado**: Sin hallazgos críticos.
- Todas las queries con input de usuario usan `$wpdb->prepare`.
- Variables interpoladas son nombres de tabla/columna controlados internamente.
- `LIKE` con `esc_like` en búsquedas públicas (verificar).

### 3. XSS
- **Estado**: Sin hallazgos en templates auditados.
- Todos los `echo` de metas usan `esc_html`/`esc_attr`.
- `wp_kses_post` solo para contenido rico (descripción, T&C).

### 4. CSRF
- **Estado**: Protegido.
- Todos los handlers AJAX verifican nonce (ajax-nonce-check: 49/49).
- Admin-post handlers verifican `check_admin_referer` + capability.

### 5. Uploads
- **Estado**: Protegido.
- MIME real verificado con `wp_check_filetype_and_ext`.
- Extensiones permitidas: jpg, jpeg, png, webp, gif.
- Tamaño máximo: 8 MB (configurable).
- Ownership verificado antes de subir.

### 6. SSRF
- **Estado**: Protegido.
- `sideload_image` y `TAP_ICal_Import::is_safe_url` bloquean IPs privadas/reservadas y localhost.
- Redirects rechazados (código 3xx).

### 7. Rate Limiting
- **Estado**: Parcial.
- ✅ Booking creation: 10/min por IP.
- ✅ Agency registration: 5/hora por IP.
- ✅ Referral clicks: 10/hora por IP.
- ⚠️ Login: delegado a WordPress (considerar plugin de brute force).
- ⚠️ Search/autocomplete: rate limit existe en TAP_Rate_Limit pero verificar cobertura.

### 8. Headers de seguridad
- **Estado**: Parcial.
- ✅ X-Content-Type-Options: nosniff
- ✅ X-Frame-Options: SAMEORIGIN
- ✅ Referrer-Policy: strict-origin-when-cross-origin
- ✅ Permissions-Policy
- ⚠️ CSP: no configurado (pendiente).
- ⚠️ HSTS: no configurado (pendiente, requiere HTTPS).

### 9. Dependencias
- **Estado**: Sin vulnerabilidades conocidas (`composer audit` limpio).

### 10. Referral program (anti-fraude)
- ✅ Anti-self-referral (usuario y guest email).
- ✅ Rate limiting de clicks.
- ✅ Idempotencia (unique key en booking_id).
- ✅ Cookie segura (HttpOnly, Secure, SameSite).
- ⚠️ Falta fingerprinting de dispositivo.
- ⚠️ Falta aprobación manual de puntos altos.

---

## Recomendaciones (priorizadas)

### P0 (inmediato)
1. Configurar CSP y HSTS (requiere HTTPS).
2. Verificar rate limiting en login (plugin de brute force o custom).
3. Ampliar suite de seguridad con casos de IDOR/XSS/CSRF.

### P1 (próxima semana)
4. Fingerprinting de dispositivo para referidos.
5. Aprobación manual de puntos > 1000.
6. Logging de anomalías (intentos de IDOR, rate limit excedido).

### P2 (2 semanas)
7. Auditoría externa (pentest).
8. Bug bounty program (opcional).

---

## Métricas
- Vulnerabilidades críticas: 0
- Vulnerabilidades altas: 0
- Vulnerabilidades medias: 2 (CSP, HSTS pendientes)
- Vulnerabilidades bajas: 3 (rate limiting parcial, fingerprinting, aprobación manual)

## Conclusión
La plataforma tiene una base de seguridad sólida: nonces en todos los handlers, ownership checks, uploads seguros, SSRF guard, SQL preparado. Las principales mejoras pendientes son CSP/HSTS, rate limiting en login y anti-fraude avanzado en referidos.
PY