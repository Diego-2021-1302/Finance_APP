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
| Catálogo financiero | `/api/v1/catalog/...` | `FinancialCatalogSelector.tsx`, `RecordsPage.tsx` | GET / POST | Verificado |
| Presupuestos | `/api/v1/budgets` | `PlanningPage.tsx` | GET / POST | Verificado |
| Reservas | `/api/v1/reserves` | `PlanningPage.tsx` | GET / POST / PATCH / DELETE | Verificado |
| Importaciones bancarias | `/api/v1/imports`, `/api/v1/imports/{id}` | `PlanningPage.tsx` | GET / POST / PATCH | Verificado |
| Dashboard financiero | `/api/v1/dashboard/summary` | `DashboardPage.tsx` | GET | Verificado |
| Proyección de liquidez | `/api/v1/reports/projection` | `ReportsPage.tsx` | GET | Verificado |
| Historial de deudas / Estados | `/api/v1/debts/history`, `/debts/{debt}/statements` | `DebtsPage.tsx` | GET | Pendiente |
| Aumentos y Pagos de deudas | `/api/v1/debts/{debt}/increases`, `/debts/{debt}/payments` | `DebtsPage.tsx` | POST | Pendiente |
| Compromisos | `/api/v1/commitments` | `RecordsPage.tsx` / Dashboard | GET / POST / PUT / DELETE | Verificado |
| Pagos de compromisos / Reversiones | `/api/v1/commitments/{commitment}/occurrences/{occurrence}/payments` | `RecordsPage.tsx` | POST | Pendiente |
| Calendario | `/api/v1/calendar`, `/api/v1/calendar/summary` | `DashboardPage.tsx` / Calendario | GET | Verificado |
| Prioridades de Pago | `/api/v1/priorities` | `DebtsPage.tsx` | GET | Pendiente |
| Reportes (Flujo de caja / Proyección) | `/api/v1/reports/cash-flow`, `/api/v1/reports/projection` | `ReportsPage.tsx` | GET | Verificado |
| Configuración | `/api/v1/settings` | `SettingsPage.tsx` | GET / PATCH | Verificado |
| Asistente IA (Chat) | `/api/v1/chat/messages`, `/api/v1/insights/recommendations` | `ChatPage.tsx` | GET / POST | Verificado |
| Presupuestos y Reservas | `/api/v1/budgets`, `/api/v1/reserves` | `RecordsPage.tsx` | GET / POST | Verificado |
| Importación de Estados | `/api/v1/imports` | `RecordsPage.tsx` | GET / POST / PATCH | Pendiente |
| Catálogo Financiero | `/api/v1/catalog/...` | `FinancialCatalogSelector.tsx` | GET / POST | Pendiente (Bloque 3) |
