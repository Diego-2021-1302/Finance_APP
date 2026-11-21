# API Integration Matrix — Ghost Finance

This matrix maps backend API endpoints to frontend screens, methods, and verification status across the Ghost Finance platform.

| Funcionalidad | Endpoint / Ruta | Pantalla Frontend | Método | Estado |
|---|---|---|---|---|
| Autenticación (Login) | `/api/v1/auth/login` | `AuthPages.tsx` | POST | Verificado |
| Autenticación (Registro) | `/api/v1/auth/register` | `AuthPages.tsx` | POST | Verificado |
| Autenticación (Usuario actual) | `/api/v1/auth/me` | `AuthContext.tsx` | GET | Verificado |
| Autenticación (Logout) | `/api/v1/auth/logout` | `AuthContext.tsx` | POST | Verificado |
| Dashboard Summary | `/api/v1/dashboard/summary` | `DashboardPage.tsx` | GET | Verificado |
| Cuentas (Listar / Crear) | `/api/v1/accounts` | `RecordsPage.tsx` | GET / POST | Verificado |
| Cuentas (Actualizar / Borrar / Archivar / Conciliar) | `/api/v1/accounts/{account}` | `RecordsPage.tsx` | PUT / DELETE / POST | Verificado |
| Ingresos (Listar / Crear) | `/api/v1/incomes` | `RecordsPage.tsx` | GET / POST | Verificado |
| Ingresos (Actualizar / Borrar / Recibos) | `/api/v1/incomes/{income}` | `RecordsPage.tsx` | PUT / DELETE / POST | Verificado |
| Gastos (Listar / Crear) | `/api/v1/expenses` | `RecordsPage.tsx` | GET / POST | Verificado |
| Gastos (Actualizar / Borrar) | `/api/v1/expenses/{expense}` | `RecordsPage.tsx` | PUT / DELETE | Verificado |
| Transferencias | `/api/v1/transfers` | `RecordsPage.tsx` | GET / POST | Verificado |
| Transacciones | `/api/v1/transactions` | `RecordsPage.tsx` | GET | Verificado |
| Deudas y Tarjetas | `/api/v1/debts` | `DebtsPage.tsx`, `CardCapacityPanel.tsx` | GET / POST | Pendiente (Bloque 2) |
| Pagos mínimos de deuda | `/api/v1/debts/{debt}/minimum-payment` | `DebtsPage.tsx` | PATCH | Pendiente |
| Configuración de límites de tarjeta | `/api/v1/debts/{debt}/card-limits` | `RecordsPage.tsx` | PATCH | Verificado |
| Vista previa de autorización de tarjeta | `/api/v1/debts/{debt}/authorize-card` | `RecordsPage.tsx` | POST | Verificado |
| Catálogo financiero | `/api/v1/catalog/institutions`, `/products`, `/variants` | `FinancialCatalogSelector.tsx`, `RecordsPage.tsx` | GET / POST | Verificado; filtra productos y variantes activas/verificadas y carga múltiples familias por entidad |
| Presupuestos | `/api/v1/budgets` | `PlanningPage.tsx` | GET / POST | Verificado |
| Reservas | `/api/v1/reserves` | `PlanningPage.tsx` | GET / POST / PATCH / DELETE | Verificado |
| Importaciones bancarias | `/api/v1/imports`, `/api/v1/imports/{id}` | `PlanningPage.tsx` | GET / POST / PATCH | Verificado |
| Dashboard financiero | `/api/v1/dashboard/summary` | `DashboardPage.tsx` | GET | Verificado |
| Proyección de liquidez | `/api/v1/reports/projection` | `ReportsPage.tsx` | GET | Verificado |
| Historial de deudas / Estados | `/api/v1/debts/history`, `/debts/{debt}/statements` | `DebtsPage.tsx` | GET | Pendiente |
| Aumentos y Pagos de deudas | `/api/v1/debts/{debt}/increases`, `/debts/{debt}/payments` | `DebtsPage.tsx` | POST | Pendiente |
| Compromisos únicos | `/api/v1/commitments` | `RecordsPage.tsx` | GET / POST / PUT / DELETE | Verificado |
| Pausar / Reanudar compromiso | `/api/v1/commitments/{commitment}/pause`, `/resume` | `RecordsPage.tsx` | POST | Verificado |
| Pagos de compromisos | `/api/v1/commitments/{commitment}/occurrences/{occurrence}/payments` | `RecordsPage.tsx` | POST | Verificado |
| Reversiones | `/api/v1/payments/{payment}/reversals` | `RecordsPage.tsx` / Transacciones | POST | Verificado |
| Calendario | `/api/v1/calendar`, `/api/v1/calendar/summary` | `DashboardPage.tsx` / Calendario | GET | Verificado |
| Prioridades de Pago | `/api/v1/priorities` | `DebtsPage.tsx` | GET | Pendiente |
| Reportes (Flujo de caja / Proyección) | `/api/v1/reports/cash-flow`, `/api/v1/reports/projection` | `ReportsPage.tsx` | GET | Verificado |
| Configuración | `/api/v1/settings` | `SettingsPage.tsx` | GET / PATCH | Verificado |
| Asistente IA (Chat) | `/api/v1/chat/messages`, `/api/v1/insights/recommendations` | `ChatPage.tsx` | GET / POST | Verificado |
| Presupuestos y Reservas | `/api/v1/budgets`, `/api/v1/reserves` | `RecordsPage.tsx` | GET / POST | Verificado |
| Importación de Estados | `/api/v1/imports` | `RecordsPage.tsx` | GET / POST / PATCH | Pendiente |
| Catálogo Financiero | `/api/v1/catalog/...` | `FinancialCatalogSelector.tsx` | GET / POST | Verificado |

