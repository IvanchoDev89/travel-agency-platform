# Plan: Internacionalización (i18n / multi-idioma)

## Objetivo
Permitir que la plataforma se use en múltiples idiomas (ES, EN, FR, DE, etc.) para expandir el mercado.

## Estado actual
- Text domain: `travel-agency-platform`.
- Catálogos: es_ES, en_US (compilados).
- `TAP_Localization` maneja locale.
- `TAP_Translations` (chatbot?) maneja selector de idioma.
- Strings en PHP usan `__()`.
- Strings en JS usan `tapI18n` (localizado).

## Tareas

### 1. Elección de solución (0.5 día)
- **WPML** (comercial, robusto) o **Polylang** (gratis, suficiente).
- Recomendación: Polylang para empezar (gratis, buena integración con CPTs y taxonomías).

### 2. Configuración de Polylang (1 día)
- Instalar y activar.
- Configurar idiomas (ES, EN, FR, DE).
- Configurar CPTs (tap_tour, tap_accommodation, etc.) como traducibles.
- Configurar taxonomías (tap_location, tap_service_cat, etc.) como traducibles.

### 3. Traducción de contenido (2-3 días)
- Traducir páginas clave (home, mi cuenta, términos).
- Traducir tours/alojamientos de ejemplo.
- Traducir taxonomías (ubicaciones, categorías).

### 4. Strings en PHP (1 día)
- Verificar que todos los strings usan `__()` (ya hecho en gran parte).
- Registrar strings en Polylang (String Translations).
- Traducir via Polylang UI.

### 5. Strings en JS (1 día)
- Actualizar `tapI18n` para incluir todos los strings de JS.
- Usar `wp_localize_script` con strings traducidos.
- Verificar que el wizard, calendario y chatbot usan strings traducidos.

### 6. Moneda y formato (0.5 día)
- Ya existe `TAP_Currency` con multi-currency.
- Configurar monedas por idioma (ES → EUR/USD, EN → USD/GBP).
- Formatos de fecha por locale.

### 7. Emails (0.5 día)
- Traducir templates de email.
- Enviar en el idioma del usuario (guardar `locale` en booking).

### 8. SEO (0.5 día)
- Hreflang tags.
- Sitemap por idioma.
- Meta tags traducidos.

### 9. Testing (1 día)
- Verificar que todo funciona en cada idioma.
- Verificar que no hay strings hardcodeados.
- Verificar que los formularios envían en el idioma correcto.

## Estimación total: 8-10 días

## Riesgos
- Polylang puede tener conflictos con CPTs personalizados.
- La traducción de contenido es manual y laboriosa.
- Algunos strings en JS pueden quedar sin traducir.

## Mitigaciones
- Usar Polylang Pro para sincronización automática.
- Crear un glosario de términos para traductores.
- Testing exhaustivo en cada idioma.

## Próximo paso
Instalar Polylang en local y configurar idiomas + CPTs.