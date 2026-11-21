# Auditoría Base — Módulo Compromisos

**Fecha:** 2026-11-20
**Alcance:** Bloque 1 del plan maestro de mejora de Compromisos
**Proyecto:** GHOST FINANCE

## 1. Resumen ejecutivo

El módulo Compromisos ya dispone de una implementación integrada en Laravel y React. No requiere reconstrucción de extremo a extremo.

La API de compromisos devuelve simultáneamente:

- Un listado de compromisos únicos activos mediante `commitments`.
- Un listado de ocurrencias programadas mediante `occurrences`, cada una vinculada a su compromiso.

El frontend actual consume ambos conjuntos, pero representa la vista principal a partir de `occurrences`. Por tanto, la siguiente fase debe cambiar la presentación principal hacia `commitments`, conservar `occurrences` como vista secundaria y evitar sustituir el endpoint actual.

## 2. Estado real de pantalla

| Área | Archivo/Componente | Estado real |
|---|---|---|
| Página principal | `Frontend/src/pages/RecordsPage.tsx` (`CommitmentsPage`) | Funcional, pero centraliza la vista de compromisos dentro de una página grande que mezcla formularios, edición y pagos. |
| Vista actual | `occurrences.map(...)` | Presenta cada vencimiento como tarjeta. Puede duplicar visualmente el mismo compromiso si tiene varias ocurrencias. |
| Datos únicos disponibles | `query.data.commitments` | Ya existen en la respuesta API, pero no son usados por la vista principal actual. |
| Selección del catálogo | `Frontend/src/components/FinancialCatalogSelector.tsx` | Integrada para tarjetas. Permite vincular institución, producto y variante. |
| Capacidad de tarjeta | `Frontend/src/components/CardCapacityPanel.tsx` | Reutilizable y muestra límite aprobado, sobregiro, uso y capacidad restante. Usa cálculos visuales en números JavaScript. |
| Confirmación de pagos con tarjeta | `Frontend/src/components/CreditCardPaymentConfirmation.tsx` | Existe, pero no está integrada directamente en el flujo principal de pago mostrado por `CommitmentsPage`. |
| Deudas relacionadas | `Frontend/src/pages/DebtsPage.tsx` y `DebtBalancesSection` | Mantiene una vista separada de deudas y muestra datos enlazados a compromisos. |
| Navegación | `RecordsPage.tsx` | Las pestañas de la aplicación llegan a Compromisos, pero no existe todavía la pestaña por categorías de compromiso solicitada. |

## 3. Estado real del frontend

### Acciones existentes

| Acción | Componente | Endpoint | Método | Validación frontend | Estado |
|---|---|---|---|---|---|
| Listar compromisos y ocurrencias | `CommitmentsPage` | `/api/v1/commitments` | GET | Estados pendiente/error/carga | Funcional |
| Crear compromiso | Formulario inline de creación | `/api/v1/commitments` | POST | Campos HTML y estado por tipo | Funcional; necesita separación de formularios |
| Editar compromiso | Formulario inline de edición | `/api/v1/commitments/{commitment}` | PUT | Campos HTML por tipo | Funcional; comparte estado `commitmentType` con creación |
| Eliminar/archivar | Botón con confirmación | `/api/v1/commitments/{commitment}` | DELETE | Confirmación de navegador | Arquitectura actual archiva el compromiso y elimina ocurrencias pendientes no pagadas |
| Registrar pago de ocurrencia | Formulario dentro de cada ocurrencia | `/api/v1/commitments/{commitment}/occurrences/{occurrence}/payments` | POST | Monto, cuenta y fecha | Funcional; el selector no filtra tipos de cuenta de manera explícita |
| Revertir pago | Página de transacciones | `/api/v1/payments/{payment}/reversals` | POST | Motivo obligatorio | Funcional |
| Consultar catálogo | `FinancialCatalogSelector` | `/api/v1/catalog/...` | GET | Selección de institución/producto/variante | Funcional |
| Vincular catálogo | `CommitmentsPage` / catálogo | `/api/v1/catalog/link` | POST | Selección completa | Funcional |
| Consultar capacidad de tarjeta | Datos incluidos en lista | `/api/v1/commitments` | GET | Muestra métricas si existen | Funcional |

