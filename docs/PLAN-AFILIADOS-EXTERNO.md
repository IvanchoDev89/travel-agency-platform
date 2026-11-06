# Plan: Programa de afiliados externos (cauteloso)

> **Estado:** ✅ Completado (2026-11-05)

## Objetivo
Permitir que partners externos promocionen tours y ganen comisión, sin riesgos legales ni pérdidas económicas.

## Riesgos identificados
1. **Fraude**: clics falsos, reservas canceladas, self-referral.
2. **Legal**: impuestos (1099/W-8), contratos, GDPR.
3. **Económico**: comisiones por reservas no pagadas, overpayment.
4. **Reputacional**: partners promocionan mal, spam.

## Safeguards (obligatorios)
- **Aprobación manual**: cada afiliado revisado por admin antes de activar.
- **Comisión solo tras pago completado** (no por click/reserva).
- **Ventana de validación**: comisión se libera 7-14 días después del tour completado (evita cancelaciones).
- **Límite de comisión**: cap por reserva (ej. max $50 o 10%).
- **Contrato digital**: términos aceptados al registrarse.
- **Datos fiscales**: W-9 (US) o W-8 (internacional) antes del primer pago.
- **Auditoría**: log de todos los clics y conversiones.
- **Blacklist**: IPs, emails, dominios sospechosos.

## Fases

### Fase 1 — Piloto (1 mes, 5-10 afiliados)
- Admin crea afiliados manualmente (no auto-registro).
- Comisión fija (ej. 5%) con tope ($30/reserva).
- Tracking básico (cookie + booking).
- Liquidación manual mensual.
- Monitoreo de fraude.

### Fase 2 — Automatización (si el piloto funciona)
- Auto-registro con aprobación.
- Dashboard de afiliado.
- Liquidación automática (PayPal).
- Alertas de anomalías.

### Fase 3 — Escala
- API para afiliados grandes.
- Integración con redes de afiliados (ShareASale, CJ).
- Programa de tiers (más conversión = mayor %).

## Implementación mínima (Fase 1)
1. Tabla `tap_affiliates` (id, name, email, code, commission_pct, status, tax_form, created_at).
2. Tabla `tap_affiliate_clicks` (id, affiliate_id, ip, user_agent, created_at).
3. Tracking: `?aff=CODE` → cookie 30 días → guardar en booking.
4. Comisión registrada al pagar (status='pending_release', libera a los 14 días).
5. Admin: CRUD de afiliados + vista de conversiones.
6. Liquidación manual (CSV export).

## Riesgos y mitigaciones
| Riesgo | Mitigación |
|--------|------------|
| Fraude de clics | Rate limit + fingerprinting |
| Reservas canceladas | Ventana de liberación 14 días |
| Evasión fiscal | W-9/W-8 obligatorio antes de pago |
| Spam | Aprobación manual + blacklist |
| Overpayment | Tope por reserva + auditoría |

## KPIs del piloto
- Conversiones por afiliado.
- Tasa de cancelación de reservas referidas.
- ROI (comisiones pagadas vs ingresos generados).
- Tasa de fraude detectado.

## Recomendación
**No implementar auto-registro aún.** Empezar con 5-10 afiliados seleccionados manualmente, comisión fija baja (5%) y liquidación manual. Si el ROI es positivo después de 1 mes, automatizar.

## Próximo paso
Si apruebas el piloto, implementar Fase 1 (tablas + tracking + admin CRUD + liquidación manual).
PY