# Integration hand-off

## Run / build

- Backend: `Backend/`; run from that folder with `php artisan serve --host=0.0.0.0 --port=8021`; port `8021`; health `GET /api/health`; build/runtime check `composer install --no-interaction --prefer-dist`, `php artisan route:list --path=api`; tests `php artisan test`.
- Frontend: `Frontend/`; install `npm install`; dev `npm run dev` (Vite config binds to `0.0.0.0:5180`); build `npm run build`; test `npm test`.
- Compose: root `compose.yaml` starts MySQL 8.4, API, database queue worker, and Laravel scheduler. Docker engine was flagged as not ready during planning; no containers were started.

## Frontend integration

- The application uses the live typed API client in `Frontend/src/api/`; feature pages are connected to Laravel endpoints for accounts, income, commitments, debts, calendar, transactions, reports, and settings.
- API contracts are defined in `Frontend/src/api/types.ts`, with response mapping to local view types in `Frontend/src/api/client.ts`.
- Auth UI/state: `Frontend/src/auth/AuthContext.tsx`, `Frontend/src/pages/AuthPages.tsx`. A random device identifier and its Sanctum bearer token are saved in browser local storage so the same browser profile can restore the session after reload; explicit sign-out revokes the token.

## API route inventory

All `/api/v1` feature routes require `auth:sanctum`; auth registration/login and health are public.

| Method | Path |
|---|---|
| GET | `/api/health` |
| POST | `/api/v1/auth/register` |
| POST | `/api/v1/auth/login` |
| GET | `/api/v1/auth/me` |
| POST | `/api/v1/auth/logout` |
| GET | `/api/v1/dashboard/summary` |
| GET | `/api/v1/incomes` |
| POST | `/api/v1/incomes` |
| POST | `/api/v1/incomes/{income}/receipts` |
| GET | `/api/v1/commitments` |
| POST | `/api/v1/commitments` |
| POST | `/api/v1/commitments/{commitment}/occurrences/{occurrence}/payments` |
| POST | `/api/v1/payments/{payment}/reversals` |
| GET | `/api/v1/accounts` |
| POST | `/api/v1/accounts` |
| GET | `/api/v1/transactions` |
| GET | `/api/v1/debts` |
| POST | `/api/v1/debts` |
| GET | `/api/v1/debts/history` |
| POST | `/api/v1/debts/{debt}/increases` |
| POST | `/api/v1/debts/{debt}/payments` |
| POST | `/api/v1/insights/recommendations` |
| GET | `/api/v1/calendar?from=YYYY-MM-DD&to=YYYY-MM-DD` |
| GET | `/api/v1/reports/cash-flow?from=YYYY-MM-DD&to=YYYY-MM-DD&group_by=month` |
| GET | `/api/v1/settings` |
| PATCH | `/api/v1/settings` |
| GET | `/api/openapi.yaml` |

Auth implementation: Laravel Sanctum bearer tokens; controller `Backend/app/Http/Controllers/Api/V1/AuthController.php`; middleware `auth:sanctum`; user token trait in `Backend/app/Models/User.php`; config `Backend/config/sanctum.php`. Login tokens and a generated device identifier are stored in browser local storage. Tokens do not expire automatically, remain separate per browser profile/device, and are revoked by explicit logout; signing in again replaces that device's prior token. Sanctum stores hashed personal-access tokens; no JWT signing secret is used. `APP_KEY` remains required for Laravel encryption.

## Database / migrations

- Database: MySQL 8.4 (plan: Azure Database for MySQL Flexible Server).
- Migration tool: Laravel Eloquent migrations (`php artisan migrate`); directory `Backend/database/migrations/`.
- Connection vars: `DB_CONNECTION=mysql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.
- Queue/cache/session tables are expected for the configured database-backed queue/cache/session drivers.
- Seven schema migrations cover users and Sanctum tokens; accounts; incomes; typed commitments and independent commitment occurrences; loan/card amortization tracking; payments and reversals; transactions; debts; user preferences; debt-balance movements; account-opening ledger transactions; and Laravel database queue/cache/session tables. Debt history starts with a migration-time baseline for existing balances and records future opening balances, increases, and payments. The schema migrations add no seed data; a separate local demo account and sample records were created on request and are not part of the repository.
- Commitment records include a type (loan, credit card, utility, subscription, insurance, rent, or other) and type-specific provider/reference, balance, interest, category, maturity, and notes fields. Existing records default safely to `other`. Loan/card payments accrue period interest against the balance before applying the payment; reversing a payment recalculates the amortized balance.
- Migration reference `.github/agents/azure-debug-plan/references/migrations.md` was reviewed; Laravel's built-in Eloquent migration system is explicitly selected in the plan/dependencies.

## Services

- Essential: Azure App Service Linux PHP 8.3 (Laravel API); Azure Static Web Apps (React SPA); Azure Database for MySQL Flexible Server.
- Enhancement: SMTP is configurable for queued reminder mail; no mail-provider service was selected. No other Enhancement Azure service or Azure SDK client was selected.
- Scheduled reminder: `php artisan finance:send-due-reminders` at 08:00 America/Santo_Domingo; queued mail uses the database queue.
- Gemini financial insights use Laravel's HTTP client and `GEMINI_API_KEY` / `GEMINI_MODEL` in `Backend/.env`; transient overload falls back to `GEMINI_FALLBACK_MODELS` (default `gemini-3.5-flash`). Never place the key in frontend code. The dashboard discloses the financial fields sent to Google and requires explicit per-analysis consent. User identity, account identifiers, and card numbers are excluded.

## No seed data

Create schema migrations only. **Do not create seed data.**

## Integration results — 2026-10-30

**Status: integrated locally; not deployed.** The existing `fgp` database contained tables and data from another application, so it was left untouched. A separate `FinanceApp` database was created using the supplied local MySQL connection settings, and all five Laravel migrations applied successfully. Debt history was baselined from existing balances; temporary API-test users were removed and no seed/demo data remains.

The finance API supports debt creation, idempotent balance increases and payments, and per-user month-end cumulative balance history. Live API checks covered debt idempotency, account opening balances, income deposits, commitment payments, transaction filters, calendar projections, and annual cash flow; temporary test data was removed. Payment can optionally record a matching negative transaction against an owned account. Missing Gemini consent is rejected before any external request.

Frontend wiring uses the typed live API client with a browser-persisted bearer token; the mock/preview API layer is removed. Login is restored after reload or browser restart, and explicit logout revokes the current device token. Income supports forecasts and partial receipts; commitments create recurring due occurrences and record account payments; accounts record opening balances; the calendar and reports follow the selected month; transactions support account/date/text filters; settings persist timezone, week start, reminder window, and appearance. Debts supports new balances, increases, and payments. The dashboard includes opt-in Gemini recommendations and monthly debt history. No Azure resources were deployed.
