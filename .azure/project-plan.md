# Project Plan

**Status**: Integrated
**Created**: 2026-10-29
**Mode**: NEW

---

## 1. Project Overview

**Goal**: Build a responsive personal-finance application for tracking forecast and received income, recurring commitments, independent due dates, partial payments, accounts, movements, debts, cards, and cash-flow reporting in Dominican pesos. Use decimal-safe money values, isolate records by user, make critical operations idempotent, transactional, and auditable, never store full card numbers, and preserve historical records when future schedules change. Every module is independently testable.

**App Type**: SPA + API

**API Login**: Yes

**Mode**: NEW

**Deployment Plan**: No deployment plan found

---

## 2. Backend — Azure App Service

| Component | Technology |
|-----------|-----------|
| **Language** | PHP |
| **Framework** | Laravel (stable release compatible with PHP 8.3) |
| **Core Capabilities** | REST API `/api/v1`, Eloquent, Form Requests, Policies, Services/Actions, notifications, scheduler, database-backed queues, migrations |
| **Runtime** | PHP 8.3 |
| **Package Manager** | Composer |
| **Test Runner** | PHPUnit |
| **Mocking Library** | Mockery |
| **Test Command** | php artisan test |
| **Orchestration** | docker-compose |

---

## 3. Frontend — Azure Static Web Apps

| Component | Technology |
|-----------|-----------|
| **Language** | TypeScript |
| **Framework** | React + Vite |
| **Core Libraries** | React Router, TanStack Query, React Hook Form, Zod, Recharts, Lucide React |
| **Styling** | Tailwind CSS |
| **Package Manager** | npm |
| **Test Runner** | vitest |
| **Mocking Library** | vi.mock |
| **Test Command** | npm test |

---

## 4. Services Required

| Azure Service | Role in App | Environment Variable | Default Value (Local) | Classification |
|---------------|------------|---------------------|----------------------|----------------|
| Azure App Service (Linux, PHP 8.3) | Host the Laravel REST API and run its scheduled and queued application work | `APP_URL` | `http://localhost:8000` | Essential |
| Azure Static Web Apps | Host the React single-page application and serve its client-side routes | `VITE_API_BASE_URL` | `http://localhost:8000/api/v1` | Essential |
| Azure Database for MySQL Flexible Server | Persist financial records and transactional audit history; support database-backed queues | `DB_HOST` | `127.0.0.1:3306` | Essential |

SMTP is configurable for queued email notifications; no mail-provider service was selected in the requirements.

---

## 5. Prerequisites

### Run

| Tool | Service(s) | Installed | Version |
|------|------------|-----------|---------|
| PHP | backend | ✅ | 8.3.27 |
| Composer | backend | ✅ | 2.8.12 |
| Node.js | frontend | ✅ | 22.13.0 |
| npm | frontend | ✅ | 10.9.2 |

### Debug

| Tool | Service(s) | Installed | Version |
|------|------------|-----------|---------|
| Docker | database | ❓ | — |
| Docker Compose | database | ✅ | v2.38.1-desktop.1 |
| Chrome | frontend | ✅ | 156.0.8078.48 |

Docker CLI was detected, but the Docker engine did not respond to the readiness check. Verify that Docker Desktop is running before starting the local MySQL container. Double-check any ❓ tools are installed and usable before proceeding.

---

## 6. Design System & UI

**Component Library**: Fluent UI v9
**Style Direction**: A calm, trustworthy personal-finance workspace with clear separation between confirmed balances and projections, compact information hierarchy, restrained teal accents, and soft elevation. Use crisp 8–10px corners and generous whitespace; make money values and due dates easy to scan without decorative gradients beyond the dashboard's single hero. The preview uses the light appearance; provide a coordinated dark appearance through the user's display preference.
**Typography**: Inter, system-ui

### Color Palette

| Token | Hex | Usage |
|-------|-----|-------|
| `primary` | `#1F4B99` | Finance navigation, primary actions, selected report periods |
| `accent`  | `#0F766E` | Received-income indicators, positive cash-flow highlights, and secondary actions |
| `surface` | `#F5F7FB` | Personal finance workspace canvas |
| `text`    | `#182230` | Account names, amounts, labels, and financial details |
| `muted`   | `#637083` | Projection notes, timestamps, categories, and supporting context |
| `border`  | `#D8E0EA` | Ledger row separators, form outlines, and card boundaries |

### Pages

