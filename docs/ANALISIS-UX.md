# Análisis de UX — Travel Agency Platform

## Metodología
Revisión de flujos clave (B2C y B2B) en desktop y mobile, con foco en fricción, claridad y conversión.

---

## 1. Flujo de reserva (B2C)

### Hallazgos
- **Selector de fecha/horario**: el calendario visual es informativo pero no seleccionable directamente en mobile (click funciona pero no es obvio).
- **Precio**: el total se calcula por AJAX pero el desglose (adultos/niños/extras) aparece tarde.
- **Guest checkout**: pide email/teléfono pero no explica por qué (genera desconfianza).
- **Waiver**: se pide después de la reserva, lo que puede causar abandono.
- **Confirmación**: la página de éxito no tiene CTA claro (compartir, invitar amigos).

### Mejoras propuestas
1. Hacer el calendario seleccionable con feedback visual (ya implementado click).
2. Mostrar desglose de precio en tiempo real (adultos × precio + extras).
3. Añadir microcopy explicativo en guest checkout ("Solo para enviarte la confirmación").
4. Mover el waiver antes del pago (pantalla dedicada con progreso).
5. Página de éxito con CTA "Invita a un amigo" y "Ver mi reserva".

---

## 2. Ficha de tour (B2C)

### Hallazgos
- **Hero**: bien, pero falta indicador de "próxima salida" visible.
- **Itinerario**: lista larga sin colapso; en mobile es tedioso.
- **Extras**: checkboxes sin imágenes ni descripción rica.
- **Reviews**: no hay fotos ni filtro por rating.
- **Mapa**: carga Leaflet pero no tiene marcador de "cómo llegar".

### Mejoras propuestas
1. Badge "Próxima salida: fecha · hora" en el hero.
2. Itinerario colapsable por día (accordion).
3. Extras con foto y toggle.
4. Reviews con foto y filtro.
5. Mapa con botón "Cómo llegar" (Google Maps link).

---

## 3. Dashboard de cliente (B2C)

### Hallazgos
- **Resumen**: muestra reservas y stats, pero el panel de referidos ahora está visible (✅).
- **Favoritos**: lista sin imágenes en mobile.
- **Historial de puntos**: tabla básica, falta gráfico de progreso.
- **Notificaciones**: no hay centro de notificaciones.

### Mejoras propuestas
1. Cards con imagen en favoritos.
2. Gráfico de progreso de puntos (barra circular).
3. Centro de notificaciones (reservas, pagos, referidos).
4. Empty states con ilustraciones y CTA.

---

## 4. Dashboard de agencia (B2B)

### Hallazgos
- **Sidebar**: muchos items, puede abrumar.
- **Overview**: KPIs básicos, falta tendencia.
- **Listados**: grid denso, difícil escanear en mobile.
- **Operaciones**: tabla larga, falta filtros rápidos.
- **Referidos (agencia)**: sección separada de referidos (cliente), puede confundir.

### Mejoras propuestas
1. Agrupar sidebar en categorías colapsables.
2. KPIs con sparklines (tendencia 7 días).
3. Cards en lugar de tabla en mobile.
4. Filtros rápidos (chips) en operaciones.
5. Unificar terminología: "Referidos de agencia" vs "Referidos de cliente".

---

## 5. Onboarding (B2B)

### Hallazgos
- **Registro de agencia**: formulario largo sin progreso.
- **Primer tour**: wizard de 5 pasos sin checklist de completitud visible.
- **Aprobación**: el agente no sabe cuánto tarda ni qué falta.

### Mejoras propuestas
1. Stepper visual en registro.
2. Checklist de "primer tour" con progreso.
3. Mensaje claro de tiempo de aprobación (24-48h).
4. Email de bienvenida con pasos.

---

## 6. Accesibilidad (a11y)

### Hallazgos
- Contraste insuficiente en algunos textos secundarios.
- Falta `aria-label` en iconos.
- Navegación por teclado incompleta en calendario.
- Sin skip link (✅ añadido en header).

### Mejoras propuestas
1. Auditoría con Lighthouse/axe.
2. Contraste mínimo 4.5:1.
3. ARIA labels en todos los iconos.
4. Navegación por teclado en calendario y widget.

---

## 7. Mobile

### Hallazgos
- **Widget de reserva**: ocupa toda la pantalla en mobile, difícil scrollear.
- **Calendario**: grid de 7 columnas muy pequeño.
- **Galería**: thumbnails muy pequeños.
- **Tablas**: overflow horizontal sin indicador.

### Mejoras propuestas
1. Widget colapsable en mobile (sticky bottom bar).
2. Calendario con scroll horizontal o vista lista.
3. Galería con swipe.
4. Tablas con scroll indicator y vista card en mobile.

---

## 8. Microinteracciones y feedback

### Hallazgos
- **Loading states**: algunos AJAX no muestran spinner.
- **Éxito/error**: toasts inconsistentes.
- **Validación**: errores inline pero no resumen.
- **Hover**: falta feedback en cards.

### Mejoras propuestas
1. Skeleton loaders en AJAX.
2. Toasts consistentes con iconos.
3. Resumen de errores al enviar formulario.
4. Hover states con elevación y cursor.

---

## 9. Empty states

### Hallazgos
- Muchos empty states son texto plano sin ilustración ni CTA.
- "Aún no tienes referidos" sin link para compartir.

### Mejoras propuestas
1. Ilustraciones SVG en empty states.
2. CTA claro ("Crea tu primer tour", "Comparte tu enlace").

---

## 10. Priorización (impacto vs esfuerzo)

| # | Mejora | Impacto | Esfuerzo | Prioridad |
|---|--------|---------|----------|-----------|
| 1 | Calendario seleccionable + feedback | Alto | Bajo | P0 |
| 2 | Desglose de precio en tiempo real | Alto | Bajo | P0 |
| 3 | Empty states con CTA | Medio | Bajo | P0 |
| 4 | Widget sticky en mobile | Alto | Medio | P1 |
| 5 | Onboarding guiado agencia | Alto | Medio | P1 |
| 6 | Reviews con foto | Medio | Medio | P1 |
| 7 | Centro de notificaciones | Medio | Alto | P2 |
| 8 | PWA | Alto | Alto | P2 |
| 9 | Accesibilidad completa | Medio | Medio | P2 |
| 10 | Gráficos en dashboard | Medio | Medio | P2 |

---

## Próximos pasos
Implementar P0 (calendario, desglose, empty states) en la próxima iteración.
PY