# Plan: Analytics y reportes del programa de referidos

> **Estado:** ✅ Completado (2026-11-05)

## Objetivo
Dar visibilidad al programa de referidos para optimizar el crecimiento (B2C) y la rentabilidad:
- Conversiones por referidor.
- Ingresos atribuidos.
- Puntos otorgados y canjeados.
- Top referidores.

## Alcance (Fase 1)
1. **Vista de reportes en admin** (menú Referidos → Reportes).
2. **Métricas clave**:
   - Clics, reservas, pagos, completados.
   - Tasa de conversión por etapa.
   - Ingresos atribuidos (sum total_amount de bookings con referral).
   - Puntos otorgados / canjeados.
3. **Filtros**: rango de fechas, nivel de afiliado.
4. **Top 10 referidores** con código, nivel, conversiones, ingresos, puntos.
5. **Export CSV** de referidos.

## Fuera de alcance (Fase 2)
- Gráficos interactivos (Chart.js).
- Cohortes por mes.
- Atribución multi-touch.

## Implementación
- Clase `TAP_Referral_Reports` con métodos de agregación SQL.
- Página admin `tap-referral-reports`.
- Reutilizar tabla `tap_customer_referrals` + `tap_bookings` + `tap_loyalty_points`.
- Nonce + capability `manage_options`.

## Criterios de aceptación
- Admin ve métricas consistentes con los datos.
- Export CSV descargable.
- PHPStan, i18n, tests en verde.
- No regresiones.
PY