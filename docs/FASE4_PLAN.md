# FASE 4 — Atención por chatbot & moderación de contenido

> Estado del documento: **COMPLETADA** (lotes A, B y C, batería 20/20 en travel e ivanchodev, 0 residuos, commits por lote: `2d3c9c2`, `0cdc47e`, y el commit de cierre de C).
> Anexa a [`FASE1_PLAN.md`](FASE1_PLAN.md) (roadmap: Fase 3 multilingüe completada).

## 0. Resumen ejecutivo

Añadir una capa de **atención al cliente** (chatbot de soporte) y de **moderación**
automática de contenido generado por usuarios (reseñas y leads), sin depender de servicios
externos de IA: motor determinista y testeable, con i18n ES/EN igual que el resto del
plugin. Queda preparada la extensión a un proveedor LLM externo vía filtro.

## 1. Lotes

### A — Motor de chatbot (core, sin UI)
- `TAP_Chatbot::answer($message)` → intents + reply traducible + enlaces reales.
- Normalización (acentos, plurales, stopwords ES/EN) y scoring de intents.
- Intents: greeting, booking, voucher/checkout, papeo (payment), cancelación, búsqueda,
  registro de agencia, favoritos, contacto/leads, precios/moneda, disponibilidad,
  recomendaciones del catálogo real, y fallback con contacto + FAQ.
- Respuestas como msgids EN del dominio plugin (ES vía es_ES.mo). Sin PII en logs; tabla
  `tap_chat_events` con agregados (intent, lang, fecha).
- Endpoint AJAX `tap_chatbot_message` (nonce público) + rate limit básico por IP.
- Suite `suite_chatbot` (intents ES/EN, fallback, enlaces, nonce, rate limit).

### B — Moderación automática
- `TAP_Moderation::assess($text, $kind)` → riesgo `none|review|block` con razones.
- Reglas: PII leak (email/teléfonos), spam (enlaces múltiples, patrones de spam),
  abuso (léxico ES/EN).
- Hooks: reseñas (`review` REST) y messages de leads (`tap_lead_submit`):
  - `block` → no se publica/notifica; reseña queda anulada, lead rechazado.
  - `review` → queda pendiente (`is_approved=0`) con `mod_status='pending_review'` y razón;
    lead se guarda `held` sin notificar a la agencia.
- Columnas nuevas (`mod_status`, `mod_reason`) en `tap_reviews` y `tap_leads` vía `migrate()`.
- Panel admin "Moderación" bajo el menú del plugin (lista reseñas/leads señalados con
  columna de estado + accesos a aprobar/rechazar reutilizando el flujo existente).
- Suite `suite_moderation`. Regresión: reseñas limpias siguen el flujo actual.

### C — Widget público, i18n y cierre
- Shortcode `[tap_chatbot]` (panel plegable auto-contenido; no flotante), estilos del widget en `public.css`
  (mismo lenguaje visual del audit) y lógica en `public.js`, usando `tapI18n`.
- Todas las cadenas del bot/widget/moderación al catálogo (ES→EN y EN→ES) con la tubería
  `dictionaries.py` + `gen_mo.py` + `msgfmt --check`.
- Docs: FASE4_PLAN, DEVELOPER_GUIDE (filtro `tap_chatbot_provider`), USER_GUIDE, CHANGELOG.
- Batería ampliada (20 suites) verde en travel e ivanchodev, 0 residuos, commit por lote.

## 2. Criterios de aceptación

1. `suite_chatbot` cubre: reserva, búsqueda, cancelación, voucher/checkout, registro de
   agencia, favoritos y fallback, en ES y EN.
2. `suite_moderation` cubre: reseña con email → pendiente con razón; lead con spam → held
   sin notificación; contenidos limpios no se marcan.
3. `[tap_chatbot]` renderiza widget; AJAX responde con intent+reply+enlaces; rate limit y
   nonce funcionan; sin errores PHP en front.
4. Batería completa (20 suites) verde en **travel** e **ivanchodev**, 0 residuos.
5. Toda la UI del bot en ES y EN (catálogos regenerados y verificados).

## 3. Riesgos y límites

- **No es un LLM**: respuestas guioneadas; preguntas fuera de alcance → fallback con
  contacto. Extensión futura vía `tap_chatbot_provider`.
- **Privacidad**: no se loguean mensajes; solo agregados de intents. Moderación solo
  sobre texto de reseñas/leads existentes.
- **Falsos positivos**: el resultado de `assess()` siempre es revisable por admin.