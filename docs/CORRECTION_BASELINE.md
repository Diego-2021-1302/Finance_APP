# Baseline de Corrección — Ghost Finance

## Alcance

- Rama de trabajo: `correction/ghost-finance-block1`
- Fecha de inspección: 20 de noviembre de 2026
- Rama base observada: `main`
- Commit observado: `ad935fb Actualizacion 19/11/2026 4:30`

## Estado inicial del repositorio

El repositorio ya contenía modificaciones locales al iniciar la auditoría. Se creó una rama separada y no se revirtieron, descartaron ni sobrescribieron esos cambios.

Cambios locales principales al inicio:

- Controladores y acciones de compromisos, pagos y cuentas.
- Servicio de transacciones.
- Migraciones y servicios del catálogo financiero y sincronización tarifaria.
- Pruebas financieras nuevas.
- Componentes React de capacidad, confirmación de tarjeta y catálogo.
- Actualizaciones de tipos API y progreso de implementación.
- Archivo de plan anterior y archivo del plan maestro actual.

La modificación de `Backend/cloudflared.log` no debe considerarse fuente funcional ni se utilizará como criterio de corrección.

## Ejecución de pruebas existentes

### Backend

- Comando: `php artisan test`
- Resultado: 68 pruebas aprobadas, 317 aserciones.
- Duración registrada: 59.08 segundos.
- Las pruebas existentes no eliminan ni reemplazan la necesidad de cubrir los nuevos escenarios solicitados.

### Frontend

- Comando: `npm test`
- Resultado: 2 archivos aprobados, 8 pruebas aprobadas.
- Comando: `npm run build`
- Resultado: build de producción aprobado.
- Advertencias: comentarios `@__PURE__` de `zod/v4/core` reportados por esbuild/Rollup, sin fallo de compilación.

## Migraciones

Las migraciones de identidad, finanzas y catálogo ya aplicadas hasta el lote 7 están registradas como ejecutadas.

Migraciones pendientes:

- `2026_11_30_000007_add_limit_status_to_financial_entities`
- `2026_11_30_000008_add_verification_status_to_catalog`

No se ejecutaron migraciones ni se modificaron datos históricos durante el bloque 1.

## Problemas confirmados

### P0 — autorización insegura de tarjetas

Ubicación principal: `Backend/app/Services/CreditCardAuthorizationEngine.php`.

Problemas:

1. La rama `special_rules` devuelve `authorized => true` sin aplicar verificaciones ordinarias ni exponer una regla especial documentada.
2. Cuando no existe límite configurado, la rama autoriza siempre con capacidad ilimitada implícita.
3. El estado `limit_status` usa `confirmed` como respaldo. Esto puede convertir una tarjeta antigua o incompleta en confirmada sin evidencia.
4. `pending_verification` no está protegido explícitamente: queda fuera de los estados reconocidos y puede seguir el flujo ordinario.
5. El estado seguro por defecto para datos históricos debe verificarse antes de declarar una tarjeta válida.

Cobertura actual: el motor rechaza `unknown`, pero no cubre el límite cero confirmado, `pending_verification` ni reglas especiales seguras.

### P0 — datos de límites incompletos o inconsistente

`2026_11_30_000007_add_limit_status_to_financial_entities.php` crea el estado con respaldo `confirmed`, lo que puede marcar información existente como confirmada aunque no exista evidencia.

`2026_11_29_000003_add_overdraft_and_fee_fields_to_cards.php` vuelve migración y backfill de límites sin cambiar de forma segura el estado de verificación de esos valores.

La configuración se replica entre `Commitment`, `Debt` y `Account`, por lo que el bloque 2 debe definir una fuente de verdad y mantener sincronización sin contradicciones.

### P0 — motor amortizable usa valores float

Ubicación: `Backend/app/Services/FinancialProductEngine.php`, método `calculateFrenchAmortization`.

Aunque el cálculo principal usa `bcmath`, convierte tasa y principal a `float`, ejecuta `pow` y calcula el pago con operaciones flotantes. Esto contradice la regla de no usar `float` en cálculos monetarios críticos.

### P1 — formato monetario en motor de autorización

`CreditCardAuthorizationEngine.php` usa `number_format((float) ...)` únicamente para mensajes. El cálculo se mantiene en `bcmath`, pero el formato no debe convertirse desde decimal cuando el sistema dispone del valor exacto.

### P1 — componentes React críticos sin consumidores

Los siguientes componentes existen, pero no tienen importaciones desde otras vistas:

