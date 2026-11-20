# Financial Data Flow Architecture — Ghost Finance

This document establishes the mandatory architectural flow for all financial transactions and catalog queries across the Ghost Finance platform.

## Principle of Integration

Every financial operation must complete the following end-to-end path without shortcuts or client-side calculation bypasses:

```
[React Frontend Interface] 
       ↓ (Axios API Client + TypeScript Types)
[Laravel API Request Validation (FormRequests / Controllers)]
       ↓ (Authentication & Data Ownership Check via sanctum)
[Financial Services / Engines (e.g., CreditCardAuthorizationEngine, FinancialProductEngine)]
       ↓ (Atomic Database Transactions & Idempotency Check)
[Database Persistence (MySQL)]
       ↓ (Structured JSON API Response)
[React State Update / TanStack Query Cache Invalidation]
       ↓ (Synchronized Dashboard, Reports, and Account Balances)
```

## Core Rules for Data Integrity

1. **Backend as Source of Truth**: Calculations of interest, commission, available credit, and over-limit thresholds must occur strictly in backend financial engines (`FinancialProductEngine`, `CreditCardAuthorizationEngine`).
2. **Monetary Precision**: Avoid float arithmetic. Currency amounts must be stored as precise decimal/integer cents representation in MySQL and serialized securely.
3. **Atomic Operations**: Financial writes involving accounts, debts, and commitments must run within database transactions (`DB::transaction`).
4. **Immediate React Sync**: State mutations must invalidate relevant query caches to ensure dashboards and reports instantly reflect updated balances.