### Hallazgos frontend

1. `CommitmentsPage` mantiene estados independientes para mostrar formularios, pero `commitmentType` es compartido entre creación y edición. Cambiar el tipo en edición puede alterar simultáneamente los campos de creación si ambos formularios están visibles.
2. La creación de tarjetas usa `String(data.get('approved_credit_limit') || '0')` y `String(data.get('authorized_overdraft_limit') || '0')`. Un límite vacío puede convertirse en cero confirmado.
3. Los campos de edición de tarjeta requieren límites numéricos con valores predeterminados de cero. Esto puede convertir ausencia de información en confirmación accidental.
4. El selector de origen de pago usa todos los resultados de cuentas sin una exclusión explícita de tarjetas para pagos ordinarios.
5. El pago se confirma directamente desde el formulario; no aparece en `CommitmentsPage` una vista previa reutilizando `CreditCardPaymentConfirmation`.
6. El frontend ya invalida todas las consultas activas después de mutaciones, pero las claves no están centralizadas en una capa de dominio. Esto debe mantenerse compatible y revisarse en la integración posterior.
7. Los importes se transportan como cadenas hacia el backend y se convierten para presentación en el cliente. No se deben añadir cálculos financieros definitivos en React.

## 4. Estado real del backend

| Acción | Controlador/Servicio/Modelo | Validación | Estado |
|---|---|---|---|
| Crear compromiso único | `CommitmentController::store` / `Commitment` | `StoreCommitmentRequest`, validación condicional por tipo | Funcional |
| Listar únicos y ocurrencias | `CommitmentController::index` | Filtra por `user_id` y compromiso activo | Funcional |
| Editar compromiso | `CommitmentController::update` | Validación separada para tarjeta y demás tipos | Funcional; reglas de límite aún requieren endurecer contrato |
| Archivar compromiso | `CommitmentController::destroy` | Consulta por propietario | Funcional; conserva pagos históricos y elimina solo ocurrencias pendientes sin pagos |
| Registrar pago | `CommitmentController::payment` / `RecordCommitmentPayment` | Petición, propiedad de compromiso/ocurrencia, idempotencia y capacidad | Funcional y probado |
| Revertir pago | `PaymentReversalController` / `ReversePayment` | Usuario, clave de idempotencia y operación reversible | Funcional y probado |
| Generar recurrencias | `GenerateCommitmentOccurrences` / `CommitmentRecurrence` | Lógica por frecuencia y horizonte de 60 días | Funcional; preserva día base de recurrencia |
| Calcular amortización | `RecalculateCommitmentAmortization` | Cálculos con BCMath | Funcional |
| Consultar tarjeta | `CreditCardAuthorizationEngine` | Estados de límite y deuda vinculada | Funcional |
| Catálogo financiero | `FinancialCatalogController` | Instituciones, variantes y asociación por propietario | Funcional |
| Estados de cuenta | `CreditCardStatement` / comando de corte | Ledger y corte de tarjeta | Funcional |

## 5. Matriz de funcionalidades y pruebas

