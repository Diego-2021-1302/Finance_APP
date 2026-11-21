# INFORME DE PRUEBAS AUTOMATIZADAS — FINANCE APP 2.0

**Fecha de Ejecución:** 20 de Noviembre, 2026
**Resultado Global:** PASS para las suites ejecutadas

## Ejecución final del plan Compromisos

- Backend: **87 pruebas aprobadas, 446 aserciones** mediante `php artisan test`.
- Frontend: **10 pruebas aprobadas** mediante `npm test`.
- Producción: build TypeScript/Vite completado mediante `npm run build`.
- Recorrido maestro: **35 escenarios cubiertos por 1 prueba con 12 aserciones** mediante `php artisan test --filter=GhostFinanceMasterIntegrationTest`.
- Migraciones: todas las migraciones registradas están aplicadas; no hay pendientes.
- Diagnósticos: sin errores en las rutas, aplicación backend y archivos frontend revisados.

No se declara aprobado un recorrido Playwright o una verificación manual de navegador, pues no fue ejecutado en esta sesión.

---

## 1. PRUEBAS DE BACKEND (PHPUnit)

- **Total de pruebas backend:** 87 pasadas.
- **Total de aserciones:** 446 verificadas.
- **Tiempo de ejecución:** aproximadamente 39 segundos.

### Cobertura destacada:
- Cuentas, ingresos, gastos y transferencias.
- Compromisos, recurrencias y pagos parciales.
- Tarjetas, sobregiros, estados de cuenta y capacidad autorizada.
- Préstamos y amortización contractual.
- Catálogo financiero y sincronización de tarifas.
- Reversiones e idempotencia.
- Calendario, dashboard y reportes.
- Aislamiento entre usuarios y seguridad.
- Módulo Alcancía.

## 2. PRUEBAS DE FRONTEND (Vitest)

- **Total de pruebas frontend:** 10 pasadas.
- **Tiempo de ejecución:** aproximadamente 0.5 segundos.

### Cobertura destacada:
- Persistencia de sesión y API autenticada.
- Cliente HTTP y manejo de respuestas inválidas.
- Historial de deudas y análisis financiero.
- Mensajería del asistente.
- Pausa y reanudación de compromisos.
- Límites desconocidos enviados como `null`.
- Catálogo bancario ampliado y clasificación de productos/variantes verificadas.

---
