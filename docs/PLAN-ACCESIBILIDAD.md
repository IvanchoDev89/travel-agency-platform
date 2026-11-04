# Plan de accesibilidad (WCAG 2.1 AA)

## Objetivo
Asegurar que la plataforma sea usable por personas con discapacidad visual, motora y cognitiva, y cumpla con estándares legales (ADA, EAA).

## Fases

### Fase 1 — Auditoría (1 día)
1. Ejecutar Lighthouse en las páginas clave:
   - Home, búsqueda, ficha de tour, checkout, mi cuenta, dashboard agencia.
2. Ejecutar axe DevTools (extensión Chrome).
3. Probar navegación solo con teclado (Tab, Enter, Escape, flechas).
4. Probar con screen reader (NVDA en Windows, VoiceOver en Mac).
5. Documentar hallazgos en `docs/AUDIT-A11Y.md`.

### Fase 2 — Contraste y tipografía (0.5 día)
- Verificar contraste mínimo 4.5:1 para texto normal, 3:1 para texto grande.
- Ajustar variables CSS (`--tap-neutral-400`, `--tap-neutral-500`) si no cumplen.
- Asegurar que el texto no se pierda en fondos con gradiente (hero).

### Fase 3 — ARIA y semántica (1 día)
- Añadir `aria-label` a todos los iconos y botones sin texto.
- Añadir `role="navigation"`, `role="main"`, `role="complementary"` donde falte.
- Añadir `aria-live="polite"` en toasts, resultados de búsqueda, calendario.
- Añadir `aria-current="page"` en navegación activa.
- Añadir `aria-expanded` en accordions/dropdowns.

### Fase 4 — Navegación por teclado (1 día)
- Calendario: flechas para mover entre días, Enter para seleccionar.
- Galería: flechas para navegar fotos, Escape para cerrar lightbox.
- Widget de reserva: Tab order lógico, Enter para enviar.
- Modal/dropdown: Escape para cerrar, foco vuelve al trigger.
- Skip link (✅ ya implementado).

### Fase 5 — Focus management (0.5 día)
- Al abrir modal, foco va al primer elemento interactivo.
- Al cerrar, foco vuelve al elemento que abrió el modal.
- Focus visible en todos los elementos (✅ ya implementado).

### Fase 6 — Testing (0.5 día)
- Re-ejecutar Lighthouse (objetivo: >90).
- Probar con screen reader.
- Probar con teclado solo.
- Documentar resultados.

## Archivos a tocar
- `public.css` (contraste, focus, reduced motion ✅).
- Templates: `single-tap_tour.php`, `single-service.php`, `service-reviews.php`, `front-page.php`.
- JS: `public.js`, `agency-wizard.js`, calendario.
- `header.php` (skip link ✅).

## Estimación
- Fase 1: 1 día.
- Fase 2: 0.5 día.
- Fase 3: 1 día.
- Fase 4: 1 día.
- Fase 5: 0.5 día.
- Fase 6: 0.5 día.
- **Total: 4.5 días.**

## Criterios de aceptación
- Lighthouse Accessibility > 90 en las 5 páginas clave.
- Navegación completa por teclado.
- Screen reader usable.
- Contraste mínimo 4.5:1.
- `make check` en verde.

## Próximo paso
Ejecutar Lighthouse/axe para obtener baseline.