| Pantalla | Acción | Endpoint | Método | Validación | Modelo | Servicio | Prueba existente | Estado real |
|---|---|---|---|---|---|---|---|---|
| Compromisos | Listar | `/api/v1/commitments` | GET | Usuario autenticado | `Commitment`, `CommitmentOccurrence` | — | Suite Ghost Finance / integración | Funcional |
| Compromisos | Crear tarjeta | `/api/v1/commitments` | POST | `StoreCommitmentRequest` | `Commitment`, `Account`, `Debt` | `DebtBalanceLedger` | `CreditCardArchitectureTest`, `DebtMinimumPaymentTest` | Funcional, contrato límite por revisar |
| Compromisos | Crear préstamo | `/api/v1/commitments` | POST | Detalles contractuales obligatorios | `Commitment`, `Debt` | — | `FinancialFlowTest` | Funcional |
| Compromisos | Crear factura/servicio/suscripción | `/api/v1/commitments` | POST | Tipo, frecuencia y fecha | `Commitment`, `CommitmentOccurrence` | `CommitmentRecurrence` | `FinancialFlowTest`, `CalendarSummaryTest` | Funcional |
| Compromisos | Editar | `/api/v1/commitments/{commitment}` | PUT | Validación por tipo | `Commitment` | — | Cobertura indirecta en suites financieras | Funcional, debe separarse en frontend |
| Compromisos | Archivar | `/api/v1/commitments/{commitment}` | DELETE | Propietario y fila activa | `Commitment`, `CommitmentOccurrence` | — | Sin prueba específica identificada en la baseline | Parcial; falta mejorar archivo/consulta inactiva |
| Compromisos | Pagar ocurrencia | `/api/v1/commitments/{commitment}/occurrences/{occurrence}/payments` | POST | Clave UUID, cuenta, importe y fecha | `Payment`, `CommitmentOccurrence` | `RecordCommitmentPayment` | `CreditCardPaymentInstrumentTest`, `FinancialFlowTest` | Funcional |
| Tarjetas | Consumir con tarjeta | `/api/v1/expenses` | POST | Propietario, saldo, límite y sobregiro | `Account`, `Debt`, `FinancialTransaction` | `TransactionService`, `CreditCardAuthorizationEngine` | `OverdraftAndFeeEngineTest`, `AccountMovementTest` | Funcional |
| Tarjetas | Pagar desde banco | `/api/v1/commitments/.../payments` | POST | Cuenta compatible y propiedad | `Payment`, `Debt`, `DebtBalanceMovement` | `RecordCommitmentPayment`, `DebtBalanceLedger` | `CreditCardPaymentInstrumentTest` | Funcional |
| Tarjetas | Configurar límites | `/api/v1/debts/{debt}/card-limits` | PATCH | Estado y límites | `Debt`, `Commitment`, `Account` | `CreditCardAuthorizationEngine` | `OverdraftAndFeeEngineTest` | Funcional |
| Tarjetas | Consultar autorización previa | `/api/v1/debts/{debt}/authorize-card` | POST | Importe y capacidad | `Debt` | `CreditCardAuthorizationEngine` | `OverdraftAndFeeEngineTest` | Funcional |
| Compromisos | Revertir | `/api/v1/payments/{payment}/reversals` | POST | Propietario, motivo e idempotencia | `Payment` | `ReversePayment` | `CreditCardStatementTest` | Funcional |
| Catálogo | Consultar/vincular | `/api/v1/catalog/...` | GET/POST | Producto y entidad del usuario | `Commitment`, tablas de catálogo | `FinancialCatalogController` | `FinancialCatalogTest` | Funcional |
| Calendario | Consultar vencimientos | `/api/v1/calendar` | GET | Rango de fechas y usuario | `CommitmentOccurrence` | `CommitmentRecurrence` | `CalendarSummaryTest`, `FinancialFlowTest` | Funcional |
| Reportes | Comparar flujo real/proyectado | `/api/v1/reports/cash-flow` | GET | Rango, agrupación y usuario | `Payment`, `CommitmentOccurrence` | `CommitmentRecurrence` | `FinancialReportAccountingTest` | Funcional |

## 6. Dependencias cruzadas

| Módulo | Relación con Compromisos | Impacto esperado |
|---|---|---|
| Dashboard | Consume `CommitmentOccurrence`, pagos y deudas | El resumen debe seguir usando datos de API y distinguir obligaciones pendientes, vencidas y deuda real. |
| Calendario | Consume ocurrencias por rango de fechas | Debe enlazar cada vencimiento con `commitment_id` sin generar duplicados. |
| Gastos | Los gastos directos pueden originarse en cuentas bancarias o tarjetas | El tipo de cuenta es parte de la seguridad financiera; no debe confundirse con el compromiso que se paga. |
| Cuentas | Paga compromisos desde una cuenta y actualiza el movimiento correspondiente | La UI debe excluir tarjetas como origen ordinario y validar propiedad/compatibilidad. |
| Deudas | Tarjetas y préstamos pueden tener modelos duplicados pero vinculados | El detalle debe leer los datos conectados sin calcular saldos definitivos en React. |
| Transacciones | Los pagos y reversiones generan movimientos auditables | El módulo debe invalidar o actualizar esta consulta tras mutaciones. |
| Reportes | Usa ocurrencias, pagos y reversión | Los importes parciales y reversiones deben seguir reflejándose sin duplicación. |

