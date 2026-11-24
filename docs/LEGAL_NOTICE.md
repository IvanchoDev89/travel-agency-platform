# Paquete legal para revisión — v1.7.1

## Qué se entrega

Cuatro documentos completos, en español e inglés, en
`app/public/wp-content/plugins/travel-agency-platform/legal/`:

| Documento | Clave | Tema central |
|---|---|---|
| Términos del viajero | `traveler_terms` | Relación plataforma–viajero, contrato directo con la agencia, precio, pago, retracto, disputas |
| Contrato de agencia | `agency_terms` | Verificación, obligaciones, comisión sobre subtotal neto, liquidaciones, datos, indemnidad |
| Política de cancelación | `cancellation_policy` | Flexible/moderada/estricta/no reembolsable, retracto de 8 días, reembolsos parciales |
| Aviso de privacidad | `privacy_notice` | Responsable, finalidades, ARCO, PRODHAB, cookies, retención, seguridad |

Son textos profesionales **preparados para revisión**, no aprobación jurídica. La
Plataforma conserva evidencia de versión, hash SHA-256, firmante, timestamp e IP/UA
hasheados, y envía correo de confirmación (Ley N.° 8454).

## Marco normativo invocado

- **Ley N.° 7472** — Defensa Efectiva del Consumidor. Art. 31 y derecho de retracto
  de ocho días; información clara y veraz; Comisión Nacional del Consumidor (CNC).
- **Ley N.° 8454** — Certificados, Firmas Digitales y Documentos Electrónicos.
  Validez del consentimiento electrónico y de los documentos electrónicos.
- **Ley N.° 8968** — Protección de la Persona frente al Tratamiento de sus Datos
  Personales. Derechos ARCO, PRODHAB, registro de bases de datos.
- **Ley N.° 10946** — Gobernanza de los Servicios Digitales y el Comercio
  Electrónico. Publicada 24/06/2026, **vigente 24/06/2027**. Exige precio total
  visible, comprobante durable, canal de reclamo gratuito en el mismo medio,
  términos en español y lenguaje claro, identificación de publicidad y prohibición
  de patrones oscuros (art. 17). Los textos ya anticipan estas exigencias.
- **Código Civil** — contratos, mandato y responsabilidad civil.
- **Ley N.° 9635 / régimen de facturación electrónica** — responsable de Hacienda.

## Marcadores que DEBES completar antes de publicar

| Marcador | Dónde | Valor esperado |
|---|---|---|
| `[NOMBRE LEGAL DE LA PLATAFORMA A COMPLETAR]` / `[NOMBRE LEGAL DE LA PLATAFORMAPARA COMPLETAR]` | Todos los ES | Razón social exacta |
| `[LEGAL NAME OF THE PLATFORM TO COMPLETE]` | Todos los EN | Nombre legal |
| `[COMPLETAR]` (cédula jurídica) | Todos | Número de identificación |
| `[DIRECCIÓN COMPLETA, COSTA RICA]` / `[FULL ADDRESS, COSTA RICA]` | Todos | Domicilio legal |
| `[CORREO LEGAL]` / `[EMAIL]` | Todos | Correo contractual |
| `[tap_privacy_contact — COMPLETAR]` | Aviso de privacidad | Correo real y monitorizado |

También deben configurarse en WordPress: `admin_email`, `tap_privacy_contact`,
`siteurl`/`home`, HTTPS forzado y SMTP.

## Checklist para el abogado

**Términos del viajero**
- [ ] Confirmar que la plataforma como **intermediario** y la agencia como
      prestadora directa es la estructura correcta frente a la CNC.
- [ ] Revisar el derecho de retracto (8 días, Ley 7472) y las excepciones por
      naturaleza del servicio de viaje. ¿Qué documentación probará la renuncia
      informada cuando se aplica la política específica?
- [ ] Revisar el plazo de retención de reservas (30 min) y la cláusula de no show.
- [ ] Validar la cláusula de moneda/tipo de cambio y la de impuestos.
- [ ] Confirmar la jurisdicción de San José y si se prefiere arbitraje.

**Contrato de agencia**
- [ ] Revisar la base de comisión (subtotal neto, sin tarifa ni seguro) y el
      incentivo por volumen con piso del 50%.
- [ ] Revisar la retención del 20% de la prima de seguro y su reflejo en la póliza.
- [ ] Confirmar la redacción de indemnidad y el límite de responsabilidad.
- [ ] Revisar la calidad de responsable/encargada de datos de la agencia y el
      acuerdo de tratamiento que debería acompañar este contrato.
- [ ] Definir si procede un contrato de mandato separado o una cláusula de mandato
      más amplia (Código Civil).

**Política de cancelación**
- [ ] Ajustar los porcentajes y ventanas a la operación real y a las fichas.
- [ ] Confirmar el plazo de reintegro y la responsabilidad por reembolsos fallidos.
- [ ] Revisar el trato de reembolsos parciales y de la prima del seguro.

**Aviso de privacidad**
- [ ] Completar el responsable y evaluar la inscripción de bases de datos ante PRODHAB.
- [ ] Revisar transferencias internacionales (PayPal, Onvo, hosting, correo).
- [ ] Confirmar plazos de conservación fiscal versus protección de datos.
- [ ] Revisar cookies analíticas y consentimiento (Ley 10946 / buenas prácticas PRODHAB).

## Lo que el sistema NO cubre todavía

- Firma digital **certificada** con certificado p12/CA (Ley 8454): se usa
  consentimiento electrónico con evidencia, no firma certificada.
- Timestamping externo (TSA) ni sellado notarial.
- Factura electrónica de Hacienda: sigue siendo un hook sin certificado.
- Canal de reclamos visible en todas las fichas públicas (el panel ya existe;
  conviene un enlace global en el footer).
- Suscripciones con renovación automática: hoy no se usan; si se activan, exigir
  consentimiento expreso y baja en un solo paso (Ley 10946 art. 17).

## Ciclo de publicación

1. El abogado edita los HTML en `.../travel-agency-platform/legal/`.
2. Al cargar el sitio, `TAP_Legal::seed_defaults()` detecta el cambio de hash y
   crea una **versión nueva**; la anterior queda inmutable.
3. Las aceptaciones de la versión anterior dejan de ser "vigentes"; el checkout y
   el panel de agencia vuelven a pedir firma antes del siguiente pago o plan.
4. Cada aceptación guarda versión + hash y envía correo con constancia.
