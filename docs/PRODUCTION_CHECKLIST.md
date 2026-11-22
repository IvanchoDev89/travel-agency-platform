# Runbook de producción — Travel Agency Platform

Este documento es la **checklist operativa** para llevar el proyecto a producción con seguridad. Cubre cuatro cosas:

1. La decisión de hosting (quién parchea Linux/Nginx/PHP).
2. El endurecimiento del servidor y de WordPress.
3. La migración (instalación limpia, **nunca clonar la BD local**).
4. La verificación, el cutover y el rollback.

Documentos relacionados: [`INSTALLATION.md`](INSTALLATION.md) (configuración funcional), [`SECURITY.md`](../SECURITY.md) (política de seguridad), `docs/AUDIT-SEGURIDAD.md` (auditoría), y el plan de SEO/migración de contenido en `~/.opencode/plan/seo-migration-plan.md`.

---

## 0. Principios no negociables

| Principio | Por qué |
|---|---|
| **Instalación limpia + migración de contenido** | La BD local está contaminada con datos de tests (~29 posts E2E, ~25 usuarios, 89 bookings, 11k+ filas de `tap_email_log`, transients huérfanos). Clonarla traería basura y datos falsos a producción. |
| **Nada llega a producción sin pasar por staging** | Staging es una copia de configuración, no de datos. Es donde se prueban migración, correos, pagos y actualizaciones. |
| **Todo cambio es reversible** | Antes de cada paso hay un backup y un procedimiento de rollback (sección 7). |
| **Los secretos nunca viven en el repo** | Constantes en `wp-config.php` / variables de entorno / GitHub Secrets. GitHub ya tiene *secret scanning* + *push protection* activos. |
| **La migración SEO preserva URLs** | Migrar contenido sin cambiar URLs cuando sea posible; 301 para las que cambien; conservar titles, descriptions y alt text (ver plan de SEO). |

---

## 1. Decisión de hosting

Esta es la decisión que define quién responde ante un CVE de Linux, Nginx o PHP. Ningún escáner de código cubre esto.

| Modelo | Quién parchea | Coste aprox. | Ventaja | Riesgo |
|---|---|---|---|---|
| **WordPress gestionado** (WP Engine, Kinsta, Cloudways) | El proveedor | $25–40/mes | Backups, staging, WAF y SSL incluidos. Cero mantenimiento de sistema. | Coste, menos control |
| **VPS** (Hetzner, Contabo) | Tú | $5–10/mes | Barato y control total | Si no parcheas, el CVE es tuyo |
| **Raspberry Pi + UPS + solar** (idea propia) | Tú | ~$350 inicial + internet | Sin cuota de hosting, energía renovable | IP dinámica, ISP que bloquea 80/443, sin SLA, tú eres sysadmin |

**Recomendación:** producción en **managed WordPress** o **VPS con actualizaciones automáticas**. Reservar la Raspberry Pi para **staging / backups secundarios / laboratorio**, no como único host de producción de un sitio que debe facturar.

> Nota: el sitio actual (HostGator, ~$20/mes) no es rentable aún. La diferencia de $5–20/mes frente a un managed suele compensarse con el tiempo no dedicado a parchear.

---

## 2. Endurecimiento del servidor

### 2.1 Si es hosting gestionado

Confirmar con el proveedor que incluye, **por defecto y automático**:

- [ ] Parcheos de Linux y Nginx automáticos.
- [ ] PHP en versión soportada (8.2 o 8.3), con opciones de producción (`display_errors = Off`, `expose_php = Off`).
- [ ] Certificado TLS con renovación automática y redirección HTTPS.
- [ ] WAF / firewall de aplicaciones.
- [ ] Backups diarios **fuera del servidor** con restauración comprobada.
- [ ] Staging accesible por separado.

### 2.2 Si es VPS

Ejecutar y dejar documentado:

```bash
# Sistema: actualizaciones de seguridad automáticas
sudo apt update && sudo apt upgrade -y
sudo apt install unattended-upgrades apt-listchanges
sudo dpkg-reconfigure --priority=low unattended-upgrades   # activar

# Firewall: solo lo necesario
sudo ufw default deny incoming
sudo ufw allow 22/tcp     # SSH (mejor: limitar a tu IP)
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable

# SSH: solo llaves, sin root por password
sudo sed -i 's/^PermitRootLogin.*/PermitRootLogin no/' /etc/ssh/sshd_config
sudo sed -i 's/^PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
sudo systemctl reload sshd

# TLS con renovación automática
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d TU_DOMINIO.com -d www.TU_DOMINIO.com
sudo certbot renew --dry-run
```

