# Finanzas Clara API

Laravel 12 / PHP 8.3 REST API for the approved personal-finance plan. The API prefix is `/api/v1`; database schema and all migrations are intentionally left for the integration phase.

## Local setup

1. Copy `.env.example` to `.env`, set MySQL credentials, then run `composer install`.
2. Set `APP_KEY` with `php artisan key:generate`.
3. Create/apply schema migrations during integration: `php artisan migrate`.
4. Start the API: `php artisan serve --host=0.0.0.0 --port=8000`.
5. Start queued notifications with `php artisan queue:work`; schedule reminders with `php artisan schedule:work`.

The app expects MySQL 8+, `pdo_mysql`, and `bcmath`. The queue, cache, and sessions use the database in the planned runtime. No financial fixtures or seed data are included.

## Security and integrity

- Personal records are queried through the authenticated user's ID; bearer tokens use Laravel Sanctum and expire after the configured lifetime.
- Amounts are persisted as decimal strings and arithmetic uses BCMath (no binary floating-point operations).
- Receipts, commitment payments, and reversals are transactional and use idempotency keys; migrations must enforce unique keys and owner-scoped foreign keys.
- Cards store only safe metadata such as `last_four`; do not add full PAN or CVV fields.
- The integration phase must create schema migrations for `users`, Sanctum `personal_access_tokens`, `accounts`, `incomes`, `commitments`, `commitment_occurrences`, `payments`, `transactions`, `debts`, `user_preferences`, and Laravel database queue/cache/session tables as needed. Include unique indexes for idempotency keys and foreign-key ownership constraints. Create **no seed data**.

Run backend validation with `php artisan test` and inspect routes with `php artisan route:list --path=api`.
