import { useQuery } from '@tanstack/react-query';
import { Button, Card, Caption1, Subtitle1 } from '@fluentui/react-components';
import { AddRegular, ArrowRightRegular, CalendarRegular, MoneyRegular } from '@fluentui/react-icons';
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { api } from '../api';
import { financialQueryKeys } from '../api/queryKeys';
import { ErrorState, LoadingState } from '../components/DataState';
import { formatMoney } from '../utils/money';
import { getPayPeriod } from '../utils/payPeriod';

export function DashboardPage() {
  const settings = useQuery({ queryKey: ['settings'], queryFn: api.getSettings });
  const payPeriod = getPayPeriod(settings.data?.payFrequency);
  const horizonDays = payPeriod.days;
  const interestCut = useQuery({
    queryKey: ['interest-cut', payPeriod.days],
    queryFn: api.getInterestCut,
    enabled: settings.isSuccess,
    refetchOnMount: 'always',
    refetchInterval: 5 * 60 * 1000,
  });
  const plan = useQuery({
    queryKey: financialQueryKeys.financialPlan(horizonDays),
    queryFn: () => api.getFinancialPlan(horizonDays),
    enabled: settings.isSuccess,
    refetchOnMount: 'always',
  });
  const dashboard = useQuery({ queryKey: ['dashboard'], queryFn: api.getDashboardSummary });

  if (settings.isPending || plan.isPending || dashboard.isPending) return <LoadingState />;
  if (plan.isError) return <ErrorState onRetry={() => void plan.refetch()} />;

  const data = plan.data;
  const nextIncome = data.expected_incomes[0];
  const nextPayments = data.obligations.slice(0, 5);
  const periodDebt = data.summary?.pending_obligations ?? data.obligations.reduce(
    (total, obligation) => total + Number(obligation.amount_pending ?? obligation.amount_due),
    0,
  ).toFixed(2);
  const periodDebtBars = data.obligations.slice(0, 6).map((obligation) => ({
    label: obligation.name.length > 16 ? `${obligation.name.slice(0, 15)}…` : obligation.name,
    dueDate: new Date(`${obligation.due_date}T12:00:00`).toLocaleDateString('es-DO', { day: '2-digit', month: 'short' }),
    amount: Number(obligation.amount_pending ?? obligation.amount_due),
  }));
  const totalDebt = dashboard.isError ? null : dashboard.data.balances.totalDebt;
  const incomplete = data.data_completeness?.status === 'incomplete';

  const periodLabel = new Intl.DateTimeFormat('es-DO', { weekday: 'long', day: 'numeric', month: 'long' }).format(new Date());

  return (
    <div className="dashboard-modern">
      <div className="dashboard-heading">
        <div><Subtitle1>Resumen financiero</Subtitle1><Caption1>{periodLabel}</Caption1></div>
        <span className="dashboard-horizon">{payPeriod.pendingLabel.replace('Pendiente ', '')}</span>
      </div>

      <Card className="interest-summary-card">
        <div className="interest-summary-main">
          <div className="interest-summary-title"><Subtitle1>Intereses del corte</Subtitle1><Caption1>{interestCut.data?.period.label ?? payPeriod.label}</Caption1></div>
          <strong className="interest-summary-total">{interestCut.isError ? 'No disponible' : formatMoney(interestCut.data?.summary.total ?? '0.00', 'DOP')}</strong>
        </div>
        <div className="interest-summary-breakdown">
          {interestCut.isPending ? <Caption1>Calculando intereses…</Caption1> : interestCut.isError ? <Caption1>No se pudo cargar el desglose de intereses.</Caption1> : <>
            <Caption1>Tarjetas {formatMoney(interestCut.data.summary.card_financing_interest, 'DOP')}</Caption1>
            <Caption1>Préstamos {formatMoney(interestCut.data.summary.loan_interest, 'DOP')}</Caption1>
            <Caption1>Sobregiro {formatMoney(interestCut.data.summary.overdraft_cut_charge, 'DOP')}</Caption1>
          </>}
        </div>
        <a className="interest-summary-link" href="/interests">Ver detalle</a>
      </Card>

      {incomplete && <Card className="warning-inline"><strong>Completa tus próximos pagos</strong><Caption1>{(data.data_completeness?.missing_information ?? []).join('. ')}</Caption1></Card>}

      <section className="dashboard-hero-grid">
        <Card className="money-primary-card">
          <div className="money-primary-header"><Caption1>{incomplete ? 'Dinero libre estimado' : 'Dinero disponible'}</Caption1><span className="status-dot">Estimado</span></div>
          <div className="money-primary-value">{formatMoney(data.free_after_plan, 'DOP')}</div>
          <div className="money-primary-caption">Puedes utilizarlo sin afectar los pagos proyectados.</div>
          <div className="money-primary-grid">
            <div><Caption1>Saldo real</Caption1><strong>{formatMoney(data.account_balance, 'DOP')}</strong></div>
            <div><Caption1>Protección sugerida</Caption1><strong>{formatMoney(data.virtual_reserves, 'DOP')}</strong></div>
            <div><Caption1>Restante previsto</Caption1><strong>{formatMoney(data.projection.ending_balance, 'DOP')}</strong></div>
          </div>
        </Card>

        <Card className="quick-actions-card">
          <Subtitle1>Acciones rápidas</Subtitle1>
          <div className="dashboard-actions">
            <Button appearance="primary" as="a" href="/income" icon={<AddRegular />}>Registrar ingreso</Button>
            <Button appearance="secondary" as="a" href="/expenses" icon={<MoneyRegular />}>Registrar gasto</Button>
            <Button appearance="secondary" as="a" href="/payments" icon={<CalendarRegular />}>Registrar pago</Button>
          </div>
        </Card>
      </section>

      <section className="dashboard-insight-grid">
        <Card className="next-income-card">
          <div className="section-title"><div><Subtitle1>Próximo ingreso</Subtitle1><Caption1>Antes de tus próximos vencimientos</Caption1></div></div>
          {nextIncome ? <>
            <div className="dashboard-next-amount">{formatMoney(nextIncome.remaining, 'DOP')}</div>
            <div className="next-income-detail"><strong>{nextIncome.source}</strong><Caption1>{new Date(`${nextIncome.date}T12:00:00`).toLocaleDateString('es-DO', { dateStyle: 'long' })}</Caption1></div>
            <Button appearance="subtle" as="a" href="/income" icon={<ArrowRightRegular />}>Confirmar recepción</Button>
          </> : <div className="dashboard-empty-action"><Caption1>Todavía no tienes ingresos previstos.</Caption1><Button appearance="secondary" as="a" href="/income">Registrar ingreso</Button></div>}
        </Card>

        <Card className="period-debt-card">
          <div className="section-title">
            <div><Subtitle1>Corte de deuda</Subtitle1><Caption1>{payPeriod.label} · pagos del periodo</Caption1></div>
            <strong className="period-debt-total">{formatMoney(periodDebt, 'DOP')}</strong>
          </div>
          {periodDebtBars.length === 0
            ? <Caption1>No hay pagos pendientes en este periodo.</Caption1>
            : <>
              <div className="period-debt-chart" role="img" aria-label={`Pagos pendientes ${payPeriod.label.toLowerCase()}: ${formatMoney(periodDebt, 'DOP')}`}>
                <ResponsiveContainer width="100%" height="100%">
                  <BarChart data={periodDebtBars} layout="vertical" margin={{ top: 2, right: 12, bottom: 2, left: 0 }}>
                    <CartesianGrid horizontal={false} stroke="#2c333b" />
                    <XAxis type="number" hide />
                    <YAxis type="category" dataKey="label" width={104} axisLine={false} tickLine={false} tick={{ fill: '#9aa4b2', fontSize: 11 }} />
                    <Tooltip
                      cursor={{ fill: 'rgba(255,255,255,0.04)' }}
                      labelFormatter={(label, payload) => `${label}${payload?.[0]?.payload?.dueDate ? ` · ${payload[0].payload.dueDate}` : ''}`}
                      formatter={(value) => [formatMoney(String(value ?? 0), 'DOP'), 'Pendiente']}
                      contentStyle={{ background: '#181c21', border: '1px solid #30363e', borderRadius: 6, color: '#edf2f7' }}
                    />
                    <Bar dataKey="amount" name="Pendiente" fill="#62a9a1" radius={[0, 4, 4, 0]} maxBarSize={18} />
                  </BarChart>
                </ResponsiveContainer>
              </div>
              {data.obligations.length > periodDebtBars.length && <Caption1>Mostrando {periodDebtBars.length} de {data.obligations.length} pagos del periodo.</Caption1>}
            </>}
        </Card>
      </section>

      <section className="dashboard-bottom-grid">
        <Card className="payments-card">
          <div className="section-title"><div><Subtitle1>Próximos pagos</Subtitle1><Caption1>Ordenados por fecha de vencimiento</Caption1></div><Button appearance="subtle" as="a" href="/payments">Ver todos</Button></div>
          {nextPayments.length === 0 ? <div className="dashboard-empty-action"><Caption1>No hay pagos pendientes en el horizonte.</Caption1><Button appearance="secondary" as="a" href="/payments">Agregar pago</Button></div> : <div className="dashboard-payment-list">
            {nextPayments.map((payment) => <div className="dashboard-payment-row" key={payment.id ?? `${payment.commitment_id}-${payment.due_date}`}>
              <div className="payment-date"><strong>{new Date(`${payment.due_date}T12:00:00`).toLocaleDateString('es-DO', { day: '2-digit', month: 'short' })}</strong></div>
              <div className="payment-info"><strong>{payment.name}</strong><Caption1>{payment.overdue ? 'Pago vencido' : 'Pendiente'} · {payment.coverage_status === 'covered' ? 'Cubierto' : payment.coverage_status === 'partially_covered' ? 'Cobertura parcial' : 'Sin cobertura'}</Caption1></div>
              <strong className="payment-amount">{formatMoney(payment.amount_pending ?? payment.amount_due, 'DOP')}</strong>
            </div>)}
          </div>}
        </Card>

        <Card className="secondary-summary-card">
          <Subtitle1>Resumen del periodo</Subtitle1>
          <div className="dashboard-summary-grid">
            <div><Caption1>Ingresos previstos</Caption1><strong>{formatMoney(data.summary?.expected_income ?? data.projection.expected_total, 'DOP')}</strong></div>
            <div><Caption1>Compromisos</Caption1><strong>{formatMoney(data.summary?.pending_obligations ?? data.projection.commitments_total, 'DOP')}</strong></div>
            <div><Caption1>Faltante máximo</Caption1><strong>{formatMoney(data.summary?.maximum_shortfall ?? data.projection.peak_shortfall, 'DOP')}</strong></div>
            <div><Caption1>Deuda registrada</Caption1><strong>{dashboard.isError ? 'No disponible' : (totalDebt ?? 'Importe no disponible')}</strong></div>
          </div>
        </Card>
      </section>
    </div>
  );
}
