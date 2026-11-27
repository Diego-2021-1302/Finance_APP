# Cambios de Ghost Finance

## Cambios implementados

- Inicio usa el Dashboard integrado con el motor financiero, no el Dashboard simple con cálculos independientes.
- `/income` quedó activa como módulo funcional con registro y confirmación de recepciones.
- `/payments` quedó activa y las rutas heredadas de compromisos, deudas y calendario apuntan a ella.
- La navegación principal quedó en Inicio, Ingresos, Gastos, Pagos y Cuentas; Ahorros permanece secundario.
- El planificador expone el mínimo proyectado y el dinero libre según el horizonte.
- Eliminar un ingreso recibido revierte sus recepciones y conserva el historial contable.
- Se añadió recurrencia de ingresos y el comando `finance:generate-income-occurrences` en el Scheduler.
- Las tarjetas pueden crearse con datos desconocidos sin presentar límites o mínimos como confirmados.

## Datos y actualización

No se eliminaron usuarios, ahorros, cuentas, movimientos históricos ni información financiera. No se ejecutaron `migrate:fresh`, seeders destructivos ni limpieza de datos. La nueva migración quedó pendiente y no fue aplicada sobre datos reales.

Extrae el ZIP sobre la raíz del proyecto para aplicar los archivos incluidos. No se requieren dependencias adicionales. Para verificar la aplicación:

```powershell
npm run build --prefix Frontend
php Backend/vendor/bin/phpunit Backend/tests/Unit/CashFlowProjectionTest.php
```

La prueba unitaria de proyección es aislada y no requiere acceso a la base de datos. No ejecutar pruebas de integración que escriban en una base compartida sin configurar previamente una base de pruebas desechable.

## Alcance pendiente del plan maestro

Este paquete no representa la implementación completa de todos los bloques del plan: la generación automática de recurrencias, flujos unificados de pago, recordatorios, asignación de ingresos a compromisos y escenarios de concurrencia requieren implementación y validación adicionales. No se deben tratar como funcionalidades completadas.