# Plan: Editor de plantillas de email

## Objetivo
Permitir gestionar las plantillas de email desde el admin sin tocar código.

## Tablas
- `tap_email_templates`: id, template_key, subject, body, locale, is_active, created_at, updated_at.
- `tap_email_log`: id, template_key, recipient, subject, status, sent_at.

## Funcionalidades
1. CRUD de plantillas (admin).
2. Variables dinámicas: {{booking_code}}, {{guest_name}}, {{total}}, {{service_name}}, {{agency_name}}, {{date}}.
3. Preview con datos de ejemplo.
4. Fallback a plantillas hardcodeadas si no hay plantilla en BD.
5. Log de envíos.

## Archivos
- `class-email-templates.php`: CRUD + render.
- `class-emails-send.php`: integrar lookup + log.
- `class-installer.php`: tablas.

## Estimación: 1-2 semanas.
PY