# Plan detallado — Fase 0: Estabilización y seguridad base

> **Estado:** ✅ Completado (2026-11-05)

## Objetivo
Cerrar brechas de seguridad, estabilizar el código y dejar la plataforma lista para producción y crecimiento.

## Alcance
- Auditoría de todos los handlers AJAX, admin-post, uploads y SQL.
- Hardening de headers y rate limiting.
- Ampliación de suite de seguridad.
- Documentación de hallazgos.

## Entregables
1. Reporte de auditoría (`docs/AUDIT-SEGURIDAD.md`).
2. Fixes aplicados con tests.
3. `make check` en verde.
4. Headers de seguridad configurados.

---

## 1. Inventario de superficie de ataque

### Handlers AJAX (49 registrados)
Archivos:
- `class-ajax.php`
- `class-ajax-listing.php`
- `class-ajax-public.php`
- `class-ajax-dash.php`
- `class-ajax-commerce.php`

Checklist por handler:
- [ ] `check_ajax_referer` presente.
- [ ] Capability check (`current_user_can`).
- [ ] Ownership check (`agency_owns_listing`, `agency_owns_booking`).
- [ ] Sanitización de inputs.
- [ ] Escape de outputs.
- [ ] Prepared statements para DB.

### Admin-post handlers
- `tap_sign_waiver` (TAP_Waiver::handle_sign) ✅ nonce.
- `tap_export_leads`, `tap_export_bookings`, `tap_export_finances`.
- `tap_email_campaign_*`.
- `tap_export_referrals` (nuevo) ✅ nonce.

### Uploads
- `TAP_Agency_Media::agency_upload_image`.
- `sideload_image`.
- Verificar: MIME real, tamaño, ownership, path traversal.

### SQL
- Buscar interpolación de variables en SQL.
- Buscar `LIKE` sin `$wpdb->esc_like`.
- Buscar `ORDER BY` con input no validado.

---

## 2. Tareas específicas

### 2.1 Revisar IDOR en bookings
- `TAP_Ajax::update_booking_status`: ¿verifica que el booking pertenece a la agencia del usuario?
- `TAP_Front_Dash::bookings_csv_download`: ¿filtra por agency?
- `TAP_Ajax::cancel_booking`: ¿verifica ownership?

### 2.2 Revisar upload de imágenes
- MIME real con `wp_check_filetype_and_ext`.
- Tamaño máximo.
- Nombre de archivo sanitizado.
- Directorio de destino correcto.
- No path traversal.

### 2.3 Revisar XSS
- `echo` de metas en templates sin `esc_html`.
- `wp_kses_post` solo donde se permite HTML.
- Shortcodes que imprimen atributos sin `esc_attr`.

### 2.4 Revisar SQL injection
- Buscar `$wpdb->query("... {$var} ...")`.
- Buscar `$wpdb->get_results("... {$var}")`.
- Usar `prepare` siempre.

### 2.5 Rate limiting
- Login: ya tiene? Verificar.
- Booking creation: añadir rate limit por IP (ej. 10/min).
- AJAX público (search, suggestions): rate limit.
- Referral clicks: ✅ implementado.

### 2.6 Headers de seguridad
- Configurar en `.htaccess` o nginx:
  - `X-Frame-Options: SAMEORIGIN`
  - `X-Content-Type-Options: nosniff`
  - `Referrer-Policy: strict-origin-when-cross-origin`
  - `Permissions-Policy`.
- CSP: definir política mínima.

### 2.7 CSRF en admin-post
- Verificar todos los `admin_post_*` handlers tienen `check_admin_referer`.

---

## 3. Herramientas
- `make audit-security` (phpcs security).
- `make ajax-nonce-check`.
- `make includes-check`.
- `make analyse` (PHPStan).
- `make test`.
- Manual: OWASP ZAP (opcional).

---

## 4. Orden de ejecución
1. Ejecutar `make audit-security` y `ajax-nonce-check` → baseline.
2. Revisar handlers uno por uno (IDOR).
3. Revisar uploads.
4. Revisar SQL.
5. Revisar XSS en templates.
6. Añadir rate limiting.
7. Configurar headers.
8. Ampliar suite de seguridad.
9. Documentar hallazgos y fixes.

---

## 5. Criterios de aceptación
- `make check` en verde.
- `audit-security` sin errores críticos.
- Suite de seguridad con >= 20 casos.
- Documento de auditoría creado.
- Headers configurados y verificados.

---

## 6. Estimación
- Auditoría: 3-4 días.
- Fixes: 3-5 días.
- Tests: 2-3 días.
- Documentación: 1 día.
- **Total: 1.5-2 semanas.**

## Próximo paso
Ejecutar `make audit-security` para obtener baseline.