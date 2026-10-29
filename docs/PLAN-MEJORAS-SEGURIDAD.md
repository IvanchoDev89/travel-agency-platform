# Plan de mejoras de seguridad y hardening

## Objetivo
Cerrar las observaciones de la auditoría de seguridad y mejorar la robustez del plugin
Travel Agency Platform, manteniendo todas las quality gates en verde.

## Alcance
1. Hardening de importación iCal (SSRF).
2. Sanitización de logs de PayPal.
3. Invalidación de cache estático en destination spots.
4. Hardening adicional de inputs/outputs en endpoints públicos.
5. Tests de regresión para cada fix.

## Fases

### Fase 1 — SSRF en import iCal (Prioridad: Alta)
- **Archivo**: `includes/class-ical-import.php` (`fetch()`)
- **Cambio**: validar la URL antes de `wp_remote_get`:
  - Solo esquemas `http`/`https`.
  - Resolver host y bloquear IPs privadas/reservadas (127.0.0.0/8, 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 169.254.0.0/16, ::1, fc00::/7).
  - Bloquear `localhost`, `0.0.0.0`.
  - Rechazar si la resolución DNS falla.
- **Test**: suite nueva `suite_security` con casos de URL válida e inválida.

### Fase 2 — Sanitización de logs PayPal (Prioridad: Media)
- **Archivo**: `includes/class-ajax-commerce.php` (`payment_error()`)
- **Cambio**: loguear solo código de error + hash del mensaje (no el mensaje completo que puede incluir tokens).
- **Test**: verificar que el log no contenga el mensaje original.

### Fase 3 — Cache estático en destination spots (Prioridad: Baja)
- **Archivo**: `includes/class-destination-spots.php` (`spot_for_term()`, `set_location_term()`)
- **Cambio**: limpiar `$cache[$term_id]` al vincular/desvincular; o usar `wp_cache_*` con grupo invalidable.
- **Test**: vincular y re-consultar en el mismo request.

### Fase 4 — Hardening de endpoints REST públicos (Prioridad: Media)
- **Archivos**: `class-api.php`, `class-shuttle.php`, `class-itinerary-collab.php`
- **Cambios**:
  - Rate limiting básico en `/shuttle/join` y `/itinerary/vote|comment` (transients por IP).
  - Validación estricta de `service_id` (absint + exists).
  - Sanitización de `email` en vote (ya sanitizado, verificar).
- **Test**: requests inválidos devuelven 400.

### Fase 5 — Headers de seguridad (Prioridad: Baja)
- **Archivo**: bootstrap o `.htaccess` (fuera del plugin, en server config).
- **Cambio**: documentar headers recomendados (CSP, X-Content-Type-Options, X-Frame-Options) para el hosting.
- **Nota**: no implementar en plugin (depende del servidor).

## Criterios de aceptación
- Todas las quality gates en verde (PHPStan 3, includes-check, ajax-nonce-check, i18n-check).
- Full test suite: 0 failing.
- CI: 3/3 jobs success.
- Cada fix con test de regresión.

## Orden de ejecución
1. Fase 1 (SSRF) — mayor impacto de seguridad.
2. Fase 2 (logs).
3. Fase 3 (cache).
4. Fase 4 (rate limiting).
5. Fase 5 (documentación).

## Estado
- [x] Fase 1 — implementada
- [x] Fase 2 — implementada
- [x] Fase 3 — implementada
- [x] Fase 4 — implementada
- [x] Fase 5 — documentada en docs/SEGURIDAD-HEADERS.md
PY