- `Frontend/src/components/CardCapacityPanel.tsx`
- `Frontend/src/components/CreditCardPaymentConfirmation.tsx`
- `Frontend/src/components/FinancialCatalogSelector.tsx`

Esto significa que no forman parte aún del flujo funcional completo.

### P1 — contratos y API incompletos

La matriz existente marca varias rutas como pendientes y el catálogo puede consultarse, pero no está integrado funcionalmente en las vistas existentes.

APIs sin consumidor React confirmado:

- `/api/v1/catalog/institutions`
- `/api/v1/catalog/categories`
- `/api/v1/catalog/products`
- `/api/v1/catalog/variants`
- `/api/v1/catalog/link`
- `/api/v1/catalog/tariffs`
- `/api/v1/catalog/sync-logs`

Rutas relacionadas con tarjetas que sí están registradas, pero deben ser integradas y auditadas en bloques posteriores:

- `/api/v1/debts`
- `/api/v1/debts/{debt}/increases`
- `/api/v1/debts/{debt}/payments`
- `/api/v1/debts/{debt}/minimum-payment`
- `/api/v1/debts/{debt}/statements`

### P1 — cálculos float fuera del motor financiero

Se encontraron usos de `float` en servicios auxiliares e inteligencia conversacional, incluidos:

- `ChatAssistantService.php`
- `TariffValidationService.php`
- `TariffNormalizationService.php`

Antes de declarar el sistema monetario íntegro, estos usos deben clasificarse entre presentación, parsing de lenguaje natural y cálculo financiero real.

## Dependencias entre módulos

- `CommitmentController` crea y sincroniza `Commitment`, `Account` y `Debt` para tarjetas de crédito.
- `TransactionService` consume la entidad asociada y delega autorizaciones al motor de tarjetas.
- `RecordCommitmentPayment` tiene validaciones de crédito propias además del motor.
- `DebtController` mantiene saldos, mínimos y estados históricos.
- `FinancialProductEngine` consulta variantes y tarifarios oficiales.
- `FinancialTariffSyncService` produce registros versionados usados por el motor contractual.
- Las vistas React consumen principalmente endpoints generales; los componentes especializados todavía no están conectados.

## Matriz de problemas y prioridad

| Prioridad | Problema | Módulos afectados | Acción siguiente |
|---|---|---|---|
| P0 | Autorización positiva con `special_rules` | Authorization Engine, Expense/Payment APIs | Bloque 2 |
| P0 | Estado cero sin configuración autorizado implícitamente | Authorization Engine, modelos de tarjeta | Bloque 2 |
| P0 | `pending_verification` no bloqueado explícitamente | Authorization Engine, migración de estados | Bloque 2 |
| P0 | Cálculo francés con `float` y `pow` | FinancialProductEngine | Bloque 5 o corrección transversal prioritaria |
| P1 | Tres componentes React no utilizados | Frontend | Bloque 2 y bloques posteriores |
| P1 | Consumo React del catálogo incompleto | API client, componentes, páginas de compromisos | Bloque 3/6 |
| P1 | Migraciones nuevas pendientes | Base de datos | Evaluar en el bloque correspondiente sin migración destructiva |
| P1 | Duplicación de datos de límites entre entidades | Commitment/Account/Debt | Bloque 2 |
| P1 | Uso de float en asistentes y tarifarios | Chat/Tariff Services | Clasificar y corregir cálculos reales |
| P2 | Advertencias de build no bloqueantes | Dependencias Zod/esbuild | Documentar; no intervenir sin necesidad |

## Limitaciones del bloque 1

- No se evaluó ni aprobó autenticidad bancaria de tasas sin documentos oficiales verificables.
- No se ejecutaron migraciones pendientes.
- No se modificaron datos financieros históricos.
- No se declaró que componentes existentes estén integrados solo por existir o tener pruebas unitarias.
- Las migraciones con defaults de verificación requieren una decisión posterior antes de aplicarse a datos reales.

## Criterio de salida del bloque 1

El bloque 1 queda registrado como auditoría inicial completa. No se modificó el comportamiento financiero en esta etapa y no se avanzó a la corrección de tarjetas.

## Resultado del Bloque 2

### Cambios backend

- El estado seguro predeterminado de límites es `unknown`.
- `unknown` y `pending_verification` bloquean nuevas autorizaciones.
- `confirmed` con límite aprobado y sobregiro en cero no otorga crédito ilimitado.
- `special_rules` exige que existan reglas documentadas, una fuente contractual y una referencia.
- Las reglas especiales no autorizan automáticamente; continúan verificando la capacidad ordinaria.
- Se agregó el endpoint `PATCH /api/v1/debts/{debt}/card-limits`.
- La configuración de límites se sincroniza entre `Debt`, `Account` y `Commitment`.
- La actualización usa transacciones de base de datos y bloqueo pesimista.

