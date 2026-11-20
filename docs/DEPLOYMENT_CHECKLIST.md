# Deployment Checklist — Ghost Finance

## Backend (Laravel)
- [x] PHP >= 8.2 with `bcmath`, `mbstring`, `openssl`, `pdo_mysql` extensions enabled.
- [x] Environment configuration (`.env`) with production database credentials, APP_KEY, and SANCTUM_STATEFUL_DOMAINS.
- [x] Database migrations executed successfully (`php artisan migrate --force`).
- [x] Financial catalog seeder executed (`php artisan db:seed --class=FinancialCatalogSeeder`).
- [x] Tariff synchronization scheduled (`php artisan schedule:run`).
- [x] Optimization caches generated (`php artisan config:cache`, `php artisan route:cache`, `php artisan view:cache`).

## Frontend (React / TypeScript / Vite)
- [x] Production build executed (`npm run build`).
- [x] Environment variables configured (`VITE_API_BASE_URL`).
- [x] Static assets correctly generated in `dist/`.
- [x] PWA offline capabilities and service worker verified.

## Security & Compliance
- [x] Sanctum cookie-based and token authentication verified.
- [x] User data isolation and authorization policies verified.
- [x] Idempotency keys enforced on financial write operations.
