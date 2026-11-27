# Ghost Finance - Actualización final

## Correcciones implementadas

- El Dashboard utiliza el plan financiero central y ya no suma créditos como efectivo ni convierte textos monetarios para cálculos.
- Se creó `FinancialPlanningService` como coordinador financiero reutilizable.
- El endpoint `/api/v1/financial-plan` incluye datos de completitud, fuentes de fondos, asignaciones proyectadas y cobertura.
- Las deudas sin compromiso pueden incluirse mediante una representación normalizada cuando tienen cuota y vencimiento suficientes.
- Los ingresos admiten recurrencia única, semanal, cada 14 días, semimensual y mensual.
- `/payments` utiliza el formulario completo de préstamos, tarjetas y compromisos.
- `/transfers` y `/transactions` muestran las páginas funcionales existentes.
- Ajustes y Reportes dejaron de ser redirecciones sin funcionalidad.
- Se añadió tema claro persistente, tema oscuro y modo según el sistema.
- El Dashboard fue reestructurado con una jerarquía clara: dinero disponible protagonista, acciones rápidas visibles, próxima información financiera, próximos pagos y resumen secundario.
- Inicio usa cuadrículas compactas y consistentes, evita tarjetas redundantes y adapta la disposición a escritorio, tablet y móvil.
- Inicio mantiene una mezcla oscura suave y sombría: lienzo carbón, tarjetas gris oscuro, verde apagado para disponibilidad y bordes discretos sin neón.
- La superficie de Inicio usa un lienzo claro, panel financiero verde, tarjetas blancas discretas y acciones con iconos.
- El formato DOP usa RD$ de forma consistente en Inicio y componentes financieros compartidos.
- Pagos utiliza una cabecera operativa, pestañas segmentadas, métricas compactas, filtros organizados y tarjetas de obligaciones con información jerarquizada.
- El resumen de Pagos consume el plan financiero central: 30 días, vencido, pagado del mes y deuda total quedan diferenciados.
- Cada obligación muestra cobertura con dinero actual, ingreso previsto, parcial o sin cobertura.
- El formulario nuevo empieza por el tipo de pago y solicita campos indispensables antes de opciones avanzadas.
- Los pagos mantienen una clave de idempotencia estable mientras se reintenta la misma operación.
- Cada compromiso real muestra acciones directas de Editar, Archivar y Eliminar.
- Archivar reemplaza a Pausar/Reanudar. Solo permite archivar cuando no existen ocurrencias pendientes ni saldo/deuda vinculada.
- Agregar expande el formulario dentro de la página de Pagos.
- Editar reemplaza temporalmente el contenido de la tarjeta del compromiso con el formulario, sin abrir un modal. Eliminar conserva trazabilidad archivando el compromiso y sus futuras ocurrencias no pagadas.
- El apartado “Pagos prioritarios” fue eliminado de Pagos; los compromisos mantienen una única lista organizada por filtros.
- La pestaña visible “Todos” fue retirada; la vista inicial de Pagos corresponde a Servicios y facturas.
- Las tarjetas y préstamos se presentan ordenados por fecha efectiva de vencimiento, con pago exigible y deuda total visibles.
- Los filtros Tarjetas, Préstamos y Servicios incluyen registros heredados y deudas autónomas, sin dejar pestañas vacías por diferencias de tipo antiguas.
- Los detalles avanzados de préstamos, tarjetas e historial permanecen bajo divulgaciones para evitar saturar la vista principal.

## Integridad financiera

- Los importes de cálculo se representan como cadenas decimales y se procesan con centavos/BCMath.
- Los ingresos futuros no incrementan el saldo real.
- Las tarjetas no se suman a liquidez.
- Las asignaciones respetan la disponibilidad antes del vencimiento.
- Las operaciones recibidas conservan trazabilidad y idempotencia.

## Actualización

1. Respaldar la base de datos.
2. Extraer el ZIP sobre la raíz existente.
3. Ejecutar `php artisan migrate` en un entorno respaldado si la migración de recurrencia aún está pendiente.
4. Ejecutar `php artisan schedule:list` y confirmar el Scheduler.
5. Ejecutar `npm run build` en Frontend.

No se ejecutaron migraciones destructivas ni cambios sobre datos reales durante esta actualización.
