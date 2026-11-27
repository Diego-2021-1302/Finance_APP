import { useQuery } from '@tanstack/react-query';
import { Card, Caption1, Subtitle1 } from '@fluentui/react-components';
import { Link } from 'react-router-dom';
import { api } from '../api';
import { financialQueryKeys } from '../api/queryKeys';
import { ErrorState, LoadingState } from './DataState';
import { getPayPeriod } from '../utils/payPeriod';

const money = (value: string) => new Intl.NumberFormat('es-DO', { style: 'currency', currency: 'DOP' }).format(Number(value));

export function FinancialOverview({ showPayments = false }: { showPayments?: boolean }) {
  const settings = useQuery({ queryKey: ['settings'], queryFn: api.getSettings });
  const payPeriod = getPayPeriod(settings.data?.payFrequency);
  const query = useQuery({ queryKey: financialQueryKeys.financialPlan(payPeriod.days), queryFn: () => api.getFinancialPlan(payPeriod.days), enabled: settings.isSuccess, refetchOnMount: 'always' });
  if (settings.isPending || query.isPending) return <LoadingState />;
  if (settings.isError) return <ErrorState onRetry={() => void settings.refetch()} />;
  if (query.isError) return <ErrorState onRetry={() => void query.refetch()} />;
  const plan = query.data;
  const projection = plan.projection;
  return <Card className="content-card" style={{ display: 'grid', gap: 12 }}>
    <div className="section-title"><Subtitle1>Tu dinero y próximos pagos</Subtitle1><Caption1>{payPeriod.pendingLabel.replace('Pendiente ', '')}</Caption1></div>
    <div style={{display:'grid', gridTemplateColumns:'repeat(auto-fit,minmax(145px,1fr))',gap:12}}>
      {[
        ['Saldo actual en cuentas', plan.account_balance],
        ['Ingresos por recibir', projection.expected_total],
        ['Compromisos pendientes', projection.commitments_total],
        ['Saldo final proyectado', projection.ending_balance],
        ['Disponible tras asignación actual', plan.free_after_plan],
        ['Mayor faltante proyectado', projection.peak_shortfall],
      ].map(([label,value]) => <div key={label}><Caption1>{label}</Caption1><div style={{fontSize:20,fontWeight:700}}>{money(value)}</div></div>)}
    </div>
    {projection.first_shortfall_date && <p role="status">Posible falta de fondos desde {projection.first_shortfall_date}. Revisa los vencimientos antes de gastar.</p>}
    <Caption1>Proyección en DOP con ingresos pendientes y obligaciones registradas; no incluye gastos futuros no registrados. Los ingresos previstos no son efectivo disponible. Las reservas existentes no se restan nuevamente del flujo proyectado.</Caption1>
    <div style={{display:'flex',gap:16,flexWrap:'wrap'}}><Link to="/income">Registrar ingreso</Link><Link to="/expenses">Registrar gasto</Link><Link to="/commitments">Gestionar pagos</Link></div>
    {showPayments && <><Subtitle1>Vencimientos por fecha</Subtitle1>{plan.obligations.length === 0 ? <Caption1>No hay vencimientos confirmados en este periodo.</Caption1> : plan.obligations.map(item => <div key={item.occurrence_id} style={{display:'flex',justifyContent:'space-between',gap:12,borderBottom:'1px solid var(--colorNeutralStroke2)',padding:'8px 0'}}><div><strong>{item.name}</strong><div>{item.due_date} {item.overdue ? '· Vencido' : ''}</div><Caption1>{item.minimum_confirmed ? `Exigible: ${money(item.minimum_required)}` : 'Mínimo sin confirmar'} · Asignación sugerida hoy: {money(item.suggested_reserve)}</Caption1></div><strong>{money(item.amount_due)}</strong></div>)}</>}
    <details><summary>Ver flujo por fecha</summary>{projection.events.map((event,index) => <div key={index} style={{display:'flex',justifyContent:'space-between',gap:8,padding:'6px 0',flexWrap:'wrap'}}><span>{event.date} · {event.name}{event.timing_uncertain ? ' · Horario no confirmado; se calcula el pago antes del ingreso' : ''}</span><span>{money(event.amount)} → {money(event.balance)}</span></div>)}</details>
  </Card>;
}
