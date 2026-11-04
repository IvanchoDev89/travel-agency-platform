# Plan: Notificaciones push (PWA)

## Objetivo
Enviar notificaciones push a usuarios (clientes y agencias) para aumentar engagement y conversión.

## Casos de uso
1. **Cliente**: reserva confirmada, pago recibido, recordatorio de tour (24h antes), review solicitada.
2. **Agencia**: nueva reserva, nueva solicitud, mensaje de cliente, pago recibido.
3. **Plataforma**: alertas de seguridad, mantenimiento.

## Implementación existente
- Módulo `TAP_Push` ya existe (Web Push API).
- Tabla `tap_push_subscriptions`.
- REST endpoint para suscribir.
- Shortcode `[tap_push_subscribe]`.

## Tareas

### 1. Integración con eventos
- Hook `tap_booking_created` → notificar agencia.
- Hook `tap_payment_completed` → notificar cliente + agencia.
- Hook `tap_booking_status_updated` → notificar cliente.
- Cron diario → recordatorio 24h antes del tour.
- Hook `tap_chat_message_sent` → notificar receptor.

### 2. UI de suscripción
- Banner en dashboard: "Activa notificaciones para no perder reservas".
- Toggle en perfil de usuario.
- Configuración de qué notificaciones recibir (granular).

### 3. Service worker
- Manejar eventos `push` en sw.js.
- Mostrar notificación con icono y acciones.
- Click → abrir URL correspondiente.

### 4. Backend (VAPID keys)
- Generar VAPID keys (una vez).
- Guardar en wp_options.
- Endpoint de suscripción (ya existe).

### 5. Testing
- Probar en Chrome/Firefox/Safari.
- Verificar que push funciona en PWA instalada.

## Estimación
- Integración con eventos: 1 día.
- UI de suscripción: 0.5 día.
- Service worker: 0.5 día.
- VAPID + backend: 0.5 día.
- Testing: 1 día.
- **Total: 3.5 días.**

## Criterios de aceptación
- Usuario puede suscribirse desde el dashboard.
- Recibe notificación al confirmar reserva.
- Agencia recibe notificación de nueva reserva.
- Recordatorio 24h antes del tour.
- Funciona en Chrome y Firefox (Safari con limitaciones).

## Riesgos
- Push requiere HTTPS (en Local no funciona).
- Safari tiene soporte limitado.
- Permisos del navegador (usuario puede bloquear).

## Próximo paso
Generar VAPID keys y configurar el módulo push.