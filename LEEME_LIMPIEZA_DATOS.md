# Limpieza de datos operativos

Se eliminaron los registros operativos de ingresos, gastos y pagos, incluyendo compromisos, ocurrencias, deudas, estados de tarjeta, movimientos de deuda e historial mensual relacionado.

## Conservado

- 2 usuarios.
- 4 cuentas.
- 8 depósitos de alcancía.
- Reservas, presupuestos, preferencias y transferencias existentes.
- Los 4 movimientos restantes son aperturas originales de las cuentas.

## Resultado

- Ingresos: 0.
- Gastos: 0.
- Pagos: 0.
- Compromisos: 0.
- Ocurrencias: 0.
- Deudas: 0.
- Estados de tarjeta: 0.
- Movimientos de deuda: 0.

## Comando

```powershell
php artisan finance:clean-operational-data
php artisan finance:clean-operational-data --force
```

La primera ejecución solo muestra el alcance. La segunda ejecuta una transacción segura y requiere confirmar manualmente que la limpieza es deseada.
