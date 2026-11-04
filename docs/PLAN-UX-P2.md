# Plan de implementación — UX P2 (avanzado)

## Objetivo
Llevar la experiencia a nivel profesional: PWA, accesibilidad, onboarding avanzado y microinteracciones.

## Tareas

### 1. PWA (Progressive Web App)
**Archivos:** `manifest.json`, `service-worker.js`, `functions.php`
- Manifest con nombre, iconos, theme_color, start_url.
- Service worker con cache de assets estáticos y páginas vistas.
- Offline fallback page.
- Push notifications (reusar módulo push).
- "Añadir a pantalla de inicio" prompt.

**Riesgo:** alto (service worker puede cachear mal). Requiere pruebas.

### 2. Accesibilidad (a11y) completa
**Archivos:** templates, CSS, JS
- Auditoría con Lighthouse/axe.
- Contraste mínimo 4.5:1 en todos los textos.
- `aria-label` en todos los iconos y botones.
- Navegación por teclado en calendario, widget, galería.
- Focus visible en todos los elementos interactivos.
- Skip link (✅ ya implementado).
- Screen reader announcements para cambios dinámicos (aria-live).

**Riesgo:** medio. Requiere testing manual con screen reader.

### 3. Onboarding avanzado
**Archivos:** `class-front-dash.php`, emails
- Tour guiado interactivo (tooltips paso a paso) en primer login.
- Video tutoriales embebidos (YouTube) en dashboard.
- Checklist persistente (guardar progreso en user meta).
- Email drip: día 1, 3, 7 con tips.

**Riesgo:** medio. Requiere coordinación con emails.

### 4. Microinteracciones y feedback
**Archivos:** `public.css`, `public.js`, templates
- Skeleton loaders en AJAX (calendario, reviews, búsqueda).
- Toasts consistentes con iconos y auto-dismiss.
- Resumen de errores al enviar formularios.
- Hover states con elevación y cursor pointer.
- Animaciones de entrada (fade-in, slide-up) en cards.
- Pull-to-refresh en mobile.

**Riesgo:** bajo-medio.

### 5. Gráficos en dashboard
**Archivos:** `class-front-dash.php`, `public.js`
- Sparklines en KPIs (tendencia 7 días).
- Gráfico de reservas por mes (Chart.js o canvas nativo).
- Gráfico de ingresos por servicio.

**Riesgo:** medio (dependencia Chart.js).

### 6. Empty states con ilustraciones (✅ P1)
- Completado.

### 7. Widget sticky en mobile (✅ P1)
- Completado.

## Estimación
- Tarea 1 (PWA): 3 días.
- Tarea 2 (a11y): 2 días.
- Tarea 3 (onboarding): 2 días.
- Tarea 4 (microinteracciones): 2 días.
- Tarea 5 (gráficos): 2 días.
- **Total: 11 días.**

## Criterios de aceptación
- Lighthouse PWA score > 80.
- Lighthouse Accessibility score > 90.
- Onboarding completado por 50% de nuevas agencias.
- Toasts consistentes en todos los flujos.
- Gráficos renderizados en dashboard.

## Orden de ejecución
4 → 5 → 2 → 3 → 1

## Riesgos
- PWA puede cachear contenido dinámico incorrectamente.
- Accesibilidad requiere testing manual extenso.
- Chart.js añade peso al bundle.

## Próximo paso
Empezar con microinteracciones (tarea 4) por bajo riesgo y alto impacto visual.
PY