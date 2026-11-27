import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Card, Caption1, Field, Input, MessageBar, MessageBarBody, Subtitle1 } from '@fluentui/react-components';
import { api, createUuid } from '../api';
import { financialQueryKeys } from '../api/queryKeys';
import { decimalToMinor, minorToDecimal } from '../utils/money';
import { EmptyState, ErrorState, LoadingState } from '../components/DataState';

const today = new Date();
const todayIso = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;

export function IncomePage() {
  const queryClient = useQueryClient();
  const incomes = useQuery({ queryKey: financialQueryKeys.income, queryFn: api.listIncomes });
  const accounts = useQuery({ queryKey: financialQueryKeys.accounts, queryFn: api.listAccounts });
  const [source, setSource] = useState('');
  const [amount, setAmount] = useState('');
  const [expectedDate, setExpectedDate] = useState(todayIso);
  const [accountId, setAccountId] = useState('');
  const [frequency, setFrequency] = useState('once');
  const [recurrenceDay, setRecurrenceDay] = useState('15');
  const [recurrenceSecondDay, setRecurrenceSecondDay] = useState('31');
  const [message, setMessage] = useState('');
  const [receiptKeys, setReceiptKeys] = useState<Record<string, string>>({});

  const invalidate = async () => {
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: financialQueryKeys.income }),
      queryClient.invalidateQueries({ queryKey: financialQueryKeys.accounts }),
      queryClient.invalidateQueries({ queryKey: ['financial-plan'] }),
      queryClient.invalidateQueries({ queryKey: financialQueryKeys.dashboard }),
      queryClient.invalidateQueries({ queryKey: financialQueryKeys.transactions }),
    ]);
  };

  const create = useMutation({
    mutationFn: () => api.createIncome({
      source,
      amount,
      expected_date: expectedDate,
      frequency,
      recurrence_day: frequency === 'once' ? undefined : Number(recurrenceDay),
      recurrence_second_day: frequency === 'semimonthly' ? Number(recurrenceSecondDay) : undefined,
    }),
    onSuccess: async () => {
      setSource(''); setAmount(''); setMessage('Ingreso previsto registrado.'); await invalidate();
    },
  });
  const receive = useMutation({
    mutationFn: ({ id, amount, key }: { id: string; amount: string; key: string }) => api.recordReceipt(id, {
      account_id: accountId,
      amount,
      received_at: todayIso,
      idempotency_key: key,
    }),
    onSuccess: async () => { setMessage('Recepción registrada y saldo actualizado.'); await invalidate(); },
  });

  const totalExpected = useMemo(() => incomes.data?.reduce((sum, item) => sum + (item.status === 'Recibido' ? 0 : Number(item.amount.replace(/[^0-9.-]/g, ''))), 0) ?? 0, [incomes.data]);
  const received = useMemo(() => incomes.data?.reduce((sum, item) => sum + Number(item.receivedAmount?.replace(/[^0-9.-]/g, '') ?? 0), 0) ?? 0, [incomes.data]);

  if (incomes.isPending || accounts.isPending) return <LoadingState />;
  if (incomes.isError || accounts.isError) return <ErrorState onRetry={() => void Promise.all([incomes.refetch(), accounts.refetch()])} />;

  const liquidAccounts = accounts.data.filter((account) => account.type !== 'credit_card');

  return <div style={{ display: 'grid', gap: 16 }}>
    {message && <MessageBar><MessageBarBody>{message}</MessageBarBody></MessageBar>}
    <div className="metric-grid">
      <Card className="metric-card"><Caption1>Ingresos recibidos</Caption1><div className="metric-value">{new Intl.NumberFormat('es-DO', { style: 'currency', currency: 'DOP' }).format(received)}</div></Card>
      <Card className="metric-card"><Caption1>Ingresos previstos pendientes</Caption1><div className="metric-value">{new Intl.NumberFormat('es-DO', { style: 'currency', currency: 'DOP' }).format(totalExpected)}</div></Card>
      <Card className="metric-card"><Caption1>Próximo ingreso</Caption1><div className="metric-value">{incomes.data.find((item) => item.status !== 'Recibido')?.source ?? 'Sin próximos ingresos'}</div></Card>
    </div>

    <Card className="content-card">
      <Subtitle1>Registrar ingreso</Subtitle1>
      <form onSubmit={(event) => { event.preventDefault(); create.mutate(); }} style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 12, marginTop: 12 }}>
        <Field label="Concepto" required><Input value={source} onChange={(_, data) => setSource(data.value)} placeholder="Ej. Salario" /></Field>
        <Field label="Monto neto" required><Input value={amount} onChange={(_, data) => setAmount(data.value)} input={{ inputMode: 'decimal' }} /></Field>
        <Field label="Fecha de cobro" required><Input type="date" value={expectedDate} onChange={(_, data) => setExpectedDate(data.value)} /></Field>
        <Field label="Frecuencia" required><select value={frequency} onChange={(event) => setFrequency(event.target.value)}><option value="once">Único</option><option value="weekly">Semanal</option><option value="biweekly">Cada 14 días</option><option value="semimonthly">Quincenal con fechas fijas</option><option value="monthly">Mensual</option></select></Field>
        {frequency === 'semimonthly' && <><Field label="Primera fecha"><Input type="number" min="1" max="31" value={recurrenceDay} onChange={(_, data) => setRecurrenceDay(data.value)} /></Field><Field label="Segunda fecha"><Input type="number" min="1" max="31" value={recurrenceSecondDay} onChange={(_, data) => setRecurrenceSecondDay(data.value)} /></Field></>}
        {frequency === 'monthly' && <Field label="Día del mes"><Input type="number" min="1" max="31" value={recurrenceDay} onChange={(_, data) => setRecurrenceDay(data.value)} /></Field>}
        <div style={{ alignSelf: 'end' }}><Button type="submit" appearance="primary" disabled={create.isPending || !source || !amount}>Guardar ingreso</Button></div>
      </form>
      {create.isError && <MessageBar intent="error"><MessageBarBody>No se pudo registrar el ingreso. Revisa los datos e inténtalo de nuevo.</MessageBarBody></MessageBar>}
    </Card>

    <Card className="content-card">
      <Subtitle1>Historial de ingresos</Subtitle1>
      {incomes.data.length === 0 ? <EmptyState label="Aún no tienes ingresos registrados." /> : <div style={{ display: 'grid', gap: 8, marginTop: 12 }}>
        {incomes.data.map((income) => <div className="record-heading" key={income.id}>
          <div><strong>{income.source}</strong><Caption1>{income.expectedDate} · {income.status}</Caption1></div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}><strong>{income.amount}</strong>
            {income.status !== 'Recibido' && <>
              <select aria-label="Cuenta de destino" value={accountId} onChange={(event) => setAccountId(event.target.value)}><option value="">Cuenta</option>{liquidAccounts.map((account) => <option key={account.id} value={account.id}>{account.name}</option>)}</select>
              <Button size="small" appearance="secondary" disabled={!accountId || receive.isPending} onClick={() => {
                const key = receiptKeys[income.id!] ?? createUuid();
                setReceiptKeys((current) => ({ ...current, [income.id!]: key }));
                const amountMinor = decimalToMinor(income.amountDecimal);
                const receivedMinor = decimalToMinor(income.receivedAmountDecimal ?? '0.00');
                const pendingMinor = amountMinor === null || receivedMinor === null ? null : amountMinor - receivedMinor;
                if (pendingMinor === null || pendingMinor <= 0n) return;
                receive.mutate({ id: income.id!, amount: minorToDecimal(pendingMinor), key });
              }}>Confirmar recibido</Button>
            </>}
          </div>
        </div>)}
      </div>}
    </Card>
  </div>;
}