### Cambios frontend

- El formulario de tarjetas permite capturar límite aprobado, sobregiro autorizado y estado de límite.
- Las tarjetas desconocidas o pendientes muestran una advertencia antes de operar.
- La vista muestra `CardCapacityPanel` con los datos devueltos por el backend.
- El frontend ya no calcula autorización ni estado de límite.
- Las tarjetas pueden reconfigurarse desde el flujo de edición y reintentar la operación mediante el endpoint API.

### Migraciones

Se dejó una migración conservadora adicional para documentación de reglas especiales:

- `2026_11_30_000009_add_card_special_rules`

No se ejecutó la migración en la base real ni se modificaron datos históricos.

### Pruebas ejecutadas

- 15 pruebas relacionadas con tarjetas aprobadas.
- 64 aserciones aprobadas.
- Frontend: 8 pruebas aprobadas.
- Frontend: build de producción aprobado.

Cobertura incluida:

- Límite desconocido.
- Límite confirmado cero.
- Límite pendiente.
- Reglas especiales sin documentación.
- Reglas especiales documentadas con capacidad insuficiente.
- Confirmación posterior de límites y reintento.
- Compatibilidad con tarjetas existentes y pagos.

## Resultado del Bloque 3

### Cambios backend

- Se agregó `credit_card_fee_rules` para reglas verificables con institución, producto, variante, activación, fórmula, vigencia, fuente y estado.
- Los disparadores implementados son entrada en sobregiro, transacción, período y umbral.
- Las fórmulas admiten importe fijo, porcentaje, exenciones, vigencia y límite máximo.
- Las reglas oficiales verificadas tienen precedencia sobre campos individuales de compatibilidad.
- La comisión por entrada se aplica una sola vez mientras permanezca el estado de sobregiro.
- Los cargos periódicos se restringen por frecuencia mensual o anual.
- Cada cargo queda asociado a una clave determinista derivada del evento financiero.
- Se agregó `event_idempotency_key` a los movimientos de deuda para trazabilidad y reversión.
- La eliminación de un gasto de tarjeta revierte también la comisión asociada mediante un movimiento negativo trazable.

### Cambios frontend

- Los gastos con tarjeta exigen una vista previa emitida por el backend antes de guardar.
- La vista previa muestra importe, saldo anterior y proyectado, capacidad, comisión, impuestos y total.
- Cuando existe una regla de comisión, indica si proviene de una fuente verificada o de configuración individual.
- React no calcula el resultado de autorización ni sustituye la respuesta del backend.

### Pruebas ejecutadas

- 17 pruebas relacionadas con tarjetas y comisiones aprobadas.
- 74 aserciones aprobadas.
- Se cubrieron compras consecutivas, cargo único por entrada, reversión y regla periódica.

## Resultado del Bloque 4

### Cambios backend

- `TariffSourceDiscoveryService` ahora descubre fuentes oficiales registradas en productos activos; ya no construye URLs genéricas.
- `TariffDocumentDownloadService` exige HTTPS, dominio autorizado, resolución pública y límites de tamaño/tipo.
- Los documentos reales se almacenan de forma local y reciben SHA-256 sobre los bytes descargados.
- `TariffExtractionService` puede extraer texto de HTML y PDF, pero no infiere valores financieros como verificados.
- Los datos no verificados quedan `pending_review` y no reemplazan una tarifa activa.
- Se registran hash, ruta, tipo, estado de acceso y fecha de descubrimiento en los logs.
- El scheduler ejecuta `finance:sync-tariffs` diariamente a las 03:00 en Santo Domingo.
- El comando no inicia sincronización si no existen fuentes oficiales configuradas.

### Cambios frontend

- Ajustes incluye un panel de sincronización bancaria.
- El panel muestra fuente, estado, acceso, errores, hash y cambios pendientes.
- Las tarifas pendientes se presentan como pendientes de revisión.

### Limitaciones

- La aprobación humana/revisión administrativa aún requiere un flujo autorizado con roles; no se añadió publicación automática.
- La extracción de PDF/HTML obtiene texto y datos explícitos, pero no reemplaza validación documental especializada.
- No se ejecutaron migraciones en datos reales.

### Pruebas ejecutadas