## 7. Protección financiera existente

- Los importes monetarios críticos se guardan como decimal y se calculan con BCMath.
- Las operaciones de pago se ejecutan dentro de una transacción de base de datos.
- Los pagos usan `idempotency_key` UUID y detectan reutilización para operación distinta.
- Las consultas financieras filtran por `user_id` antes de resolver compromiso, ocurrencia, cuenta o deuda.
- El action de pago bloquea filas de cuenta y compromiso con `lockForUpdate()`.
- Las tarjetas pueden vincularse con una cuenta y una deuda mediante claves externas.
- El backend reconoce pagos parciales y amortización para préstamos/tarjetas.
- Las reversiones mantienen trazabilidad y recalculan estados vinculados.

## 8. Brechas confirmadas para fases posteriores

1. La vista principal no utiliza el arreglo único `commitments`; muestra `occurrences` y puede representar el mismo compromiso varias veces.
2. Falta una interfaz clara de pestañas por tipo y una opción explícita para compromisos inactivos.
3. Creación y edición comparten `commitmentType` y todavía tienen formularios acoplados en un único componente.
4. La creación de tarjeta convierte límites vacíos en cero durante la preparación del payload.
5. El payload de edición de tarjeta exige límites numéricos en lugar de permitir estados de límite desconocido sin convertirlos en cero confirmado.
6. El selector de cuenta de pago no excluye explícitamente cuentas de tarjeta para pagos ordinarios.
7. `CreditCardPaymentConfirmation` existe pero no está integrado en el flujo de pago de compromisos.
8. Falta una vista de detalle único con próximo vencimiento, historial de vencimientos y pagos.
9. No se identificó en la ruta actual un endpoint dedicado de detalle de compromiso; puede reutilizarse el listado único para la primera fase, pero conviene verificar/crear detalle si se necesita historial completo.
10. No se identificó en la ruta actual un endpoint dedicado de pagos por compromiso independiente de la ocurrencia; reutilizar el endpoint existente es compatible.
11. La eliminación usa `DELETE` y desactiva el compromiso; no existe todavía una vista para reactivar o consultar archivados.
12. El catálogo puede vincular personalizaciones contractuales, pero el frontend todavía no muestra una ficha completa de fuente/estado en la vista principal.

## 9. Pruebas iniciales ejecutadas

### Backend

Comando:

```powershell
php artisan test
```

Resultado real: `81` pruebas pasaron con `369` aserciones.

Cobertura relevante observada:

- Creación y vínculo de tarjetas con cuenta/deuda.
- Pagos con tarjeta y desde cuenta bancaria.
- Pago de utilidades con tarjeta.
- Límites, sobregiro y capacidad insuficiente.
- Pagos parciales y recurrencias.
- Amortización y recalculo de préstamos.
- Reversiones y estados de cuenta.
- Aislamiento entre usuarios.
- Catálogo financiero.
- Reportes, calendario y dashboard.

### Frontend

Comando:

```powershell
npm test
```

Resultado real: `2` archivos de pruebas pasaron con `8` pruebas.

Comando de compilación:

```powershell
npm run build
```

Resultado real: compilación de producción completada correctamente con Vite y TypeScript. La salida mostró advertencias no bloqueantes de anotaciones en dependencias Zod.

## 10. Base de datos

No se realizaron migraciones ni modificaciones de datos durante el Bloque 1.

El modelo actual ya contiene los campos necesarios para:

- Límite aprobado y sobregiro.
- Estado de confirmación del límite.
- Reglas especiales documentadas.
- Corte y gracia de pago.
- Interés y amortización.
- Vinculación con cuenta y deuda.
- Recurrencias y ocurrencias independientes.
- Pagos e idempotencia.

## 11. Conclusión del Bloque 1

**Estado:** COMPLETADO.

La implementación existente cubre gran parte de los requisitos financieros del módulo. La estrategia para el siguiente bloque debe reutilizar el endpoint `/api/v1/commitments`, que ya entrega tanto compromisos únicos como ocurrencias, y mejorar el frontend sin introducir rutas paralelas ni alterar el backend contable.

No se continuó al Bloque 2 ni se modificó código funcional.