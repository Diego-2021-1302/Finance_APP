# Resultados de pruebas financieras

## Backend

Comando: `php artisan test`

Resultado final:

- 80 pruebas aprobadas.
- 359 aserciones aprobadas.
- Duración: 172.23 segundos.

## Frontend

- `npm test`: 2 archivos aprobados, 8 pruebas aprobadas.
- `npm run build`: aprobado.

## Build y migraciones

- Build Vite/TypeScript aprobado.
- No se aplicaron migraciones pendientes.
- Pendientes: piggy bank deposits y evidencia ampliada de sync tarifario.

## Cobertura no ejecutada

- E2E con navegador.
- Flujo completo manual con banco externo real.
- Lint frontend, porque no existe script `lint` en `package.json`.