- 5 pruebas de sincronización y seguridad aprobadas.
- 19 aserciones aprobadas.
- Se verificó rechazo de HTTP y dominios no autorizados.
- Frontend: 8 pruebas aprobadas y build exitoso.

## Resultado del Bloque 6

### Cambios backend

- Se agregaron actualización y eliminación propietarias para reservas.
- Las reservas permanecen vinculadas al usuario autenticado.
- Las rutas de presupuestos, reservas e importaciones están disponibles al cliente autenticado.
- El formulario de compromisos acepta y persiste la institución y variante financiera seleccionada.

### Cambios frontend

- Se integró `FinancialCatalogSelector` en la creación de tarjetas.
- `CardCapacityPanel` se muestra dentro del detalle de compromisos de tarjeta.
- La vista previa de gasto usa el motor API y mantiene la información contractual devuelta por el backend.
- Se creó `PlanningPage` accesible desde navegación para presupuestos, reservas e importaciones CSV.
- Se centralizaron consultas y mutaciones en React Query con invalidación posterior.

### APIs integradas

- `/api/v1/catalog/...`
- `/api/v1/budgets`
- `/api/v1/reserves`
- `/api/v1/reserves/{reserve}`
- `/api/v1/imports`
- `/api/v1/imports/{importedTransaction}`

### Limitaciones

- `CreditCardPaymentConfirmation.tsx` no sustituyó todavía al componente de vista previa personalizado; ambos representan el mismo flujo, pero falta una prueba de formulario dedicada.
- No existe una pantalla específica independiente de detalle de deuda; `DebtBalancesSection` mantiene el flujo completo dentro de compromisos.
- No se ejecutaron pruebas E2E de navegador.

## Resultado del Bloque 7

### Cambios backend

- El Dashboard incluye capacidad restante de tarjetas sin mezclarla con efectivo.
- Se añadió desglose confirmado de tarjetas, deudas y reservas.
- El saldo real continúa excluyendo cuentas de tarjeta.
- El disponible sin comprometer resta reservas del efectivo confirmado.
- Los importes de deudas se mantienen separados del ledger bancario.

### Cambios frontend

- El Dashboard muestra un desglose expandible de tarjetas.
- La tarjeta de cada producto informa límite aprobado, sobregiro, capacidad restante y estado.
- Se muestran desglose separado de deudas y reservas.
- Los importes confirmados están etiquetados como confirmados.

### Pruebas ejecutadas

- 3 pruebas de Dashboard/reportes aprobadas.
- 30 aserciones aprobadas.
- Frontend: 8 pruebas aprobadas y build exitoso.

### Limitaciones

- Las proyecciones siguen siendo estimaciones y no se presentan como saldos confirmados.
- No se ejecutó navegación visual E2E.

### Pruebas ejecutadas

- 7 pruebas backend aprobadas en catálogo, presupuestos/importaciones y arquitectura de tarjetas.
- 42 aserciones backend aprobadas.
- 8 pruebas frontend aprobadas.
- Build frontend aprobado.

## Resultado del Bloque 5

### Cambios backend

- Se eliminó `float`, `pow` y multiplicación flotante de `FinancialProductEngine`.
- La amortización francesa usa exponentes binarios implementados con BCMath.
- Se separó precisión interna de presentación monetaria.
- Se agregó redondeo monetario determinista con política half-up.
- Se agregó cálculo reproducible de intereses por saldo diario.
- Se agregó orden documental de aplicación de pagos para mora, comisiones, avances y compras.
- `CreditCardAuthorizationEngine` formatea límites y capacidades con strings exactos.
- La vista previa devuelve tasa aplicable, fuente, vigencia, comisión y explicación del motor.

### Cambios frontend

- La vista previa de gasto de tarjeta muestra tasa anual, fuente efectiva, fuente de comisión, fecha y explicación.
- Las estimaciones permanecen etiquetadas como previsión y provienen de la API.
- React no vuelve a calcular amortización ni intereses definitivos.

### Limitaciones

- La aplicación de pagos por producto específico requiere que el banco documente su precedencia contractual.
- Los cálculos diarios están disponibles como método determinista, pero no reemplazan estados históricos sin movimientos diarios completos.
- No se ejecutaron migraciones ni se modificaron datos financieros históricos.

### Pruebas ejecutadas

- 18 pruebas financieras y de tarjetas aprobadas.
- 73 aserciones aprobadas.
- Frontend: 8 pruebas aprobadas y build exitoso.
- Verificación específica: no quedan `pow` ni `float` en `FinancialProductEngine`.
