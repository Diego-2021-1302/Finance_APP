# Known Issues & Audit Findings — Ghost Finance (Bloque 1 Baseline)

This document tracks known issues, technical debt, and areas requiring attention across the upcoming blocks of the Ghost Finance platform.

## 1. Credit Card & Overdraft Authorization (`CreditCardAuthorizationEngine`)
- **Issue**: Authorizing credit card transactions when limit and overdraft configurations are zero or uninitialized needs strict state handling (unknown limit vs. explicit credit limit).
- **Plan**: Addressed in Bloque 2.

## 2. Financial Catalog Seeder (`FinancialCatalogSeeder`)
- **Issue**: Preloaded tariffs and financial products need strict verification status checking. Products without verifiable official sources must not be marked as verified (`verified = true`).
- **Plan**: Addressed in Bloque 3.

## 3. Tariff Synchronization (`FinancialTariffSyncService`)
- **Issue**: Automated tariff synchronization requires real connector definitions and hash verification rather than mock or auto-generated guesses.
- **Plan**: Addressed in Bloque 4.

## 4. Financial Calculation Engine (`FinancialProductEngine`)
- **Issue**: Potential float-based arithmetic in monetary calculations must be replaced with strict decimal handling.
- **Plan**: Addressed in Bloque 5.

## 5. Frontend-Backend API Coverage
- **Issue**: Several advanced debt, import, and catalog components in React need full integration with newly validated backend endpoints.
- **Plan**: Addressed in Bloque 6.

## 6. Catálogo bancario oficial
- **Estado anterior**: El catálogo local contenía 5 instituciones, 8 productos y 8 variantes.
- **Corrección**: El seeder fue ampliado a familias de productos oficiales por entidad, el API filtra solo productos y variantes verificadas, y el modelo conserva `verification_status`.
- **Limitación**: Las condiciones numéricas no extraídas de un tarifario vigente permanecen como `null` y se muestran como desconocidas. No se convierten en cero ni se presentan como datos oficiales confirmados.