Nginx / PHP:

- [ ] `server_tokens off;` en la config de Nginx.
- [ ] Redirección HTTP → HTTPS y HSTS (tras confirmar que todo funciona).
- [ ] Cabeceras de seguridad (CSP del plugin está en *report-only*; revisar reportes antes de ponerla `enforce` — ver `SECURITY.md`).
- [ ] PHP 8.2/8.3 soportado, `display_errors = Off`, `expose_php = Off`, OPcache activo.
- [ ] Sin paquetes innecesarios (superficie de ataque mínima).

### 2.3 Backups (crítico, en cualquier modelo)

- [ ] Backup **diario automatizado** de archivos + BD, almacenado **fuera del servidor**.
- [ ] Retención mínima: 7 diarios + 4 semanales.
- [ ] **Prueba de restauración real** en staging antes de migrar. Un backup que no se ha restaurado no existe.
- [ ] Procedimiento de rollback escrito (sección 7) y probado.

---

## 3. Endurecimiento de WordPress

- [ ] WordPress core, tema y plugins **al día**; activar actualizaciones automáticas de seguridad.
- [ ] **2FA obligatoria** en todas las cuentas con `administrator` y `tap_agency_admin`.
- [ ] Revisar usuarios: eliminar cuentas de prueba, sin usuarios `admin`, contraseñas fuertes.
- [ ] XML-RPC desactivado (ya lo hace `TAP_Site_Hardening`), enumeración de `/wp/v2/users` bloqueada, archives de autor redirigidos.
- [ ] `DISALLOW_FILE_EDIT` y `DISALLOW_FILE_MODS` en `wp-config.php` si no se necesita editar desde el admin.
- [ ] Baseline de **WPScan** contra staging antes del cutover:
      `wpscan --url https://staging.TU_DOMINIO.com --enumerate vp,vt,u`
- [ ] Monitorización de uptime y revisión periódica de logs.

---

## 4. Secretos y credenciales

Definir en `wp-config.php` (constantes) o variables de entorno. **Nunca** en el repo ni en la BD como opción cuando exista constante.

| Dato | Dónde | Estado actual |
|---|---|---|
| Dominio real y HTTPS | `siteurl` / `home` + certificado | ⏳ pendiente |
| `admin_email` real y monitorizado | WP → Ajustes Generales | ⏳ pendiente (Launch Check lo marca) |
| `tap_privacy_contact` real | WP → Ajustes → Privacidad | ⏳ pendiente (Launch Check lo marca) |
| `TAP_SMTP_HOST` / `TAP_SMTP_USER` / `TAP_SMTP_PASS` | `wp-config.php` | ⏳ pendiente (Launch Check lo marca) |
| `ONVO_PUBLISHABLE_KEY` / `ONVO_SECRET_KEY` / webhook secret | `wp-config.php` | ⚠️ hay claves de **prueba**; faltan live |
| PayPal live + webhook ID | `wp-config.php` / ajustes | ⚠️ no configurado |
| `TAP_PEXELS_API_KEY` | `wp-config.php` | revisar si se usa |
| `TAP_VAPID_PUBLIC` / `TAP_VAPID_PRIVATE` | `wp-config.php` | revisar si se usa |

Tras cualquier exposición accidental: **rotar la credencial**, no borrar el commit (el historial lo conserva).

---

## 5. Runbook de migración (instalación limpia)

### Fase 0 — Inventario y congelación

- [ ] Inventariar el sitio de origen: dominio, URLs indexadas, contenido (posts, páginas, taxonomías, media), usuarios y plugins.
- [ ] Exportar contenido con `wp export` (WXR) y copiar `/wp-content/uploads/`.
- [ ] Verificar que el export no incluye datos de test.
- [ ] Congelar cambios en el sitio de origen durante la ventana de migración.

### Fase 1 — Staging limpio

- [ ] Crear instalación WordPress **nueva** en el hosting de destino (BD vacía).
- [ ] Subir plugin y tema desde el repo (release/tag `v1.6.6`), no copiar el directorio local.
- [ ] Activar plugin y tema → el instalador crea las **42 tablas** `wp_tap_*` (`class-installer.php`).
- [ ] Configurar permalinks y guardar (rewrite rules).
- [ ] Comprobar que no hay errores de activación ni tablas faltantes.

### Fase 2 — Configuración base

- [ ] Crear las páginas requeridas con sus shortcodes (ver tabla en `INSTALLATION.md § 4.1`):
      `search-results`, `my-bookings`, `booking-detail`, `checkout`, `mi-cuenta`, `armar-mi-viaje`, `dashboard`, `agency-register`, `agency-manage`, `favorites`, `planes`, `privacidad`, `chat`.