## Matriz detallada — Compromisos (Bloque 7)

| Acción visible | Componente | Endpoint | Método | Payload principal | Respuesta | Invalidaciones | Prueba |
|---|---|---|---|---|---|---|---|
| Listar/filtar compromisos | `CommitmentsPage` | `/api/v1/commitments` | GET | `status,type,institution,search,sort,page,per_page` | `commitments,occurrences,summary,meta` | Consulta reactiva por filtros | Suite backend + build frontend |
| Crear compromiso | Formulario de creación | `/api/v1/commitments` | POST | Campos por tipo | `{ data: Commitment }` | Compromisos, cuentas, deudas, dashboard, calendario, reportes | Suite backend + cliente Vitest |
| Editar compromiso | Formulario de edición | `/api/v1/commitments/{commitment}` | PUT | Campos editables por tipo | `{ data: Commitment }` | Compromisos, cuentas, deudas, dashboard, calendario, reportes | Suite backend |
| Archivar compromiso | Acción de archivo | `/api/v1/commitments/{commitment}` | DELETE | — | `{ data: { deleted } }` | Compromisos, calendario, dashboard, reportes | Suite backend |
| Pausar compromiso | Acción Pausar | `/api/v1/commitments/{commitment}/pause` | POST | — | `{ data: Commitment }` | Compromisos, calendario, dashboard, reportes | Test de pausa/reanudación |
| Reanudar compromiso | Acción Reanudar | `/api/v1/commitments/{commitment}/resume` | POST | — | `{ data: Commitment }` | Compromisos, calendario, dashboard, reportes | Test de pausa/reanudación |
| Registrar pago | Detalle de ocurrencia | `/api/v1/commitments/{commitment}/occurrences/{occurrence}/payments` | POST | `amount,account_id,paid_at,idempotency_key` | `{ data, remaining_due }` | Compromisos, cuentas, deudas, transacciones, calendario, dashboard, reportes | Pruebas de instrumentos de pago |
| Revertir pago | Transacciones | `/api/v1/payments/{payment}/reversals` | POST | `reason,idempotency_key` | `{ reversed, reason }` | Compromisos, cuentas, deudas, transacciones, calendario, dashboard, reportes | Suite de reversiones |
| Vista previa tarjeta | Confirmación de pago | `/api/v1/debts/{debt}/authorize-card` | POST | `amount,occurred_at` | Balance, capacidad, cargos y fuente contractual | Sin mutación; consulta bajo demanda | Pruebas de autorización de tarjeta |
| Estados de cuenta | Detalle de tarjeta | `/api/v1/debts/{debt}/statements` | GET | — | Lista de estados de cuenta | `debt-statements` | Suite de estados de cuenta |