| Page | Route | Purpose | Layout |
|------|-------|---------|--------|
| Dashboard | `/` | Review confirmed balance, projected cash flow, income, commitments, and upcoming due dates | `header, sidebar, main, hero, grid, table, card-list, actions` |
| Income | `/income` | Track forecast and received income and record partial receipts | `header, sidebar, main, actions, tabs, table, form` |
| Commitments | `/commitments` | Manage recurring obligations with independent due dates and payment history | `header, sidebar, main, actions, table, card-list` |
| Debts & Cards | `/debts` | Review loan and card balances, due dates, and repayment progress | `header, sidebar, main, grid, table, actions` |
| Calendar | `/calendar` | Scan income, bills, and payment due dates by week and month | `header, sidebar, main, tabs, grid, card-list` |
| Transactions | `/transactions` | Inspect account movements, partial payments, and reversals in an auditable ledger | `header, sidebar, main, actions, table` |
| Reports | `/reports` | Compare received and forecast cash flow by period and category | `header, sidebar, main, actions, tabs, grid, table` |
| Settings | `/settings` | Configure locale, display preferences, and notification settings | `header, sidebar, main, two-column(form+card-list), actions` |

### Sample Content

Dashboard — monthly finance snapshot:
| Metric / Record | Amount / Date | Detail | Status |
|-----------------|---------------|--------|--------|
| Confirmed balance | RD$ 86,450.00 | Across operating accounts | Actual |
| Expected income | RD$ 124,000.00 | October forecast | Projected |
| Received income | RD$ 82,500.00 | Through Oct 29 | Received |
| Upcoming commitment | RD$ 18,900.00 | Apartment rent · Oct 30 | Due soon |

Income — planned and received income:
| Source | Amount | Expected date | Status |
|--------|--------|---------------|--------|
| Nómina · Grupo Antillano | RD$ 68,000.00 | Oct 30, 2026 | Expected |
| Consultoría · Estudio Norte | RD$ 24,500.00 | Oct 24, 2026 | Partially received |
| Clases de inglés | RD$ 12,000.00 | Oct 15, 2026 | Received |

Commitments — recurring obligations:
| Commitment | Amount due | Due date | Status |
|------------|------------|----------|--------|
| Alquiler · Edificio Coral | RD$ 18,900.00 | Oct 30, 2026 | Due soon |
| Internet · Claro Hogar | RD$ 2,450.00 | Nov 3, 2026 | Scheduled |
| Seguro médico · ARS Universal | RD$ 6,800.00 | Nov 5, 2026 | Scheduled |

Debts & Cards — balances and upcoming payments:
| Account | Outstanding | Minimum / installment | Due date |
|---------|-------------|-----------------------|----------|
| Préstamo vehículo · Asociación Cibao | RD$ 312,400.00 | RD$ 14,250.00 | Nov 8, 2026 |
| Visa Clásica · Banco Popular | RD$ 48,730.50 | RD$ 3,200.00 | Nov 12, 2026 |
| Mastercard Gold · Banreservas | RD$ 21,680.00 | RD$ 2,100.00 | Nov 18, 2026 |

Calendar — October 2026 schedule:
| Event | Date | Amount | Type |
|-------|------|--------|------|
| Clases de inglés received | Oct 15 | RD$ 12,000.00 | Income |
| Consultoría · second installment | Oct 24 | RD$ 10,000.00 | Income |
| Alquiler · Edificio Coral | Oct 30 | RD$ 18,900.00 | Commitment |

Transactions — recent account movements:
| Movement | Account | Amount | Recorded |
|----------|---------|--------|----------|
| Nómina · Grupo Antillano | Cuenta nómina · Popular | +RD$ 68,000.00 | Oct 1, 2026 |
| Pago parcial · Consultoría Estudio Norte | Cuenta corriente · Banreservas | +RD$ 14,500.00 | Oct 24, 2026 |
| Supermercado Nacional | Cuenta corriente · Banreservas | −RD$ 8,245.70 | Oct 26, 2026 |

Reports — October cash flow:
| Category | Forecast | Actual | Variance |
|----------|----------|--------|----------|
| Income | RD$ 124,000.00 | RD$ 82,500.00 | −RD$ 41,500.00 |
| Commitments | RD$ 47,350.00 | RD$ 28,450.00 | −RD$ 18,900.00 |
| Variable spending | RD$ 36,000.00 | RD$ 31,280.40 | −RD$ 4,719.60 |

Settings — display and notifications:
Currency: DOP (RD$) · Time zone: America/Santo_Domingo · Week starts: Monday · Email reminders: 3 days before due date

