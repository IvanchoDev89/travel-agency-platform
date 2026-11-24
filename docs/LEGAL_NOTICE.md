# Aviso legal y checklist de revisión (v1.7.0)

El código implementa la **mecánica** de contratos digitales: documentos
versionados con hash, aceptaciones con evidencia, correo de confirmación y
bloqueo de pago/contratación hasta aceptar. **No constituye asesoría legal.**
Los textos incluidos son una base profesional, no una aprobación jurídica.

## Antes de publicar

- [ ] Revisión por abogado en Costa Rica de los cuatro documentos:
  - `traveler_terms` (Términos del viajero)
  - `agency_terms` (Contrato de agencia)
  - `cancellation_policy` (Política general de cancelación)
  - `privacy_notice` (debe armonizarse con la Ley 8968 y el Aviso vigente)
- [ ] Confirmar el marco aplicable: Ley 8454 (documentos/firmas electrónicas),
      Ley 7472 (consumidor) y ley de gobernanza de servicios digitales y
      comercio electrónico. Validar especialmente el derecho de retracto en
      servicios de viaje y sus excepciones.
- [ ] Definir el correo contractual y el responsable legal reales
      (`admin_email`, `tap_privacy_contact`).
- [ ] Revisar la cláusula de comisiones con el modelo de dinero v1.7.0:
      comisión sobre subtotal neto, tarifa pagada por la agencia, seguro
      excluido de la base.
- [ ] Aprobación de Hacienda: la factura electrónica sigue siendo un hook;
      requiere certificado y credentials reales.
- [ ] Publicar las versiones ES/EN definitivas desde
      `wp-admin → Travel Platform → Contratos digitales` (cada cambio crea una
      versión nueva e invalida aceptaciones anteriores).
- [ ] Añadir páginas públicas con `[tap_legal_doc key="..."]` y enlazarlas desde
      el checkout y el panel de agencia.

## Evidencia que conserva el sistema

Por cada aceptación: documento, versión, hash SHA-256 del HTML, nombre del
firmante, fecha del servidor, hash de IP y User-Agent, reserva/agencia
asociada y confirmación por correo. Los documentos publicados nunca se
sobrescriben: editar crea una versión nueva, por lo que siempre se puede
reconstruir qué texto aceptó cada persona.

## No cubierto

- Firma digital certificada (certificado p12 / CA) — la ley 8454 la contempla,
  pero aquí solo hay consentimiento electrónico con evidencia.
- Archivado notarial / timestamping externo (TSA).
- Traducción jurada de los textos internacionales.
