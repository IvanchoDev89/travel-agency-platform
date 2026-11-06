# Plan maestro — Perfección de la plataforma

> **Estado:** ✅ Completado (2026-11-05)

## Visión
Convertir Travel Agency Platform en un producto SaaS-grade: seguro, rápido, accesible, internacional y rentable.

## Fases (orden de ejecución)

### Fase A — Limpieza y estabilización (1 semana)
1. **Limpiar duplicados de Polylang** (posts/páginas duplicados).
2. **Desactivar Polylang** (usar sistema nativo de i18n).
3. **Unificar configuración** en tabs (General, Pagos, Automations, Seguridad, Referidos).
4. **Validación de campos** en settings.
5. **Import/export** de configuración.

### Fase B — Seguridad avanzada (1 semana)
1. **CSP en producción** (activar enforce en HTTPS).
2. **HSTS** (activar en producción).
3. **Rate limiting en login** (brute force protection).
4. **2FA** para admins y agencias.
5. **Fingerprinting** para referidos.
6. **Aprobación manual** de puntos altos.

### Fase C — UX y accesibilidad (1.5 semanas)
1. **Auditoría Lighthouse/axe** en 5 páginas clave.
2. **ARIA labels** en todos los iconos y botones.
3. **Contraste** mínimo 4.5:1.
4. **Navegación por teclado** en calendario, galería, modales.
5. **Focus management** en modales/dropdowns.
6. **Empty states** con ilustraciones (ya parcial).
7. **Skeleton loaders** en AJAX.

### Fase D — Rendimiento (1 semana)
1. **CSS splitting** (por ruta).
2. **Lazy loading** de imágenes (ya parcial).
3. **WebP/AVIF** para imágenes.
4. **Cache de páginas** (WP Rocket / LiteSpeed).
5. **CDN** para assets.
6. **Índices DB** (✅ implementado).

### Fase E — Internacionalización (2 semanas)
1. **Traducir páginas clave** (Home, Tours, Mi cuenta, Términos).
2. **Duplicar tours populares** al inglés.
3. **Emails en idioma del usuario** (guardar locale en booking).
4. **Moneda por idioma** (✅ implementado).
5. **Hreflang tags** (✅ implementado).

### Fase F — Monetización avanzada (2 semanas)
1. **Featured listings** (✅ badge + ordenamiento).
2. **Upsells en checkout** (✅ extras + privado).
3. **Comisiones escalonadas** (✅ implementado).
4. **Suscripciones mejoradas** (add-ons, prorrateo).
5. **Dynamic pricing** (yield por demanda).
6. **Afiliados externos** (piloto).

### Fase G — Analytics y BI (1.5 semanas)
1. **Dashboard de plataforma** (GMV, agencias activas, conversión).
2. **Cohortes** (✅ implementado).
3. **Alertas automáticas** (✅ implementado).
4. **UTM tracking** (✅ implementado).
5. **Exportación PDF** (✅ imprimible).

### Fase H — Onboarding y soporte (1 semana)
1. **Onboarding guiado** (checklist ✅, email drip ✅, tour guiado ✅).
2. **Videos tutoriales** embebidos.
3. **Base de conocimiento** (FAQ).
4. **Sistema de tickets**.
5. **SLA de respuesta**.

### Fase I — PWA y mobile (1 semana)
1. **Manifest + SW** (✅ implementado).
2. **Offline fallback** (✅ implementado).
3. **Install prompt** (✅ implementado).
4. **Push notifications** (✅ implementado).
5. **Background sync** para reservas offline.

### Fase J — Testing y documentación (1 semana)
1. **Suite E2E** (✅ 0 fallos).
2. **Tests de seguridad** (ampliar a 30+ casos).
3. **Tests de referidos** (✅ implementado).
4. **Documentación** (guías de usuario, admin, developer).
5. **CI/CD** (GitHub Actions).

---

## KPIs de éxito
- Lighthouse Performance > 90, Accessibility > 90, SEO > 90.
- 0 vulnerabilidades críticas/altas.
- Suite E2E: 0 fallos.
- Conversión visita → reserva > 3%.
- Retención de agencias > 80% a 6 meses.

## Estimación total
- Fase A: 1 sem
- Fase B: 1 sem
- Fase C: 1.5 sem
- Fase D: 1 sem
- Fase E: 2 sem
- Fase F: 2 sem
- Fase G: 1.5 sem
- Fase H: 1 sem
- Fase I: 1 sem
- Fase J: 1 sem
- **Total: 13-14 semanas**

## Próximo paso recomendado
Ejecutar **Fase A** (limpieza y estabilización) para dejar la base limpia antes de añadir más features.

¿Quieres que empiece con la **Fase A**?