---

## 7. Project Structure

```
Finance_App/
├── .azure/
│   └── project-plan.md
├── .env.example
├── compose.yaml
├── Backend/                         ← Laravel API and scheduled/queued work
│   ├── artisan
│   ├── composer.json
│   ├── phpunit.xml
│   ├── app/
│   │   ├── Actions/                 ← Transactional financial operations
│   │   ├── Models/
│   │   ├── Notifications/
│   │   ├── Policies/
│   │   └── Services/
│   ├── routes/
│   │   └── api.php
│   ├── database/
│   │   ├── migrations/
│   │   └── factories/
│   └── tests/
│       ├── Feature/
│       └── Unit/
└── Frontend/                        ← All frontend code remains in this folder
    ├── package.json
    ├── vite.config.ts
    ├── tsconfig.json
    └── src/
        ├── api/
        ├── components/
        ├── features/
        │   ├── dashboard/
        │   ├── income/
        │   ├── commitments/
        │   ├── debts/
        │   ├── calendar/
        │   ├── transactions/
        │   ├── reports/
        │   └── settings/
        ├── pages/
        └── test/
```

---

## 8. Route Definitions

| # | Method | Path | Description | Request Body | Response Body | Status Codes |
|---|--------|------|-------------|--------------|---------------|--------------|
| 1 | GET | `/api/health` | Health check | — | `{ status, services }` | 200, 503 |
| 2 | GET | `/api/v1/dashboard/summary` | Confirmed balances, forecast totals, and upcoming due items | — | `{ balances, income, commitments, upcoming }` | 200, 500 |
| 3 | GET | `/api/v1/incomes` | List forecast and received income with date and status filters | — | `{ data, meta }` | 200, 422, 500 |
| 4 | POST | `/api/v1/incomes` | Create an expected income record | `{ source, amount, expected_date }` | `{ data }` | 201, 409, 422 |
| 5 | POST | `/api/v1/incomes/{income}/receipts` | Record a full or partial income receipt | `{ amount, account_id, received_at, idempotency_key }` | `{ data, balance }` | 201, 404, 409, 422 |
| 6 | GET | `/api/v1/commitments` | List recurring commitments and independent due instances | — | `{ commitments, occurrences }` | 200, 500 |
| 7 | POST | `/api/v1/commitments/{commitment}/payments` | Record a full or partial commitment payment | `{ amount, account_id, paid_at, idempotency_key }` | `{ data, remaining_due }` | 201, 404, 409, 422 |
| 8 | POST | `/api/v1/payments/{payment}/reversals` | Reverse a recorded payment while retaining its audit history | `{ reason, idempotency_key }` | `{ data, reversal }` | 201, 404, 409, 422 |
| 9 | GET | `/api/v1/accounts` | List accounts and calculated balances | — | `{ data }` | 200, 500 |
| 10 | GET | `/api/v1/transactions` | Search account movements and payment audit events | — | `{ data, meta }` | 200, 422, 500 |
| 11 | GET | `/api/v1/debts` | List loans and cards with balances and due dates | — | `{ data }` | 200, 500 |
| 12 | GET | `/api/v1/calendar` | Return scheduled income, commitments, and debt due dates for a date range (`from`, `to` query parameters) | — | `{ events }` | 200, 422, 500 |
| 13 | GET | `/api/v1/reports/cash-flow` | Compare forecast and actual cash flow by period and category (`from`, `to`, `group_by` query parameters) | — | `{ totals, series, categories }` | 200, 422, 500 |
| 14 | GET | `/api/v1/settings` | Read locale, display, and notification preferences | — | `{ data }` | 200, 500 |
| 15 | PATCH | `/api/v1/settings` | Update preferences without modifying financial history | `{ currency, timezone, reminders }` | `{ data }` | 200, 422, 500 |

All errors use `{ "error": { "code": "…", "message": "…", "details": null } }`; common codes include `VALIDATION_ERROR` (422), `NOT_FOUND` (404), `CONFLICT` (409), and `INTERNAL_ERROR` (500).

---

## 9. Next Steps

1. Run **azure-project-scaffold** to execute this plan after approval.
2. Run **azure-project-integrate** to wire the frontend to live data, smoke-test the backend, and create the migrations.
3. Run **azure-debug-plan** → **azure-debug-generate** for Docker emulators and VS Code debugging.
4. Run the **azure-deploy** agent when ready; it uses **azure-app-onboard** for architecture, cost estimation, IaC generation, provisioning, and health verification.
