# Plan: Monetización avanzada (rentabilidad)

## Objetivo
Maximizar ingresos por reserva y por agencia, manteniendo una experiencia justa para B2B y B2C.

## 1. Comisiones escalonadas por volumen
- Tabla de tiers por agencia (reservas/mes).
- Ejemplo: 0-10 reservas = 10%, 11-30 = 12%, 31+ = 15%.
- Aplicar automáticamente en `TAP_Booking::get_agency_commission()`.
- Dashboard muestra tier actual y progreso.

## 2. Featured listings (tours destacados)
- Ya existe `tap_promotions`. Extender para:
  - Compra de destacado por 7/14/30 días.
  - Posicionamiento en resultados de búsqueda.
  - Badge "Destacado" visible.
- Admin aprueba/rechaza.
- Pago vía PayPal (ya integrado).

## 3. Suscripciones de agencia (planes)
- Ya existe `TAP_Subscriptions`. Mejorar:
  - Planes con límites claros (listados, fotos, analytics).
  - Add-ons (más fotos, analytics premium, soporte prioritario).
  - Upgrade/downgrade con prorrateo.
  - Recordatorios de renovación.

## 4. Upsells en checkout
- Bundles: tour + seguro + transporte.
- Seguro de viaje (módulo existe).
- Upgrade a tour privado.
- Fotos del tour post-experiencia.
- Implementar en `class-ajax-public.php` (calculate_booking_total) y booking form.

## 5. Afiliados externos
- API para afiliados externos.
- Comisión por click/reserva.
- Tracking con UTM + cookie.
- Dashboard de afiliado.

## 6. Dynamic pricing (yield)
- Ya existe `tap_pricing_rules`. Mejorar:
  - Reglas por demanda (occupancy-based).
  - Descuentos por temporada baja.
  - Precio por grupo.

## Implementación (orden)
1. Comisiones escalonadas (1 semana).
2. Featured listings mejorado (1 semana).
3. Upsells en checkout (1 semana).
4. Suscripciones mejoradas (1 semana).
5. Dynamic pricing (1 semana).
6. Afiliados externos (2 semanas).

## KPIs
- Ingreso por reserva (ARPU) +20%.
- % de agencias en plan pago > 40%.
- Ingreso por featured listings > 10% del total.
- Conversión de upsells > 15%.

## Riesgos
- Comisiones escalonadas pueden reducir margen si no se calcula bien.
- Featured listings pueden generar percepción de injusticia si no hay transparencia.
- Upsells pueden aumentar abandono si son agresivos.

## Mitigaciones
- Mostrar siempre el precio base antes del upsell.
- Transparencia en featured listings ("Patrocinado").
- A/B testing de upsells.

## Próximo paso
Empezar con comisiones escalonadas (menor riesgo, alto impacto).
PY