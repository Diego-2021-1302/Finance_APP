import { useQuery } from '@tanstack/react-query';
import { Card, CardHeader, Caption1, Subtitle1 } from '@fluentui/react-components';
import {
  WalletRegular, MoneyRegular, ArrowUpRightRegular, AlertRegular, ShieldLockRegular,
} from '@fluentui/react-icons';
import { Link } from 'react-router-dom';
import { api } from '../api';
import { ErrorState, LoadingState } from '../components/DataState';

export function DashboardPage() {
  const query = useQuery({ queryKey: ['dashboard'], queryFn: api.getDashboardSummary });
  const accountsQuery = useQuery({ queryKey: ['accounts'], queryFn: api.listAccounts });
  const currentMonth = new Intl.DateTimeFormat('es-DO', { month: 'long', year: 'numeric' }).format(new Date());

  if (query.isPending || accountsQuery.isPending) return <LoadingState />;
  if (query.isError) return <ErrorState onRetry={() => void query.refetch()} />;
  if (accountsQuery.isError) return <ErrorState onRetry={() => void accountsQuery.refetch()} />;

  const summary = query.data;
  const accounts = accountsQuery.data;
  const liquidAccounts = accounts.filter((account) => account.type !== 'credit_card');
  const cards = summary.balances.cards ?? [];
  const debtBreakdown = summary.balances.debtBreakdown ?? [];
  const reservesBreakdown = summary.balances.reservesBreakdown ?? [];

  const metrics = [
    { label: 'Saldo real en cuentas', value: summary.balances.real, detail: `${summary.balances.accountCount} cuentas registradas`, icon: <WalletRegular /> },
    { label: 'Dinero reservado', value: summary.balances.reserved ?? 'RD$ 0.00', detail: 'Fondos apartados para obligaciones', icon: <ShieldLockRegular /> },
    { label: 'Disponible sin comprometer', value: summary.balances.availableUncommitted ?? summary.balances.real, detail: 'Saldo real menos reservas activas', icon: <MoneyRegular /> },
    { label: 'Deuda registrada', value: summary.balances.totalDebt ?? 'RD$ 0.00', detail: 'Saldos de tarjetas y préstamos', icon: <AlertRegular /> },
  ];

  return (
    <div style={{ display: 'grid', gap: '24px' }}>
      {/* Target Summary Cards */}
      <section className="metric-grid" aria-label={`Resumen financiero de ${currentMonth}`}>
        {metrics.map((metric) => (
          <Card className="metric-card" key={metric.label}>
            <CardHeader image={<span className="metric-icon">{metric.icon}</span>} header={<Caption1>{metric.label}</Caption1>} />
            <div className="metric-value">{metric.value}</div>
            <Caption1>{metric.detail}</Caption1>
          </Card>
        ))}
      </section>

      <section className="content-grid dashboard-grid">
        <Card className="content-card">
          <div className="section-title">
            <div>
              <Subtitle1>Desglose confirmado de tarjetas</Subtitle1>
              <Caption1>Crédito disponible separado del efectivo</Caption1>
            </div>
          </div>
          {cards.length === 0 ? (
            <div className="empty-inline">No hay tarjetas registradas.</div>
          ) : (
            <div style={{ display: 'grid', gap: '10px', marginTop: '12px' }}>
              {cards.map((card) => (
                <details key={card.id} className="debt-action">
                  <summary>{card.name} · confirmado · saldo {card.balance}</summary>
                  <div style={{ display: 'grid', gap: '6px', padding: '8px 0', fontSize: '13px' }}>
                    <div><strong>Límite aprobado:</strong> {card.approved_credit_limit}</div>
                    <div><strong>Sobregiro autorizado:</strong> {card.authorized_overdraft_limit}</div>
                    <div><strong>Capacidad restante:</strong> {card.remaining_total_capacity}</div>
                    <div><strong>Sobregiro restante:</strong> {card.remaining_overdraft}</div>
                    <div><strong>Estado del límite:</strong> {card.limit_status}</div>
                  </div>
                </details>
              ))}
            </div>
          )}
        </Card>

        <Card className="content-card">
          <div className="section-title">
            <div>
              <Subtitle1>Deudas y reservas confirmadas</Subtitle1>
              <Caption1>Importes registrados, no disponibles como efectivo</Caption1>
            </div>
          </div>
          <details className="debt-action" open>
            <summary>Deudas ({debtBreakdown.length})</summary>
            {debtBreakdown.length ? debtBreakdown.map((debt) => (
              <div key={debt.id} style={{ display: 'flex', justifyContent: 'space-between', padding: '6px 0', fontSize: '13px' }}>
                <span>{debt.name} · confirmado</span>
                <strong>{debt.outstanding}</strong>
              </div>
            )) : <Caption1>No hay deudas registradas.</Caption1>}
          </details>
          <details className="debt-action" open>
            <summary>Reservas ({reservesBreakdown.length})</summary>
            {reservesBreakdown.length ? reservesBreakdown.map((reserve) => (
              <div key={reserve.id} style={{ display: 'flex', justifyContent: 'space-between', padding: '6px 0', fontSize: '13px' }}>
                <span>{reserve.name} · confirmado</span>
                <strong>{reserve.currentAmount} / {reserve.targetAmount}</strong>
              </div>
            )) : <Caption1>No hay reservas registradas.</Caption1>}
          </details>
        </Card>
      </section>

      {/* Main Content Grid: Priority Payments & Budgets */}
      <section className="content-grid dashboard-grid">
        {/* Priority Commitment Payments */}
        <Card className="content-card">
          <div className="section-title">
            <div>
              <Subtitle1>Prioridad de Pagos de Compromisos</Subtitle1>
              <Caption1>Próximos vencimientos ordenados por prioridad de fecha</Caption1>
            </div>
            <Link to="/commitments">Ver compromisos →</Link>
          </div>
          {summary.upcoming.length ? (
            <div className="upcoming-list">
              {summary.upcoming.map((item) => (
                <div className="upcoming-row" key={item.label}>
                  <div className="date-chip">{item.dueDate}</div>
                  <div className="upcoming-name">
                    <strong>{item.label}</strong>
                    <Caption1>{item.kind}</Caption1>
                  </div>
                  <strong>{item.amount}</strong>
                </div>
              ))}
            </div>
          ) : (
            <div className="empty-inline">No hay compromisos pendientes próximos. ¡Estás al día!</div>
          )}
        </Card>

        {/* Budgets & Account Summaries */}
        <Card className="content-card account-card">
          <div className="section-title">
            <div>
              <Subtitle1>Presupuestos del Mes</Subtitle1>
              <Caption1>Control de gastos por categoría</Caption1>
            </div>
          </div>
          {summary.budgets && summary.budgets.length > 0 ? (
            <div style={{ display: 'grid', gap: '12px', marginTop: '14px' }}>
              {summary.budgets.map((b) => (
                <div key={b.category} style={{ fontSize: '13px' }}>
                  <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '4px' }}>
                    <strong>{b.category}</strong>
                    <span>{b.spent} / {b.limit}</span>
                  </div>
                  <div style={{ background: 'var(--border-subtle)', borderRadius: '4px', height: '6px', overflow: 'hidden' }}>
                    <div style={{ width: `${Math.min(100, b.percentage)}%`, background: b.percentage > 90 ? 'var(--color-status-danger)' : 'var(--primary)', height: '100%' }} />
                  </div>
                </div>
              ))}
            </div>
          ) : (
            <div className="empty-inline" style={{ marginTop: '14px' }}>No hay presupuestos configurados para este mes.</div>
          )}

          <div style={{ display: 'grid', gap: '10px', marginTop: '24px' }}>
            <Subtitle1 style={{ fontSize: '14px' }}>Saldos por Cuenta</Subtitle1>
            {liquidAccounts.map((acc) => (
              <div key={acc.id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', fontSize: '13px', padding: '8px 0', borderBottom: '1px solid var(--border-subtle)' }}>
                <span>{acc.name}</span>
                <strong style={{ color: 'var(--primary)' }}>{acc.balance}</strong>
              </div>
            ))}
          </div>

          <Link className="account-link" to="/transactions">
            Revisar historial de movimientos <ArrowUpRightRegular />
          </Link>
        </Card>
      </section>
    </div>
  );
}
