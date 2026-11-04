# Plan: UI de suscripción push + recordatorio 24h

## Objetivo
Permitir que usuarios y agencias activen notificaciones push y reciban recordatorios automáticos antes de sus tours.

## Tareas

### 1. UI de suscripción (0.5 día)
- Banner en dashboard: "Activa notificaciones para no perder reservas".
- Toggle en perfil de usuario.
- Guardar preferencias (qué notificaciones recibir).
- Botón "Activar" que solicita permiso del navegador y suscribe.

### 2. Service worker update (0.5 día)
- Manejar `pushsubscriptionchange` (renovar suscripción).
- Guardar endpoint actualizado.

### 3. Recordatorio 24h (1 día)
- Cron diario `tap_push_reminders`.
- Buscar bookings con check_in = mañana y status confirmed/pending.
- Enviar push a client_id y agency_user.
- Evitar duplicados (meta `_tap_reminder_sent`).

### 4. Preferencias granulares (0.5 día)
- Opciones: nuevas reservas, pagos, mensajes, recordatorios.
- Guardar en user meta `_tap_push_prefs`.
- Respetar en `TAP_Push::send` (filtrar por preferencia).

### 5. Testing (0.5 día)
- Probar en Chrome/Firefox.
- Verificar permisos y fallback a email.

## Estimación total: 3 días

## Criterios de aceptación
- Usuario puede activar/desactivar push desde su perfil.
- Recordatorio se envía 24h antes del tour.
- Preferencias respetadas.
- PHPStan, i18n, tests en verde.

## Próximo paso
Implementar UI de suscripción y cron de recordatorios.