import { useQuery } from '@tanstack/react-query';
import { Card, Caption1, Subtitle1 } from '@fluentui/react-components';
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { api } from '../api';
import { ErrorState, LoadingState } from '../components/DataState';
import { formatMoney } from '../utils/money';

const formatDate = (value: string) => new Date(`${value}T12:00:00`).toLocaleDateString('es-DO', {
  day: 'numeric',
  month: 'short',
  year: 'numeric',
});

const overdraftStatus = (status: string) => ({
  posted: 'Contabilizado por el banco',
  estimated: 'Estimado al próximo corte',
  not_configured: 'No configurado',
  missing_limit: 'Falta registrar el límite aprobado',
  not_applicable: 'No aplica',
}[status] ?? status);

const financingStatus = (status: string) => ({
  accruing: 'Interés activo sobre saldo financiado',
  grace_period: 'En periodo de gracia · sin interés financiado',
  paid: 'Saldo facturado cubierto',
  statement_unavailable: 'Falta saldo facturado o fecha de vencimiento',
}[status] ?? status);

export function InterestCutPage() {
  const query = useQuery({
    queryKey: ['interest-cut'],
    queryFn: api.getInterestCut,
    refetchOnMount: 'always',
    refetchInterval: 5 * 60 * 1000,
  });

  if (query.isPending) return <LoadingState />;
  if (query.isError) return <ErrorState onRetry={() => void query.refetch()} />;

  const report = query.data;
  const chartData = [
    { name: 'Financiamiento tarjeta', amount: Number(report.summary.card_financing_interest) },
    { name: 'Interés préstamo', amount: Number(report.summary.loan_interest) },
    { name: 'Sobregiro al corte', amount: Number(report.summary.overdraft_cut_charge) },
  ];

  return <div className="interest-cut-page">
    <div className="page-intro">
      <Subtitle1>Intereses y cargos del corte</Subtitle1>
      <Caption1>{report.period.label} · {formatDate(report.period.starts_on)} – {formatDate(report.period.ends_on)}</Caption1>
      <Caption1>Acumulado calculado al {formatDate(report.period.calculated_through)} · {report.period.days_elapsed} día(s).</Caption1>
    </div>

    <Card className="content-card interest-cut-total-card">
      <div><Caption1>Total acumulado del corte</Caption1><strong>{formatMoney(report.summary.total, 'DOP')}</strong></div>
      <Caption1>Incluye intereses diarios y cargos por sobregiro contabilizados o estimados.</Caption1>
    </Card>

    {report.items.length === 0 ? <Card className="content-card"><Caption1>No hay tarjetas o préstamos con saldo pendiente en este corte.</Caption1></Card> : <>
      <Card className="content-card interest-cut-chart-card">
        <div className="interest-cut-section-heading"><div><Subtitle1>Desglose del corte</Subtitle1></div><div><Caption1>Montos acumulados por tipo de interés o cargo.</Caption1></div></div>
        <div className="interest-cut-chart">
          <ResponsiveContainer width="100%" height="100%">
            <BarChart data={chartData} layout="vertical" margin={{ top: 4, right: 16, bottom: 4, left: 8 }}>
              <CartesianGrid horizontal={false} stroke="#2c333b" />
              <XAxis type="number" hide />
              <YAxis type="category" dataKey="name" width={150} axisLine={false} tickLine={false} tick={{ fill: '#9aa4b2', fontSize: 11 }} />
              <Tooltip
                cursor={{ fill: 'rgba(255,255,255,0.04)' }}
                formatter={(value) => [formatMoney(String(value ?? 0), 'DOP'), 'Acumulado']}
                contentStyle={{ background: '#181c21', border: '1px solid #30363e', borderRadius: 6, color: '#edf2f7' }}
              />
              <Bar dataKey="amount" name="Acumulado" fill="#62a9a1" radius={[0, 4, 4, 0]} maxBarSize={22} />
            </BarChart>
          </ResponsiveContainer>
        </div>
      </Card>

      <section className="interest-cut-details">
        <div className="interest-cut-section-heading"><div><Subtitle1>Detalle por producto</Subtitle1></div><div><Caption1>El cálculo se actualiza al consultar y no modifica el saldo de la deuda.</Caption1></div></div>
        {report.items.map((item) => <Card className="content-card interest-cut-item" key={item.id}>
          <div className="interest-cut-item-heading">
            <div><Subtitle1>{item.name}</Subtitle1><Caption1>{item.kind_label} · saldo {formatMoney(item.balance, 'DOP')}</Caption1></div>
            <strong>{formatMoney(item.total, 'DOP')}</strong>
          </div>
          <div className="interest-cut-breakdown">
            <div>
              <Caption1>{item.financing_interest_label}</Caption1>
              <strong>{formatMoney(item.financing_interest, 'DOP')}</strong>
              <Caption1>{item.rate_missing ? 'Tasa anual no registrada; no se estima este interés.' : `${item.annual_rate}% anual · ${item.rate_source}`}</Caption1>
              <Caption1>Capital financiado: {formatMoney(item.financed_balance, 'DOP')} · {financingStatus(item.financing_status)}</Caption1>
              {item.kind === 'credit_card' && item.financing_status === 'grace_period' && <Caption1>Saldo facturado pendiente: {formatMoney(item.projected_financed_balance, 'DOP')} · Si no se paga, desde el {item.financing_starts_on}: {formatMoney(item.daily_interest_if_unpaid, 'DOP')} por día.</Caption1>}
              {!item.rate_missing && <Caption1>{item.financing_interest_detail} Tasa diaria: {item.daily_interest_rate}%.</Caption1>}
              {item.balance_source === 'current_balance' && <Caption1>Calculado con el saldo actual; no hubo movimientos en este corte.</Caption1>}
              {item.balance_source === 'current_balance_and_period_movements' && <Caption1>Saldo actual reconstruido con los movimientos y pagos de este corte.</Caption1>}
            </div>
            {item.kind === 'credit_card' && <div>
              <Caption1>Cobro por sobregiro al corte · {overdraftStatus(item.overdraft_charge_status)}</Caption1>
              <strong>{formatMoney(item.overdraft_cut_charge, 'DOP')}</strong>
              <Caption1>{item.overdraft_charge_detail}</Caption1>
            </div>}
          </div>
        </Card>)}
      </section>
    </>}
  </div>;
}