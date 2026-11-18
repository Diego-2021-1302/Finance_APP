import { useQuery } from '@tanstack/react-query';
import { Card, CardHeader, Caption1, Subtitle1 } from '@fluentui/react-components';
import {
  WalletRegular, MoneyRegular, CalendarRegular, ArrowUpRightRegular, AlertRegular,
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

  const metrics = [
    { label: 'Dinero total disponible', value: summary.balances.confirmed, detail: `${summary.balances.accountCount} cuentas activas`, icon: <WalletRegular /> },
    { label: 'Deuda total acumulada', value: summary.balances.totalDebt ?? 'RD$ 0.00', detail: 'Tarjetas y préstamos', icon: <AlertRegular /> },
    { label: 'Ingresos recibidos este mes', value: summary.income.received, detail: `Cobros de ${currentMonth}`, icon: <MoneyRegular /> },
    { label: 'Compromisos próximos', value: summary.commitments.dueSoon, detail: summary.commitments.dueSoonLabel, icon: <CalendarRegular /> },
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

      {/* Main Content Grid: Priority Payments & Account Summaries */}
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

        {/* Account Summaries & Quick Link */}
        <Card className="content-card account-card">
          <div className="section-title">
            <div>
              <Subtitle1>Saldos por Cuenta</Subtitle1>
              <Caption1>Liquidez real disponible en tus cuentas</Caption1>
            </div>
          </div>
          <div className="account-balance">{summary.balances.confirmed}</div>
          <Caption1>Dinero total disponible</Caption1>

          <div style={{ display: 'grid', gap: '10px', marginTop: '20px' }}>
            {accounts.map((acc) => (
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
