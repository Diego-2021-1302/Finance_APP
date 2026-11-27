# Resultados de pruebas

## Backend

- Suite completa: 86 pruebas aprobadas, 393 aserciones.
- Pruebas financieras nuevas: escenario de saldo RD$81.28, ingreso futuro y pagos RD$1,700 + RD$1,350 aprobado.
- Prueba de deuda autónoma sin commitment_id aprobada.
- Estado: una prueba marcada como deprecated por PHPUnit, sin fallo funcional asociado.

## Frontend

- `npm run build`: aprobado después del rediseño de Inicio.
- `npm run test`: 2 archivos aprobados, 10 pruebas aprobadas.
- Navegación, contratos TypeScript y tema compilados sin errores.
- Pagos compilado y probado a 375 px; se confirmó que “Todos” no aparece y Servicios es la vista inicial.; se verificó Archivar sin Pausar/Reanudar.; “Todos” no muestra Nuevo/Agregar, y Préstamos conserva “Agregar préstamo”.; Nuevo se verificó como expansión in-page y Editar como reemplazo dentro de la tarjeta. sin desborde horizontal; se confirmó que “Saldos independientes” fue reemplazado por “Pagos prioritarios”.; Tarjetas, Préstamos y Servicios se verificaron con datos reales.; el selector de tipo y las métricas se verificaron con datos reales de sesión.; las pestañas permanecen desplazables y los filtros se apilan verticalmente.

## Navegador

- Escritorio: HTTP 200, captura cargada, cero errores de consola y cero solicitudes fallidas.
- Mobile 390 px: scrollWidth = 390, sin desborde horizontal.
- La pantalla disponible en QA fue acceso/login porque no se proporcionaron credenciales de prueba.

## Operación

- Routes API disponibles y autenticadas según el router existente.
- Scheduler incluye generación de ingresos recurrentes.
- Migración de recurrencia pendiente: no fue aplicada sobre datos reales.

## Limitaciones

- No se realizó QA autenticado del Dashboard porque no se usaron credenciales reales.
- No se realizaron mediciones reales de Core Web Vitals en producción.
- No se aplicó la migración sobre la base real.
