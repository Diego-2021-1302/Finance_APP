# Finance App 2.0 — Gestión Financiera Personal Confiable

Finance App 2.0 es una plataforma de finanzas personales completa, precisa y lista para producción. Diseñada con las **Cuentas Financieras (Ahorros, Corriente, Efectivo)** como la fuente única de verdad contable para todos los movimientos de dinero.

## Características Principales

- **Cuentas Financieras como Eje Central**: Cada movimiento (ingreso, gasto, pago de compromiso o deuda) está vinculado a una cuenta real.
- **Gastos Directos Ocasionales**: Registro inmediato de consumos sin requerir compromisos recurrentes previos.
- **Transferencias Internas Atómicas**: Mueve fondos entre tus cuentas de forma atómica manteniendo intacto el patrimonio neto total.
- **Compromisos Recurrentes y Facturas Variables**: Programación automática de vencimientos (Scheduler) con soporte de fechas especiales de fin de mes (ej. 31 ene -> 28 feb -> 31 mar).
- **Préstamos y Tarjetas de Crédito**: Gestión de deudas separando el pago mínimo recomendado del saldo total adeudado.
- **Asistente Financiero IA Interactiva**: Asesor personal que entiende lenguaje natural en español (RD$), extrae fechas relativas (*"ayer"*, *"hoy"*), ofrece opciones mediante botones clicables e interactivos y solicita confirmación previa al guardado.
- **Diseño Glassmorphism y Totalmente Responsivo**: Interfaz moderna adaptable a teléfonos móviles, tablets y escritorios en modo claro y oscuro.

## Stack Tecnológico

- **Frontend**: React + Vite + TypeScript + Tailwind CSS.
- **Backend**: Laravel (API REST).
- **Base de Datos**: MySQL (con precisión decimal estricta y BCMath en PHP).
- **Autenticación**: Laravel Sanctum.
- **Automatización**: Laravel Scheduler, Notifications y Queues.
- **Moneda Principal**: Pesos Dominicanos (DOP / RD$).
- **Zona Horaria**: America/Santo_Domingo.

## Estructura del Proyecto

- `Backend/`: API REST en Laravel.
- `Frontend/`: Aplicación SPA en React con TypeScript y Tailwind.
- `AUDIT.md`: Auditoría integral de arquitectura y hallazgos.
- `implementation_plan.artifact.md`: Plan de fases y ejecución técnica.
- `CHANGELOG.md`: Registro histórico de versiones y cambios.
- `TEST_REPORT.md`: Informe de pruebas automatizadas (19 Backend + 6 Frontend PASS).
- `DEPLOYMENT.md`: Guía paso a paso para despliegue en producción.
