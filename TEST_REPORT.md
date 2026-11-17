# INFORME DE PRUEBAS AUTOMATIZADAS — FINANCE APP 2.0

**Fecha de Ejecución:** 17 de Noviembre, 2026  
**Resultado Global:** PASS (100% Exitoso)  

---

## 1. PRUEBAS DE BACKEND (PHPUnit / Pest)

- **Total de pruebas backend:** 19 pasadas
- **Total de aserciones:** 60 verificadas
- **Tiempo de ejecución:** ~14.0 segundos

### Cobertura de Pruebas Destacadas:
1. `AccountMovementTest::test_user_can_create_savings_checking_and_cash_accounts`: Verificación de creación de cuentas de ahorro, corriente y efectivo con saldos iniciales en el libro mayor.
2. `AccountMovementTest::test_user_can_record_direct_expense`: Registro de gasto ocasional afectando la cuenta correspondiente.
3. `AccountMovementTest::test_internal_transfer_does_not_alter_total_user_wealth`: Comprobación de que una transferencia interna de $3,000 entre cuentas del mismo usuario no altera el patrimonio neto total.
4. `RecurrenceDateTest::test_recurrence_preserves_original_base_day_across_short_months`: Comprobación del algoritmo de fechas conservando el día 31 a través de febrero y marzo.
5. `ChatTest::test_authenticated_user_can_get_and_send_chat_messages`: Pruebas de mensajería con el Asistente IA.
6. `ChatTest::test_ai_interactive_form_filling_and_confirmation_flow_for_income`: Pruebas del flujo interactivo de recolección de datos y confirmación pre-guardado.
7. `ChatTest::test_ai_provides_personalized_financial_opinion_when_asked`: Verificación de diagnóstico financiero personalizado basado en datos reales del usuario.
8. `FinancialFlowTest::test_user_can_register_and_create_account_and_income_receipt`: Flujo completo de autenticación, creación de cuenta y cobro de ingreso.
9. `FinancialFlowTest::test_commitment_recurrence_generation_and_partial_payment`: Generación de recurrencias y abonos parciales a compromisos.

---

## 2. PRUEBAS DE FRONTEND (Vitest)

- **Total de pruebas frontend:** 6 pasadas
- **Tiempo de ejecución:** ~1.3 segundos

### Cobertura de Pruebas:
- `client.test.ts`: Persistencia de tokens de sesión Sanctum, llamadas a API de ingresos, historial de deudas, análisis Gemini y mensajería de chat.
- `uuid.test.ts`: Generación de UUID v4 criptográficamente seguro para idempotencia sin dependencias externas.

---
