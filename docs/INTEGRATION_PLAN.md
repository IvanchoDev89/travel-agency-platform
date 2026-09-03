# Plan de integración en sitio WordPress existente (theme Rara Themes)

> Contexto: quieres implantar `travel-agency-platform` en una página WordPress que ya
> corre con un theme de **Rara Themes**. Este documento recoge el análisis de
> compatibilidad y el plan paso a paso, sin necesidad de tocar código ahora mismo.

---

## 1. Cómo está construido el plugin (clave para la decisión)

El plugin **delega el renderizado público en el theme compañero** `travel-agency-theme`
y también expone **shortcodes** autocontenidos. Hay dos modos de mostrar contenido:

**A) Mediante templates del theme (experiencia completa):**
- `single-tap_accommodation.php` → una **plantilla completamente a medida** (galería con
  lightbox, calendario de disponibilidad, selección de habitaciones, widget de reserva
  lateral con JS propio, formulario de `tap_booking_create`, etc.).
- `single.php` → para el resto de servicios usa el shortcode `[tap_service_detail id="X"]`.
- `archive-*.php`, `search.php`, `front-page.php`, `template-parts/*`.

**B) Mediante shortcodes (mínimo impacto):**
| Shortcode | Para qué |
|---|---|
| `[tap_search]` | Formulario de búsqueda con autocompletado |
| `[tap_search_results]` | Página de resultados con filtros (tipo, ubicación, precio, orden) |
| `[tap_service_detail id="X"]` | Página de detalle de un servicio |
| `[tap_agency_detail id="X"]` | Perfil de agencia |
| `[tap_booking_form ...]` | Formulario de reserva |
| `[tap_agency_services agency="X"]` | Servicios de una agencia |

El theme Rara Themes **no sabe nada** de estos templates/hooks, por lo que por sí solo no
mostrará las páginas de detalle/archivo de los posts del plugin de forma óptima.

---

## 2. Decisión de implantación

Dado que quieres **mantener tu página y avanzar rápido**, se recomienda el camino híbrido:

1. **No** reemplazar por completo el theme Rara Themes.
2. Usar la **página Planificar ahora / construir la plataforma** para no romper lo que ya
   funciona.
3. Integrar el plugin mediante **shortcodes + ajustes mínimos de single/archive**
   mediante un **child theme** del theme Rara Themes (o añadiendo templates de respaldo).

> Recomendado: un **child theme** donde solo sobrescribas `single-*.php` y `archive-*.php`
> para los post types del plugin, y dejes el resto del diseño Rara Themes intacto.

---

## 3. Requisitos del entorno

Antes de instalar, verifica en la página de destino:

- **WordPress ≥ 7.1** (el plugin usa los sitemaps nativos `wp_sitemaps_*`).
- **PHP ≥ 7.4** (verificar; si soporta 8.0+ mejor).
- **Permalinks**: estructura con nombres bonitos (no "simple"), para que `sitemap.xml`,
  `/{tipo}/` y los archivos funcionen.
- **Tema**: cualquier theme sirve para el *backend*/admin; solo la parte pública necesita
  integración (ver punto 4).

---

## 4. Pasos de integración (child theme)

Crear un child theme del theme Rara Themes, por ejemplo `rara-travel`:

```
rara-travel/
├── style.css          (Template: <rara-theme-folder>)
├── functions.php      (encolar estilos/JS del plugin, declarar soporte)
├── single-tap_accommodation.php
├── single-tap_tour.php
├── single-tap_transport.php
├── ... (resto de tipos)
├── archive-tap_tour.php
├── ... (archivos)
└── search.php
```

En `functions.php` del child theme:

1. Encolar los assets del plugin y del theme compañero si hiciera falta.
2. Para cada tipo de servicio, un `single-tap_*.php` que haga
   `do_shortcode('[tap_service_detail id="'.get_the_ID().'"]')` (igual que hace
   `single.php` del theme compañero).
3. `single-tap_agency.php` → `do_shortcode('[tap_agency_detail id="..."]')`.

Con eso obtienes: breadcrumbs visibles, rich results, rating, precio, agencia y el
formulario de reserva enlazado, **todo** el detalle ya maquetado por el plugin.

> Opción rápida aún más simple: **copiar** los archivos `single-*.php`, `archive-*.php` y
> `search.php` del theme compañero a tu child theme. Son plantillas completas que ya
> saben renderizar los datos. La única dependencia es que el plugin esté activo.

---

## 5. Qué NO depende del theme (viene gratis)

Esto funciona **sin tocar templates** una vez activado el plugin:

- **SEO técnico**: sitemap extendido, robots.txt, canonical, meta description, OG,
  Twitter Cards. (Los renderiza el plugin en `wp_head` / `wp_robots`.)
- **Rich results (JSON-LD)**: BreadcrumbList, FAQPage, ContactPoint, TouristTrip, Hotel,
  TravelAgency.
- **Búsqueda con filtros**: la página `/search-results` con `[tap_search_results]`.
- **Toda la lógica de negocio**: reservas, pagos (PayPal en `565f344`), promociones,
  planes, comisiones, analíticas, panel de agencia.

---

## 6. Riesgos y mitigaciones

| Riesgo | Mitigación |
|---|---|
| Rara Themes no renderiza singles/archives de CPT | Child theme con los `single-*`/`archive-*` del plugin |
| Conflicto de estilos | Usar clases `tap-*` (prefijadas) y encolar CSS solo en CPT / páginas del plugin |
| Conflicto de hooks o escaner de seguridad del theme | Probar en staging; revisar `functions.php` del theme |
| Versión antigua de WP/PHP | Actualizar antes (requisito point 3) |
| Permalinks "simples" rompen sitemap/archivos | Cambiar a estructura con nombres bonitos y re-guardar |

---

## 7. Pasos inmediatos sugeridos (cuando quieras implantar)

1. Hacer **backup** de la página de destino (DB + archivos).
2. **Staging**: clonar el sitio y activar el child theme + plugin ahí primero.
3. Activar el plugin y verificar admin + SEO tags en staging.
4. Crear/copiar templates de CPT al child theme.
5. Probar flujo completo (crear listing → buscar → reservar → checkout).
6. Migrar a producción con datos reales.

---

## 8. Pendiente a largo plazo

- Confirmar **tema Rara Themes concreto** (nombre exacto / versión) para comprobar
  interacciones específicas.
- Decidir si, además del child theme, se adoptan ciertas partes del `travel-agency-theme`
  (header/footer/estilos) para unificar la experiencia.
- Configurar **credenciales PayPal** (sandbox/live) para el flujo de pago real.