- [ ] Configurar moneda, comisiones (incluidos los tramos por volumen), políticas de cancelación y modos de reserva.
- [ ] Configurar `tap_commission_default`, tramos de volumen y resto de ajustes desde *Travel Platform → Ajustes*.
- [ ] Definir constantes de la sección 4.

### Fase 3 — Migración de contenido

- [ ] Importar el WXR (posts, páginas, categorías, etiquetas y media).
- [ ] Subir `/wp-content/uploads/` desde el origen.
- [ ] Revisar que las imágenes tienen alt text y que los créditos CC se conservan.
- [ ] Migrar usuarios reales; **no importar usuarios de prueba**.
- [ ] Migrar datos operativos (agencias, reservas, reseñas) **solo si son reales**. Tablas relevantes: `tap_bookings`, `tap_booking_items`, `tap_agencies`, `tap_reviews`, `tap_daily_pricing`, `tap_pricing_rules`, `tap_referrals`, `tap_loyalty_points`, `tap_email_templates`.
- [ ] **No migrar** tablas de telemetría/log de test: `tap_email_log`, `tap_affiliate_clicks`, etc.
- [ ] Recalcular/verificar importes con la base acordada: `payment_status='paid'` y `status NOT IN ('cancelled','refunded')`.

### Fase 4 — SEO y redirects

- [ ] Aplicar la estrategia del plan de SEO: preservar URLs de blog/páginas/destinos; 301 para las que cambien (p. ej. `/trip/{slug}/` → `/tour/{slug}/`).
- [ ] Conservar meta titles, descriptions y alt text.
- [ ] Verificar Yoast / TAP_SEO instalado y funcionando (el guard `WPSEO_VERSION` ya está implementado).
- [ ] No activar Polylang (está instalado pero inactivo).
- [ ] Enviar sitemap actualizado a Search Console.

### Fase 5 — Verificación en staging

- [ ] **Launch Check** en 0 issues (URLs reales, correo legal, `admin_email`, HTTPS, SMTP, entorno).
- [ ] Todos los chequeos verdes: `lint.sh`, PHPCS, i18n, ajax-nonce, includes, `composer audit`.
- [ ] E2E (**solo en staging desechable**, nunca en producción): 61/61 suites.
- [ ] Probar flujo completo de reserva, correos transaccionales (llegan y no caen en spam) y webhooks.
- [ ] Probar pagos en modo **test** y luego el paso a **live** con montos mínimos.
- [ ] Probar panel de agencia: finanzas, promos con admin vs empleado, REST público.
- [ ] Revisar logs y WPScan sin hallazgos críticos.

### Fase 6 — Cutover

- [ ] Bajar el TTL de DNS unas horas antes.
- [ ] Backup final del staging verificado.
- [ ] Cambiar DNS al destino y forzar HTTPS.
- [ ] Comprobar desde fuera: home, páginas clave, checkout, correos, pagos, REST.
- [ ] Monitorizar uptime, logs y cola de correos las primeras 72 h.
- [ ] Dejar el sitio anterior intacto (sin apagarlo) durante el periodo de rollback.

---

## 6. Criterios de aceptación (sign-off)

No se considera "listo para producción" hasta que **todo** esto sea verde:

- [ ] Launch Check: 0 issues.
- [ ] CI y Security workflows en verde en `master`.
- [ ] 61/61 suites E2E en staging.
- [ ] Backups restaurados con éxito al menos una vez.
- [ ] 2FA activo en todos los admins.
- [ ] Secretos rotados si alguna vez estuvieron expuestos.
- [ ] HTTPS forzado y correos entregables.
- [ ] Rollback ensayado.

---

## 7. Rollback

Si algo falla tras el cutover:

1. No borrar ni modificar el sitio de origen hasta pasado el periodo de prueba.
2. Restaurar el **backup final** en el destino (o reapuntar DNS al origen si el problema es de infraestructura).
3. Bajar el TTL de DNS antes del cambio para que el revert sea rápido.
4. Documentar la causa, abrir un issue y corregir en staging antes de reintentar.
5. Nunca "arreglar en producción" sin pasar por staging.

---

## 8. Cadencia posterior

| Frecuencia | Acción |
|---|---|
| Diaria | Revisar backups y monitorización de uptime |
| Semanal | Revisar alertas de Dependabot y actualizar dev tools/Actions |
| Mensual | Aplicar parcheos de sistema, WPScan, revisar logs y rotar credenciales sensibles |
| Trimestral | Ensayar restauración completa y revisar este runbook |
