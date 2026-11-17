import { useQuery } from '@tanstack/react-query';
import {
  Area, AreaChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis,
} from 'recharts';
import { Card, Caption1, Field, Input, Subtitle1 } from '@fluentui/react-components';
import { useState } from 'react';
import { api } from '../api';
import { ErrorState, LoadingState, EmptyState } from '../components/DataState';
import { FinanceTable } from '../components/FinanceTable';

function formatMinorUnits(value: number): string {
  const absolute = Math.abs(value);
  const grouped = Math.floor(absolute / 100).toLocaleString('en-US');
  const cents = String(absolute % 100).padStart(2, '0');
  return `${value < 0 ? '−' : ''}RD$ ${grouped}.${cents}`;
}

export function ReportsPage() {
  const [year, setYear] = useState(new Date().getFullYear());
  const from = `${year}-01-01`;
  const to = `${year}-12-31`;
  const query = useQuery({ queryKey: ['cash-flow', from, to], queryFn: () => api.getCashFlowReport(from, to, 'month') });
  if (query.isPending) return <LoadingState />;
  if (query.isError) return <ErrorState onRetry={() => void query.refetch()} />;
  const data = query.data;
  return <>
    <div className="page-intro"><p>Review annual net cash flow and compare projections with actuals month by month.</p><Field label="Report year"><Input type="number" min="2000" max="2100" value={String(year)} onChange={(event) => setYear(Number(event.target.value) || year)} /></Field></div>
    <section className="metric-grid report-metrics">
      {[['Forecast', data.totals.forecast], ['Actual', data.totals.actual], ['Variance', data.totals.variance]].map(([label, amount]) => <Card className="metric-card" key={label}><Caption1>{label} net cash flow</Caption1><div className="metric-value">{amount}</div><Caption1>{year} · DOP</Caption1></Card>)}
    </section>
    <Card className="content-card chart-card"><div className="section-title"><div><Subtitle1>Monthly net cash flow</Subtitle1><Caption1>Income minus commitments and debt payments</Caption1></div></div>
      {data.categories.every((category) => category.actual === 'RD$ 0.00' && category.forecast === 'RD$ 0.00') ?
        <EmptyState label={`No cash-flow activity for ${year}. Choose another year or add records.`} /> :
      <div className="chart-area"><ResponsiveContainer width="100%" height="100%"><AreaChart data={data.series} margin={{ top: 12, right: 12, left: 0, bottom: 0 }}>
        <defs><linearGradient id="actualFill" x1="0" y1="0" x2="0" y2="1"><stop offset="5%" stopColor="#0F766E" stopOpacity={0.22} /><stop offset="95%" stopColor="#0F766E" stopOpacity={0} /></linearGradient></defs>
        <CartesianGrid stroke="#e5eaf1" vertical={false} /><XAxis dataKey="month" axisLine={false} tickLine={false} /><YAxis axisLine={false} tickLine={false} tickFormatter={(value: number) => formatMinorUnits(value)} /><Tooltip formatter={(value) => formatMinorUnits(Number(value))} />
        <Area type="monotone" dataKey="forecastMinor" stroke="#1F4B99" fill="#1F4B99" fillOpacity={0.08} strokeWidth={2} />
        <Area type="monotone" dataKey="actualMinor" stroke="#0F766E" fill="url(#actualFill)" strokeWidth={3} />
      </AreaChart></ResponsiveContainer></div>}
    </Card>
    <div className="section-title table-section-title"><div><Subtitle1>Cash flow by category</Subtitle1></div></div>
    <FinanceTable columns={['Category', 'Forecast', 'Actual', 'Variance']} rows={data.categories.map((row) => ({ Category: row.category, Forecast: row.forecast, Actual: row.actual, Variance: row.variance }))} />
  </>;
}
