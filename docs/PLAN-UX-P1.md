# Plan de implementación — UX P1 (medio esfuerzo, alto impacto)

## Objetivo
Mejorar la experiencia mobile y el onboarding de agencias, aumentando conversión y retención.

## Tareas

### 1. Widget de reserva sticky en mobile
**Archivo:** `single-tap_tour.php`, `public.css`
- Convertir el widget en barra inferior fija en mobile (<768px).
- Botón "Reservar" que expande el widget completo.
- Mostrar precio mínimo y próxima salida en la barra.
- Evitar solapamiento con el footer.

**Riesgo:** medio (CSS + JS). Requiere pruebas en mobile.

### 2. Onboarding guiado para agencias
**Archivos:** `class-front-dash.php`, `class-listing-editor.php`
- Stepper visual en registro (3 pasos: datos, agencia, confirmación).
- Checklist "Primer tour" en dashboard de agencia:
  - [ ] Crear primer tour
  - [ ] Añadir fotos
  - [ ] Configurar precios
  - [ ] Configurar disponibilidad
  - [ ] Publicar
- Mensaje de tiempo de aprobación (24-48h) visible.
- Email de bienvenida con pasos (usar `class-emails-send.php`).

**Riesgo:** medio. Requiere coordinación con emails.

### 3. Reviews con foto
**Archivos:** `class-reviews.php`, `service-reviews.php` partial, `public.css`
- Permitir subir 1-3 fotos en el review.
- Mostrar galería de fotos en reviews.
- Filtro por rating (1-5 estrellas).
- Lightbox para fotos de reviews.

**Riesgo:** medio (uploads, storage).

### 4. Centro de notificaciones
**Archivos:** `class-front-dash-client.php`, `class-push.php`
- Campana en el header del dashboard.
- Lista de notificaciones (reservas, pagos, referidos, reviews).
- Marcar como leído.
- Push notifications (reusar módulo push).

**Riesgo:** alto (requiere push setup).

### 5. Empty states con ilustraciones
**Archivos:** templates, CSS
- Añadir SVG inline en empty states.
- CTA destacado.

**Riesgo:** bajo.

## Estimación
- Tarea 1: 1 día.
- Tarea 2: 2 días.
- Tarea 3: 2 días.
- Tarea 4: 3 días.
- Tarea 5: 0.5 día.
- **Total: 8.5 días.**

## Criterios de aceptación
- Widget sticky funcional en mobile (probado en 375px).
- Onboarding con checklist visible.
- Reviews con foto (upload + display).
- Empty states con ilustración.
- PHPStan, i18n, tests en verde.

## Orden de ejecución
1 → 5 → 2 → 3 → 4
PY