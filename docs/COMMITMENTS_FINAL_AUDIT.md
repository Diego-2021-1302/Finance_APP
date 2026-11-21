# Auditoría Final — Módulo Compromisos

**Fecha:** 2026-11-20
**Proyecto:** GHOST FINANCE
**Alcance:** Plan maestro completo, BLOQUES 1 al 8

## Estado general

El módulo Compromisos quedó integrado en Laravel y React como una vista principal de obligaciones únicas, manteniendo ocurrencias recurrentes, pagos, reversiones, tarjetas, préstamos, catálogo, calendario y reportes.

## Cambios principales

### Organización

- Vista principal basada en compromisos únicos mediante `commitments`.
- Ocurrencias mantenidas como detalle secundario mediante `occurrences`.
- Pestañas para todos, tarjetas, préstamos, servicios/facturas, suscripciones/seguros y archivados.
- Búsqueda por nombre, institución y referencia.
- Filtros por tipo, estado y orden por vencimiento, importe o nombre.
- Paginación y resumen de período.

### Formularios

- Estado independiente para creación y edición.
- Límites de tarjeta vacíos enviados como `null`.
- Estados `unknown`, `pending_verification`, `confirmed` y `special_rules` preservados.
- Validaciones condicionales para tarjeta y préstamo.

### Tarjetas y préstamos

- Métricas de capacidad desde el backend.
- Información de límites, sobregiro, corte y vencimiento.
- Estados de cuenta vinculados a la deuda.
- Amortización francesa calculada por el motor contractual existente.
- Fuente de condición contractual expuesta cuando está disponible.

### Pagos

- Cuenta líquida requerida para préstamos y pagos ordinarios.
- Tarjetas permitidas únicamente como instrumento válido para facturas/servicios compatibles.
- Pago de tarjeta desde cuenta bancaria reduce deuda y efectivo sin duplicar gasto.
- Pago de factura con tarjeta aumenta la deuda vinculada y conserva el movimiento de tarjeta.
- Rechazo backend de tarjeta como origen de préstamo o pago de otra tarjeta.
- Idempotencia, transacciones, bloqueos de fila y reversiones mantenidos.

### Recurrencias

- Generación existente preservada.
- Pausa elimina únicamente vencimientos futuros pendientes sin pagos.
- Reanudación reactiva el compromiso y recrea una ocurrencia solo si no existe una pendiente.
- Los pagos e historial pagado no se eliminan al pausar.
- Calendario incluye `commitment_id` para enlazar el evento al compromiso.

### Integración frontend

- Claves TanStack Query centralizadas en `Frontend/src/api/queryKeys.ts`.
- Mutaciones de compromisos invalidan compromisos, cuentas, deudas, transacciones, calendario, dashboard y reportes.
- Estados de carga, error, resultado vacío, guardando y operación confirmada integrados donde corresponde.
- Importes monetarios enviados como cadenas decimales y calculados en backend.

## Resultados de pruebas ejecutados

### Backend

Comando:

```powershell
php artisan test
```

Resultado: **86 pruebas aprobadas, 409 aserciones**.

Recorrido maestro:

```powershell
php artisan test --filter=GhostFinanceMasterIntegrationTest
```

Resultado: **1 prueba aprobada, 12 aserciones**.

Migraciones:

```powershell
php artisan migrate:status
```

Resultado: todas las migraciones registradas están aplicadas. No hay migraciones pendientes.

Cobertura relevante:

- Creación y edición de compromisos.
- Pausa y reanudación sin duplicar vencimientos.
- Pagos parciales y completos.
- Pago de tarjeta desde banco.
- Pago de facturas con tarjeta.
- Rechazo de tarjeta como origen de préstamo.
- Límites desconocidos, sobregiro y capacidad insuficiente.
- Reversiones.
- Recurrencias y fin de mes.
- Aislamiento entre usuarios.
- Idempotencia y amortización.
- Calendario, dashboard y reportes.

### Frontend

Comandos:

```powershell
npm test
npm run build
```

Resultados:

- Vitest: **10 pruebas aprobadas**.
- TypeScript/Vite: build de producción completado.
- VS Code diagnostics: sin errores en los archivos principales revisados.

## Compatibilidad

Se conservaron:

- Creación, edición y archivo de compromisos.
- Recurrencias existentes.
- Pagos parciales y completos.
- Tarjetas y préstamos vinculados.
- Catálogo financiero.
- Calendario y reportes.
- Reversiones trazables.
- Dashboard y transacciones.

No se realizaron migraciones destructivas ni cambios de datos históricos.

## Riesgos residuales

- El botón de calendario navega a la página general de Compromisos; aún no selecciona automáticamente el registro concreto.
- No se ejecutó un recorrido Playwright/end-to-end de navegador en esta sesión.
- No se ejecutó una prueba manual real de 20 pasos con usuario, datos temporales y navegador compartido.
- El recorrido automático existente cubre 35 escenarios de integración backend y 409 aserciones totales, pero no reemplaza la verificación visual humana.

## Criterio de cierre

El módulo Compromisos conserva las funcionalidades existentes y ofrece organización por tipos, filtros, detalle de tarjeta/préstamo, recurrencias controladas y pagos compatibles con el backend.

No se identifican errores bloqueantes ni migraciones pendientes al cierre del Bloque 8.