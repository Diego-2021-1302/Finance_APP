# CHANGELOG — FINANCE APP 2.0

Todas las modificaciones notables introducidas en Finance App 2.0.

---

## [2.0.0] - 2026-11-17

### Añadido (Added)
- **Módulo de Cuentas Financieras como Eje Central**: Soporte para tipos Ahorro (`savings`), Corriente (`checking`) y Efectivo (`cash`). Saldo contable calculado mediante libro mayor unificado.
- **Gastos Directos Ocasionales**: Entidad `Expense` y servicio `RecordExpense` para consumos inmediatos sin requerir compromisos recurrentes previos.
- **Transferencias Internas Atómicas**: Entidad `Transfer` y servicio `RecordTransfer` con bloqueos pesimistas (`lockForUpdate()`) para mover fondos entre cuentas del usuario sin modificar el patrimonio total.
- **Conciliación de Saldos y Archivado de Cuentas**: Endpoints de ajuste explícito auditable (`POST /accounts/{id}/reconcile`) y archivado (`POST /accounts/{id}/archive`).
- **Asistente Inteligente e Interactiva**: Atributo `pending_action` con soporte de botones de selección interactiva (chips) en chat para confirmar o abortar transacciones.
- **Conservación de Día Base en Recurrencias**: Motor de fechas que mantiene el día de referencia original a través de meses cortos (ej. 31 de enero -> 28 de febrero -> 31 de marzo).
- **Aislamiento Multiusuario y Entregas de Notificaciones**: Registro en `notification_deliveries` para prevenir correos de recordatorio duplicados.

### Cambios (Changed)
- **Refactorización del Motor de Movimientos**: `TransactionService` centraliza todas las operaciones transaccionales con idempotencia y prevención de congelamiento por concurrencia.
- **Rediseño Completo GUI / UX**: Estilo Glassmorphism con desenfoque de fondo (`backdrop-filter: blur(16px)`), esquinas redondeadas suaves y navegación móvil táctil responsiva.
- **Internacionalización Completa**: Todos los módulos e interfaces están 100% en español dominicano con soporte de moneda `RD$ (DOP)`.

### Corregido (Fixed)
- Eliminación de salto brusco de página en el chat (`scrollIntoView` sustituido por scroll del contenedor interno).
- Eliminación de generación automática prematura de ocurrencias en el pago de compromisos.
- Aislamiento estricto de accesos cruzados por usuario.
