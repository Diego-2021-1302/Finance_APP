# Plan de Implementación — Finance App

Este plan detalla las fases y tareas necesarias para refactorizar, optimizar y completar Finance App hasta convertirlo en un MVP robusto y listo para producción.

## Fases de Ejecución

### Bloque 1 — Integridad Financiera (Prioridad P0)
1. **Motor de Recurrencias y Vencimientos Independientes**
   - Implementar comando/servicio `GenerateCommitmentOccurrences` ejecutado por Laravel Scheduler para generar vencimientos a 60 días sin depender de que el pago anterior esté completo.
   - Garantizar idempotencia mediante índices únicos (`commitment_id`, `due_date`, `schedule_version`).
2. **Pagos Parciales, Saldos y Reversiones**
   - Refinar el cálculo de `remainingDue` y estados (`pending`, `partial`, `paid`, `overdue`).
   - Mejorar `ReversePayment` para restaurar saldos y mantener trazabilidad sin eliminar movimientos históricos.
3. **Concurrencia e Idempotencia**
   - Verificar transacciones atómicas con `lockForUpdate()` y claves de idempotencia en todos los pagos de compromisos, deudas e ingresos.

### Bloque 2 — Modelo Financiero y Cuentas (Prioridad P1)
1. **Ingresos Recurrentes y Cobros**
   - Separar la configuración de ingresos, cobros esperados y cobros confirmados.
   - Corregir la paginación y filtrado en `IncomeController`.
2. **Unificación de Deudas**
   - Sincronizar la relación entre deudas (`Debt`), movimientos de saldo (`DebtBalanceMovement`) y compromisos amortizados (`Commitment`).

### Bloque 3 — Seguridad y Autenticación (Prioridad P0)
1. **Autenticación y Aislamiento Multiusuario**
   - Validar políticas (`Policies`) en todos los endpoints y recursos.
   - Reforzar la gestión de sesiones y tokens en API y Frontend.

### Bloque 4 — Automatización y Notificaciones (Prioridad P1)
1. **Notificaciones Automatizadas con Colas**
   - Implementar tabla de registro de entregas (`notification_deliveries`) para evitar duplicados.
   - Revalidar estado del vencimiento antes de enviar recordatorios vía colas de Laravel.

### Bloque 5 — Optimización Frontend y Backend (Prioridad P2)
1. **Dashboard y Reportes**
   - Centralizar agregaciones financieras en servicios reutilizables.
   - Asegurar que el dashboard muestre saldos reales vs proyectados correctamente.
2. **Optimización de Consultas y Paginación**
   - Eager loading y paginación en servidor para listados de compromisos, transacciones e ingresos.

### Bloque 6 — Pruebas y Producción (Prioridad P0/P1)
1. **Batería de Pruebas Financieras**
   - Implementar pruebas unitarias y de característica cubriendo los 30 escenarios requeridos.
2. **Preparación para Producción y Docker**
   - Revisar configuración de Docker (`compose.yaml`), variables de entorno, seguridad, backups y documentación de despliegue.
