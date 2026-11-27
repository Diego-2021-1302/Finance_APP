import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Card, Caption1, Field, Input, MessageBar, MessageBarBody, Subtitle1 } from '@fluentui/react-components';
import { api, createUuid } from '../api';
import { EmptyState, ErrorState, LoadingState } from '../components/DataState';
import type { AccountRecord, CommitmentRecord, DebtRecord, ExpenseRecord } from '../types';
import { Bar, BarChart, CartesianGrid, LabelList, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

const now = new Date();
const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;

function bcPositive(value: string): boolean {
  return Number(value.replace(/[^0-9.-]/g, '')) > 0;
}

function addMonthsToDate(date: Date, months: number): Date {
  const result = new Date(date.getFullYear(), date.getMonth() + months, 1);
  const day = date.getDate();
  result.setDate(Math.min(day, new Date(result.getFullYear(), result.getMonth() + 1, 0).getDate()));
  return result;
}

function isoDateFromDisplay(value: string): string | null {
  const match = value.match(/^(\d{4}-\d{2}-\d{2})/);
  if (match) return match[1];
  return null;
}

function money(value: string) {
  const number = Number(value.replace(/[^0-9.-]/g, ''));
  return new Intl.NumberFormat('es-DO', { style: 'currency', currency: 'DOP' }).format(Number.isFinite(number) ? number : 0);
}

export function SimpleDashboardPage() {
  const accounts = useQuery({ queryKey: ['accounts'], queryFn: api.listAccounts });
  const debts = useQuery({ queryKey: ['debts'], queryFn: api.listDebts });
  const expenses = useQuery({ queryKey: ['expenses'], queryFn: () => api.listExpenses() });
  const commitments = useQuery({ queryKey: ['commitments'], queryFn: () => api.listCommitments({ status: 'active' }) });
  const debtHistory = useQuery({ queryKey: ['debt-history'], queryFn: api.getDebtHistory });

  if (accounts.isPending || debts.isPending || expenses.isPending || commitments.isPending || debtHistory.isPending) return <LoadingState />;
  if (accounts.isError || debts.isError || expenses.isError || commitments.isError || debtHistory.isError) {
    return <ErrorState onRetry={() => void Promise.all([accounts.refetch(), debts.refetch(), expenses.refetch(), commitments.refetch(), debtHistory.refetch()])} />;
  }

  const balance = accounts.data.reduce((sum, account) => sum + Number(account.balance.replace(/[^0-9.-]/g, '')), 0);
  const debt = debts.data.reduce((sum, item) => sum + Number(item.outstanding.replace(/[^0-9.-]/g, '')), 0);
  const monthExpenses = expenses.data
    .filter((item) => item.occurred_at.startsWith(today.slice(0, 7)))
    .reduce((sum, item) => sum + Number(item.amount.replace(/[^0-9.-]/g, '')), 0);

  const monthlyItems = [
    ...commitments.data.commitments.filter((item) => isoDateFromDisplay(item.dueDate)?.startsWith(today.slice(0, 7))).map((item) => ({
      id: item.id || item.commitmentId,
      name: item.name,
      date: item.dueDateIso || item.dueDate,
      amount: item.amount,
      source: 'Compromiso',
      detail: 'Compromiso pendiente',
    })),
    ...debts.data
      .filter((item) => {
        if (!bcPositive(item.outstanding)) return false;
        const iso = item.kind === 'credit_card'
          ? (item.nextCutoffDate || item.dueDateIso || isoDateFromDisplay(item.dueDate))
          : (item.dueDateIso || isoDateFromDisplay(item.dueDate));
        return iso?.startsWith(today.slice(0, 7));
      })
      .map((item) => ({
        id: item.id,
        name: item.account,
        date: item.kind === 'credit_card'
          ? (item.nextCutoffDate || item.dueDateIso || item.dueDate)
          : (item.dueDateIso || item.dueDate),
        amount: item.kind === 'credit_card' ? (item.statementBalance || 'RD$ 0.00') : (item.minimum || 'RD$ 0.00'),
        source: item.kind === 'credit_card' ? 'Tarjeta de crédito' : item.kind === 'loan' ? 'Préstamo' : 'Deuda',
        detail: item.kind === 'credit_card'
          ? `Pago mínimo ${item.minimum || 'RD$ 0.00'} · Próximo corte ${item.nextCutoffDate ? new Date(`${item.nextCutoffDate}T12:00:00`).toLocaleDateString('es-DO') : 'por calcular'}`
          : item.kind === 'loan'
            ? `Cuota fija ${item.minimum || 'RD$ 0.00'}`
            : 'Deuda pendiente',
      })),
  ];
  const monthlyTotal = monthlyItems.reduce((sum, item) => sum + Number(item.amount.replace(/[^0-9.-]/g, '')), 0);
  const currentMonth = today.slice(0, 7);
  const [currentYear, currentMonthNumber] = currentMonth.split('-').map(Number);
  const nextMonthDate = addMonthsToDate(new Date(currentYear, currentMonthNumber - 1, 1), 1);
  const nextMonth = `${nextMonthDate.getFullYear()}-${String(nextMonthDate.getMonth() + 1).padStart(2, '0')}`;

  const getDebtItemsForMonth = (month: string) => {
    const items: typeof monthlyItems = [];
    debts.data.filter((item) => bcPositive(item.outstanding)).forEach((item) => {
      const iso = item.kind === 'credit_card'
        ? (item.nextCutoffDate || item.dueDateIso || isoDateFromDisplay(item.dueDate))
        : (item.dueDateIso || isoDateFromDisplay(item.dueDate));
      if (!iso) return;

      const start = new Date(`${iso}T12:00:00`);
      let cursor = new Date(start);

      if (item.kind === 'credit_card') {
        const cursorMonth = `${cursor.getFullYear()}-${String(cursor.getMonth() + 1).padStart(2, '0')}`;
        if (cursorMonth === month) {
          items.push({
            id: item.id,
            name: item.account,
            date: `${cursor.getFullYear()}-${String(cursor.getMonth() + 1).padStart(2, '0')}-${String(cursor.getDate()).padStart(2, '0')}`,
            amount: item.statementBalance || 'RD$ 0.00',
            source: 'Tarjeta de crédito',
            detail: `Balance al corte ${item.statementBalance || 'RD$ 0.00'} · Pago mínimo ${item.minimum || 'RD$ 0.00'} · Próximo corte ${item.nextCutoffDate ? new Date(`${item.nextCutoffDate}T12:00:00`).toLocaleDateString('es-DO') : 'por calcular'}`,
          });
        }
        return;
      }
      while (cursor.getFullYear() < start.getFullYear() + 2) {
        const cursorMonth = `${cursor.getFullYear()}-${String(cursor.getMonth() + 1).padStart(2, '0')}`;
        if (cursorMonth === month) {
          items.push({
            id: item.id,
            name: item.account,
            date: `${cursor.getFullYear()}-${String(cursor.getMonth() + 1).padStart(2, '0')}-${String(cursor.getDate()).padStart(2, '0')}`,
            amount: item.kind === 'credit_card' ? (item.statementBalance || 'RD$ 0.00') : (item.minimum || 'RD$ 0.00'),
            source: item.kind === 'credit_card' ? 'Tarjeta de crédito' : item.kind === 'loan' ? 'Préstamo' : 'Deuda',
            detail: item.kind === 'credit_card'
              ? `Balance al corte ${item.statementBalance || 'RD$ 0.00'} · Pago mínimo ${item.minimum || 'RD$ 0.00'} · Próximo corte ${item.nextCutoffDate ? new Date(`${item.nextCutoffDate}T12:00:00`).toLocaleDateString('es-DO') : 'por calcular'}`
              : item.kind === 'loan'
                ? `Cuota fija ${item.minimum || 'RD$ 0.00'}`
                : 'Deuda pendiente',
          });
        }
        if (cursorMonth > month) break;

        if (item.kind === 'loan' && item.amortizationType === 'biweekly') {
          cursor = new Date(cursor.getTime() + 15 * 86400000);
        } else {
          cursor = addMonthsToDate(cursor, 1);
        }
      }
    });

    commitments.data.commitments.forEach((item) => {
      const iso = item.dueDateIso || isoDateFromDisplay(item.dueDate);
      if (iso?.startsWith(month)) {
        items.push({ id: item.id || item.commitmentId, name: item.name, date: iso, amount: item.amount, source: 'Compromiso', detail: 'Compromiso pendiente' });
      }
    });

    return items;
  };

  const nextMonthItems = getDebtItemsForMonth(nextMonth);
  const nextMonthTotal = nextMonthItems.reduce((sum, item) => sum + Number(item.amount.replace(/[^0-9.-]/g, '')), 0);

  const debtHistoryChart = debtHistory.data.map((item) => ({
    ...item,
    total: Number(item.total_debt),
  }));
  const formatMoney = (value: number) => new Intl.NumberFormat('es-DO', {
    style: 'currency', currency: 'DOP',
  }).format(Number.isFinite(value) ? value : 0);

  const monthlyItemsByDate = monthlyItems.reduce<Record<string, typeof monthlyItems>>((groups, item) => {
    const date = isoDateFromDisplay(item.date) || today;
    (groups[date] ||= []).push(item);
    return groups;
  }, {});

  return (
    <div style={{ display: 'grid', gap: '16px' }}>
      <div className="metric-grid">
        <Card className="metric-card"><Caption1>Dinero en cuentas</Caption1><div className="metric-value">{money(String(balance))}</div></Card>
        <Card className="metric-card"><Caption1>Gastos este mes</Caption1><div className="metric-value">{money(String(monthExpenses))}</div></Card>
        <Card className="metric-card"><Caption1>Deudas pendientes</Caption1><div className="metric-value">{money(String(debt))}</div></Card>
        <Card className="metric-card"><Caption1>Compromisos y deudas pendientes</Caption1><div className="metric-value">{money(String(monthlyTotal))}</div><Caption1>Mes actual</Caption1></Card>
      </div>
      <Card className="content-card">
        <div className="section-title">
          <div><Subtitle1>Progreso mensual de la deuda</Subtitle1><Caption1>Saldo total al cierre de cada mes</Caption1></div>
        </div>
        {debtHistoryChart.length === 0 ? (
          <div className="empty-inline" style={{ marginTop: '12px' }}>Aún no existe historial mensual.</div>
        ) : (
          <div style={{ height: 280, marginTop: '16px' }}>
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={debtHistoryChart} margin={{ top: 28, right: 8, left: 0, bottom: 8 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--border-subtle)" />
                <XAxis dataKey="label" tick={{ fill: 'var(--text)', fontSize: 12 }} />
                <YAxis tickFormatter={(value) => `RD$ ${Number(value).toLocaleString('es-DO')}`} tick={{ fill: 'var(--text)', fontSize: 11 }} />
                <Tooltip formatter={(value) => [formatMoney(Number(value)), 'Deuda total']} labelFormatter={(label, payload) => payload?.[0]?.payload?.source === 'current_estimate' ? `${label} · Actual estimado` : `${label} · Cierre`} />
                <Bar dataKey="total" name="Deuda total" fill="#5f9cff" radius={[4, 4, 0, 0]}>
                  <LabelList
                    dataKey="total"
                    position="top"
                    formatter={(value: number) => formatMoney(Number(value))}
                    fill="var(--text)"
                    fontSize={11}
                  />
                </Bar>
              </BarChart>
            </ResponsiveContainer>
          </div>
        )}
      </Card>
      <Card className="content-card">
        <Subtitle1>Acciones rápidas</Subtitle1>
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: '10px', marginTop: '12px' }}>
          <Button as="a" href="/expenses" appearance="primary">Registrar gasto</Button>
          <Button as="a" href="/accounts" appearance="secondary">Agregar dinero</Button>
          <Button as="a" href="/debts" appearance="secondary">Registrar deuda</Button>
          <Button as="a" href="/commitments" appearance="secondary">Agregar compromiso</Button>
        </div>
      </Card>
      <Card className="content-card">
        <div className="section-title"><Subtitle1>Detalle del mes actual</Subtitle1><strong>{money(String(monthlyTotal))}</strong></div>
        <Caption1>{today.slice(0, 7)} · Tarjetas, préstamos y compromisos</Caption1>
        <div style={{ display: 'grid', gap: '8px', marginTop: '12px' }}>
          {Object.entries(monthlyItemsByDate).sort(([a], [b]) => a.localeCompare(b)).map(([date, items]) => (
            <div key={date} style={{ borderTop: '1px solid var(--border-subtle)', paddingTop: '8px' }}>
              <strong>{new Date(`${date}T12:00:00`).toLocaleDateString('es-DO', { day: 'numeric', month: 'long', year: 'numeric' })}</strong>
              {items.map((item) => (
                <div className="record-heading" key={`${item.source}-${item.id}`}>
                  <div><strong>{item.name}</strong><Caption1>{item.source}{'detail' in item && item.detail ? ` · ${item.detail}` : ''}</Caption1></div>
                  <strong>{item.amount}</strong>
                </div>
              ))}
            </div>
          ))}
          {commitments.data.commitments.length === 0 && debts.data.length === 0 && <EmptyState label="No hay compromisos o deudas pendientes este mes." />}
        </div>
      </Card>

      <Card className="content-card">
        <div className="section-title"><Subtitle1>Compromisos y deudas del mes siguiente</Subtitle1><strong>{money(String(nextMonthTotal))}</strong></div>
        <Caption1>{nextMonth} · Tarjetas, préstamos y compromisos</Caption1>
        <div style={{ display: 'grid', gap: '8px', marginTop: '12px' }}>
          {nextMonthItems.length === 0 ? <EmptyState label="No hay compromisos o deudas pendientes el mes siguiente." /> : (
            (() => {
              const groups = nextMonthItems.reduce<Record<string, typeof nextMonthItems>>((result, item) => {
                (result[item.date] ||= []).push(item);
                return result;
              }, {});
              return Object.entries(groups).sort(([a], [b]) => a.localeCompare(b)).map(([date, items]) => (
                <div key={date} style={{ borderTop: '1px solid var(--border-subtle)', paddingTop: '8px' }}>
                  <strong>{new Date(`${date}T12:00:00`).toLocaleDateString('es-DO', { day: 'numeric', month: 'long', year: 'numeric' })}</strong>
                  {items.map((item) => (
                    <div className="record-heading" key={`${item.source}-${item.id}-${item.date}`}>
                      <div><strong>{item.name}</strong><Caption1>{item.source}{'detail' in item && item.detail ? ` · ${item.detail}` : ''}</Caption1></div>
                      <strong>{item.amount}</strong>
                    </div>
                  ))}
                </div>
              ));
            })()
          )}
        </div>
      </Card>

      <Card className="content-card">
        <Subtitle1>Mis cuentas</Subtitle1>
        {accounts.data.length === 0 ? <EmptyState label="Aún no tienes cuentas." /> : <div style={{ display: 'grid', gap: '8px', marginTop: '10px' }}>
          {accounts.data.map((account) => <div className="record-heading" key={account.id}><span>{account.name}</span><strong>{account.balance}</strong></div>)}
        </div>}
      </Card>
    </div>
  );
}

export function SimpleAccountsPage() {
  const [showCreate, setShowCreate] = useState(false);
  const [depositAccount, setDepositAccount] = useState<AccountRecord | null>(null);
  const [error, setError] = useState('');
  const queryClient = useQueryClient();
  const accounts = useQuery({ queryKey: ['accounts'], queryFn: api.listAccounts });

  const create = useMutation({
    mutationFn: api.createFinancialAccount,
    onSuccess: async () => { await queryClient.invalidateQueries(); setShowCreate(false); },
    onError: (cause) => setError(cause.message),
  });
  const deposit = useMutation({
    mutationFn: ({ id, amount, description, occurred_at }: { id: string; amount: string; description: string; occurred_at: string }) => api.depositToAccount(id, { amount, description, occurred_at, idempotency_key: createUuid() }),
    onSuccess: async () => { await queryClient.invalidateQueries(); setDepositAccount(null); },
    onError: (cause) => setError(cause.message),
  });
  const remove = useMutation({
    mutationFn: api.deleteFinancialAccount,
    onSuccess: async () => { await queryClient.invalidateQueries(); },
    onError: (cause) => setError(cause.message),
  });

  if (accounts.isPending) return <LoadingState />;
  if (accounts.isError) return <ErrorState onRetry={() => void accounts.refetch()} />;

  return <>
    <div className="page-intro"><p>Crea tus cuentas y registra depósitos o retiros de efectivo.</p><Button appearance="primary" onClick={() => setShowCreate((value) => !value)}>{showCreate ? 'Cancelar' : 'Agregar cuenta'}</Button></div>
    {error && <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar>}
    {showCreate && <Card className="inline-form"><Subtitle1>Nueva cuenta</Subtitle1><form className="finance-form" onSubmit={(event) => {
      event.preventDefault(); setError(''); const data = new FormData(event.currentTarget);
      create.mutate({ name: String(data.get('name')), institution: String(data.get('institution') || ''), type: String(data.get('type')), currency: 'DOP', opening_balance: String(data.get('opening_balance') || '0') });
    }}><div className="form-grid">
      <Field label="Nombre" required><Input name="name" required maxLength={160} placeholder="ej. Cuenta principal" /></Field>
      <Field label="Banco o lugar"><Input name="institution" maxLength={160} placeholder="ej. Banco Popular" /></Field>
      <Field label="Tipo" required><select name="type" defaultValue="checking"><option value="checking">Banco / Corriente</option><option value="savings">Ahorro</option><option value="cash">Efectivo</option><option value="other">Otro</option></select></Field>
      <Field label="Dinero inicial (DOP)"><Input name="opening_balance" type="number" step="0.01" defaultValue="0" /></Field>
    </div><Button type="submit" appearance="primary" disabled={create.isPending}>Guardar cuenta</Button></form></Card>}

    {depositAccount && <Card className="inline-form"><Subtitle1>Agregar dinero a {depositAccount.name}</Subtitle1><form className="finance-form" onSubmit={(event) => {
      event.preventDefault(); setError(''); const data = new FormData(event.currentTarget);
      deposit.mutate({ id: depositAccount.id, amount: String(data.get('amount')), description: String(data.get('description') || 'Depósito'), occurred_at: String(data.get('occurred_at')) });
    }}><div className="form-grid">
      <Field label="Monto (DOP)" required><Input name="amount" type="number" min="0.01" step="0.01" required /></Field>
      <Field label="Concepto" required><Input name="description" required defaultValue="Depósito" /></Field>
      <Field label="Fecha" required><Input name="occurred_at" type="date" defaultValue={today} required /></Field>
    </div><div style={{ display: 'flex', gap: '8px' }}><Button type="submit" appearance="primary" disabled={deposit.isPending}>Agregar dinero</Button><Button type="button" appearance="subtle" onClick={() => setDepositAccount(null)}>Cancelar</Button></div></form></Card>}

    {accounts.data.length === 0 ? <EmptyState label="No hay cuentas registradas." /> : <section className="record-list">{accounts.data.map((account) => <Card className="content-card record-card" key={account.id}><div className="record-heading"><div><Subtitle1>{account.name}</Subtitle1><Caption1>{account.type === 'credit_card' ? `Tarjeta de crédito${account.institution ? ` · ${account.institution}` : ''}` : (account.institution || 'Cuenta propia')}</Caption1></div><strong>{account.balance}</strong></div>{account.type === 'credit_card' && <div style={{ display: 'grid', gap: '6px', marginTop: '10px' }}><div style={{ color: '#2563eb', fontWeight: 700 }}>Límite aprobado: {account.approvedCreditLimit || 'RD$ 0.00'}</div><Caption1>Límite adicional con sobregiro: {account.authorizedOverdraftLimit || 'RD$ 0.00'}</Caption1><Caption1>Saldo utilizado: {account.outstandingAmount || 'RD$ 0.00'}</Caption1><Caption1 style={{ color: '#2563eb', fontWeight: 700 }}>Disponible para usar: {account.availableCreditCapacity || account.balance}</Caption1></div>}<div style={{ display: 'flex', gap: '8px', marginTop: '10px' }}>{account.type !== 'credit_card' && <><Button appearance="secondary" size="small" onClick={() => setDepositAccount(account)}>Agregar dinero</Button><Button appearance="subtle" size="small" style={{ color: '#ff4d4f' }} onClick={() => { if (confirm(`¿Eliminar la cuenta "${account.name}"?`)) remove.mutate(account.id); }}>Eliminar</Button></>}</div></Card>)}</section>}
  </>;
}

function CardDatePreview({ statementDay, graceDays }: { statementDay: number; graceDays: number }) {
  const today = new Date();
  const cutoff = new Date(today.getFullYear(), today.getMonth(), 1);
  cutoff.setDate(Math.min(statementDay, new Date(today.getFullYear(), today.getMonth() + 1, 0).getDate()));
  if (cutoff < new Date(today.getFullYear(), today.getMonth(), today.getDate())) {
    cutoff.setMonth(cutoff.getMonth() + 1);
    cutoff.setDate(Math.min(statementDay, new Date(cutoff.getFullYear(), cutoff.getMonth() + 1, 0).getDate()));
  }
  const due = new Date(cutoff);
  due.setDate(due.getDate() + (Number(graceDays) || 0));
  const format = (date: Date) => `${String(date.getDate()).padStart(2, '0')}/${String(date.getMonth() + 1).padStart(2, '0')}/${date.getFullYear()}`;

  return <><Caption1>Próximo corte calculado: {format(cutoff)}</Caption1><Caption1>Vencimiento calculado: {format(due)}</Caption1></>;
}

export function SimpleExpensesPage() {
  const [showCreate, setShowCreate] = useState(false);
  const [error, setError] = useState('');
  const queryClient = useQueryClient();
  const accounts = useQuery({ queryKey: ['accounts'], queryFn: api.listAccounts });
  const expenses = useQuery({ queryKey: ['expenses'], queryFn: () => api.listExpenses(), refetchOnMount: 'always' });
  const create = useMutation({ mutationFn: api.createExpense, onSuccess: async () => { await queryClient.invalidateQueries(); setShowCreate(false); }, onError: (cause) => setError(cause.message) });
  const remove = useMutation({ mutationFn: api.deleteExpense, onSuccess: async () => { await queryClient.invalidateQueries(); }, onError: (cause) => setError(cause.message) });

  if (accounts.isPending || expenses.isPending) return <LoadingState />;
  if (accounts.isError) return <ErrorState onRetry={() => void accounts.refetch()} />;
  if (expenses.isError) return <ErrorState onRetry={() => void expenses.refetch()} />;

  const liquidAccounts = accounts.data;

  return <>
    <div className="page-intro"><p>Registra cuánto gastaste, cuándo y desde cuál cuenta.</p><Button appearance="primary" onClick={() => setShowCreate((value) => !value)}>{showCreate ? 'Cancelar' : 'Registrar gasto'}</Button></div>
    {error && <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar>}
    {liquidAccounts.length === 0 && <MessageBar intent="warning"><MessageBarBody>Crea una cuenta antes de registrar gastos.</MessageBarBody></MessageBar>}
    {showCreate && <Card className="inline-form"><Subtitle1>Nuevo gasto</Subtitle1><form className="finance-form" onSubmit={(event) => {
      event.preventDefault(); setError(''); const data = new FormData(event.currentTarget);
      const accountId = String(data.get('account_id'));
      const selectedAccount = accounts.data.find((account) => account.id === accountId);
      const amount = String(data.get('amount'));
      if (selectedAccount?.type === 'credit_card' && selectedAccount.debtId) {
        api.previewCreditCardAuthorization(selectedAccount.debtId, amount).then((preview) => {
          if (preview.uses_overdraft && !window.confirm(`Esta compra utilizará sobregiro. El límite aprobado es ${preview.approved_limit} y el sobregiro disponible es limitado. El sobregiro puede generar intereses y cargos adicionales. ¿Deseas continuar?`)) return;
          create.mutate({ account_id: accountId, category: String(data.get('category')), amount, occurred_at: String(data.get('occurred_at')), description: String(data.get('description')), idempotency_key: createUuid(), confirm_overdraft: preview.uses_overdraft });
        }).catch((cause) => setError(cause instanceof Error ? cause.message : 'No se pudo evaluar la tarjeta.'));
        return;
      }
      create.mutate({ account_id: accountId, category: String(data.get('category')), amount, occurred_at: String(data.get('occurred_at')), description: String(data.get('description')), idempotency_key: createUuid() });
    }}><div className="form-grid">
      <Field label="Concepto" required><Input name="description" required maxLength={200} placeholder="ej. Supermercado" /></Field>
      <Field label="Monto (DOP)" required><Input name="amount" type="number" min="0.01" step="0.01" required /></Field>
      <Field label="Cuenta" required><select name="account_id" required defaultValue=""><option value="" disabled>Seleccionar cuenta</option>{liquidAccounts.map((account) => <option key={account.id} value={account.id}>{account.name} ({account.type === 'credit_card' ? (account.availableCreditCapacity || account.balance) : account.balance})</option>)}</select></Field>
      <Field label="Fecha" required><Input name="occurred_at" type="date" defaultValue={today} required /></Field>
      <Field label="Tipo"><select name="category" defaultValue="Otros"><option>Alimentación</option><option>Transporte</option><option>Servicios</option><option>Salud</option><option>Educación</option><option>Entretenimiento</option><option>Otros</option></select></Field>
    </div><Button type="submit" appearance="primary" disabled={create.isPending || liquidAccounts.length === 0}>Guardar gasto</Button></form></Card>}

    {expenses.data.length === 0 ? <EmptyState label="No hay gastos registrados." /> : <section className="record-list">{expenses.data.map((expense: ExpenseRecord) => <Card className="content-card record-card" key={expense.id}><div className="record-heading"><div><Subtitle1>{expense.description}</Subtitle1><Caption1>{expense.category} · {expense.account} · {expense.occurred_at}</Caption1></div><strong>{expense.amount}</strong></div><div style={{ textAlign: 'right', marginTop: '8px' }}><Button appearance="subtle" size="small" style={{ color: '#ff4d4f' }} onClick={() => { if (confirm('¿Eliminar este gasto?')) remove.mutate(expense.id!); }}>Eliminar</Button></div></Card>)}</section>}
  </>;
}

export function SimpleDebtsPage() {
  const [showCreate, setShowCreate] = useState(false);
  const [debtType, setDebtType] = useState('loan');
  const [cardApproved, setCardApproved] = useState('');
  const [cardOverdraft, setCardOverdraft] = useState('');
  const [cardBalance, setCardBalance] = useState('');
  const [cardCutoffDay, setCardCutoffDay] = useState('');
  const [cardPaymentDays, setCardPaymentDays] = useState('25');
  const [editingDebt, setEditingDebt] = useState<DebtRecord | null>(null);
  const [cardPaymentType, setCardPaymentType] = useState<'total' | 'statement' | 'minimum' | 'custom'>('custom');
  const [error, setError] = useState('');
  const queryClient = useQueryClient();
  const debts = useQuery({ queryKey: ['debts'], queryFn: api.listDebts });
  const accounts = useQuery({ queryKey: ['accounts'], queryFn: api.listAccounts });
  const create = useMutation({ mutationFn: api.createDebt, onSuccess: async () => { await queryClient.invalidateQueries(); setShowCreate(false); }, onError: (cause) => setError(cause.message) });
  const update = useMutation({ mutationFn: ({ id, input }: { id: string; input: Parameters<typeof api.updateDebt>[1] }) => api.updateDebt(id, input), onSuccess: async () => { await queryClient.invalidateQueries(); setEditingDebt(null); }, onError: (cause) => setError(cause.message) });
  const remove = useMutation({ mutationFn: api.deleteDebt, onSuccess: async () => { await queryClient.invalidateQueries(); }, onError: (cause) => setError(cause.message) });
  const pay = useMutation({ mutationFn: ({ id, input }: { id: string; input: { amount: string; account_id: string | null; paid_at: string; idempotency_key: string; payment_type?: 'total' | 'statement' | 'minimum' | 'custom' } }) => api.recordDebtPayment(id, input), onSuccess: async () => { await queryClient.invalidateQueries(); }, onError: (cause) => setError(cause.message) });

  if (debts.isPending || accounts.isPending) return <LoadingState />;
  if (debts.isError) return <ErrorState onRetry={() => void debts.refetch()} />;
  if (accounts.isError) return <ErrorState onRetry={() => void accounts.refetch()} />;

  const liquidAccounts = accounts.data.filter((account) => account.type !== 'credit_card');

  return <>
    <div className="page-intro"><p>Registra lo que debes y abona sin cálculos de intereses.</p><Button appearance="primary" onClick={() => setShowCreate((value) => !value)}>{showCreate ? 'Cancelar' : 'Agregar deuda'}</Button></div>
    {error && <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar>}
    {showCreate && <Card key={`create-debt-${debtType}`} className="inline-form"><Subtitle1>Nueva deuda</Subtitle1><form className="finance-form" onSubmit={(event) => {
      event.preventDefault(); setError(''); const data = new FormData(event.currentTarget);
      const kind = String(data.get('kind'));
      if (kind === 'credit_card') {
        const approved = Number(data.get('approved_credit_limit') || 0);
        const balance = Number(data.get('outstanding_amount') || 0);
        if (balance > approved) {
          const overdraft = balance - approved;
          if (!window.confirm(`El balance supera el límite aprobado por RD$ ${overdraft.toLocaleString('es-DO', { minimumFractionDigits: 2 })}. Esto utilizará sobregiro y puede generar intereses o cargos adicionales. ¿Confirmas el registro?`)) return;
        }
        create.mutate({
          name: String(data.get('card_product_name')),
          kind,
          card_product_name: String(data.get('card_product_name')),
          institution: String(data.get('institution')),
          approved_credit_limit: String(data.get('approved_credit_limit')),
          authorized_overdraft_limit: String(data.get('authorized_overdraft_limit') || '0'),
          outstanding_amount: String(data.get('outstanding_amount') || '0'),
          statement_balance: String(data.get('statement_balance') || '0'),
          minimum_payment: String(data.get('minimum_payment') || '0'),
          statement_day: Number(data.get('statement_day')),
          payment_grace_days: Number(data.get('payment_grace_days')),
          due_date: null,
          last_four: String(data.get('last_four') || '') || null,
          idempotency_key: createUuid(),
        });
        return;
      }
      if (kind === 'loan') {
        create.mutate({
          name: String(data.get('name')),
          kind,
          loan_number: String(data.get('loan_number') || '') || null,
          institution: String(data.get('institution') || '') || null,
          outstanding_amount: String(data.get('outstanding_amount')),
          installment_amount: String(data.get('installment_amount')),
          next_payment_date: String(data.get('next_payment_date')),
          recurrence: String(data.get('recurrence')) as 'biweekly' | 'monthly',
          maturity_date: String(data.get('maturity_date')),
          due_date: null,
          last_four: null,
          idempotency_key: createUuid(),
        });
        return;
      }
      create.mutate({ name: String(data.get('name')), kind, outstanding_amount: String(data.get('outstanding_amount')), due_date: String(data.get('due_date') || '') || null, last_four: null, idempotency_key: createUuid() });
    }}>
      <div className="form-grid">
        <Field label="Tipo" required><select name="kind" value={debtType} onChange={(event) => {
          setDebtType(event.target.value);
          setCardPaymentType('custom');
        }}><option value="loan">Préstamo</option><option value="personal">Deuda personal</option><option value="credit_card">Tarjeta de crédito</option><option value="other">Otra</option></select></Field>
      </div>
      {debtType === 'credit_card' ? <div className="form-grid" key="credit-card-create">
        <Field label="Nombre del producto" required><Input name="card_product_name" required maxLength={160} placeholder="ej. Visa Gold" /></Field>
        <Field label="Banco emisor" required><Input name="institution" required maxLength={160} placeholder="ej. Banco Popular" /></Field>
        <Field label="Últimos 4 dígitos"><Input name="last_four" maxLength={4} inputMode="numeric" pattern="[0-9]{4}" placeholder="4321" /></Field>
        <Field label="Límite aprobado (DOP)" required><Input name="approved_credit_limit" type="number" min="0" step="0.01" required value={cardApproved} onChange={(event) => setCardApproved(event.target.value)} /></Field>
        <Field label="Saldo utilizado / Balance a la fecha (DOP)" required><Input name="outstanding_amount" type="number" min="0" step="0.01" required value={cardBalance} onChange={(event) => setCardBalance(event.target.value)} /></Field>
        <Field label="Balance al corte (DOP)" required><Input name="statement_balance" type="number" min="0" step="0.01" required /></Field>
        <Field label="Pago mínimo (DOP)" required><Input name="minimum_payment" type="number" min="0" step="0.01" required /></Field>
        <Field label="Día de corte" required><Input name="statement_day" type="number" min="1" max="31" required value={cardCutoffDay} onChange={(event) => setCardCutoffDay(event.target.value)} /></Field>
        <Field label="Días para el vencimiento" required><Input name="payment_grace_days" type="number" min="1" max="365" required value={cardPaymentDays} onChange={(event) => setCardPaymentDays(event.target.value)} /></Field>
        <Field label="Límite adicional con sobregiro (DOP)"><Input name="authorized_overdraft_limit" type="number" min="0" step="0.01" value={cardOverdraft} onChange={(event) => setCardOverdraft(event.target.value)} /></Field>
      </div> : debtType === 'loan' ? <div className="form-grid" key="loan-create">
        <Field label="Nombre del préstamo" required><Input name="name" required placeholder="ej. Préstamo vehicular" /></Field>
        <Field label="Número del préstamo" required><Input name="loan_number" required maxLength={80} placeholder="Número o referencia" /></Field>
        <Field label="Banco" required><Input name="institution" required maxLength={160} placeholder="ej. Banco Popular" /></Field>
        <Field label="Balance a la fecha (DOP)" required><Input name="outstanding_amount" type="number" min="0.01" step="0.01" required /></Field>
        <Field label="Monto fijo de la cuota (DOP)" required><Input name="installment_amount" type="number" min="0.01" step="0.01" required /></Field>
        <Field label="Fecha final del préstamo" required><Input name="maturity_date" type="date" defaultValue="" required /></Field>
        <Field label="Fecha del próximo pago" required><Input name="next_payment_date" type="date" defaultValue={today.match(/^\d{4}-\d{2}-\d{2}$/)?.[0] || ''} required /></Field>
        <Field label="Recurrencia" required><select name="recurrence" defaultValue="monthly"><option value="monthly">Mensual</option><option value="biweekly">Quincenal</option></select></Field>
      </div> : <div className="form-grid" key="simple-debt-create"><Field label="Nombre" required><Input name="name" required placeholder="ej. Deuda personal" /></Field><Field label="Monto pendiente (DOP)" required><Input name="outstanding_amount" type="number" min="0.01" step="0.01" required /></Field><Field label="Fecha de vencimiento"><Input name="due_date" type="date" /></Field></div>}
      {debtType === 'credit_card' && <div style={{ display: 'grid', gap: '8px', marginTop: '10px' }}>
        <div style={{ color: '#2563eb', fontWeight: 700 }}>Límite aprobado: DOP {Number(cardApproved || 0).toLocaleString('es-DO', { minimumFractionDigits: 2 })}</div>
        {Number(cardOverdraft || 0) > 0 && <Caption1>Capacidad total con sobregiro: DOP {(Number(cardApproved || 0) + Number(cardOverdraft || 0)).toLocaleString('es-DO', { minimumFractionDigits: 2 })}</Caption1>}
        {Number(cardCutoffDay) >= 1 && Number(cardCutoffDay) <= 31 && <CardDatePreview statementDay={Number(cardCutoffDay)} graceDays={Number(cardPaymentDays)} />}
        {Number(cardBalance || 0) > Number(cardApproved || 0) && <MessageBar intent="warning"><MessageBarBody>Advertencia: el balance actual supera el límite aprobado. Se está utilizando sobregiro y podrían generarse intereses o cargos adicionales.</MessageBarBody></MessageBar>}
      </div>}
      <Button type="submit" appearance="primary" disabled={create.isPending}>Guardar deuda</Button>
    </form></Card>}

    {editingDebt && <Card key={`edit-debt-${editingDebt.kind}-${editingDebt.id}`} className="inline-form"><Subtitle1>Editar deuda</Subtitle1><form className="finance-form" onSubmit={(event) => {
      event.preventDefault(); setError(''); const data = new FormData(event.currentTarget);
      const kind = String(data.get('kind') || editingDebt.kind || 'other');
      if (kind === 'credit_card') {
        update.mutate({ id: editingDebt.id!, input: {
          name: String(data.get('card_product_name') || editingDebt.account),
          kind,
          card_product_name: String(data.get('card_product_name') || editingDebt.account),
          institution: String(data.get('institution') || ''),
          last_four: String(data.get('last_four') || '') || null,
          approved_credit_limit: String(data.get('approved_credit_limit') || '0'),
          authorized_overdraft_limit: String(data.get('authorized_overdraft_limit') || '0'),
          outstanding_amount: String(data.get('outstanding_amount') || '0'),
          statement_balance: String(data.get('statement_balance') || '0'),
          minimum_payment: String(data.get('minimum_payment') || '') || null,
          statement_day: Number(data.get('statement_day')),
          payment_grace_days: Number(data.get('payment_grace_days')),
        } });
      } else if (kind === 'loan') {
        update.mutate({ id: editingDebt.id!, input: {
          name: String(data.get('name') || editingDebt.account),
          kind,
          loan_number: String(data.get('loan_number') || editingDebt.referenceNumber || '') || null,
          institution: String(data.get('institution') || editingDebt.institution || '') || null,
          outstanding_amount: String(data.get('outstanding_amount') || '0'),
          installment_amount: String(data.get('installment_amount') || editingDebt.minimum?.replace(/[^0-9.]/g, '') || '0'),
          next_payment_date: String(data.get('next_payment_date') || ''),
          recurrence: String(data.get('recurrence') || editingDebt.amortizationType || 'monthly') as 'biweekly' | 'monthly',
          maturity_date: String(data.get('maturity_date') || editingDebt.maturityDate || ''),
        } });
      } else {
        update.mutate({ id: editingDebt.id!, input: {
          name: String(data.get('name') || editingDebt.account),
          kind,
          outstanding_amount: String(data.get('outstanding_amount') || '0'),
          minimum_payment: String(data.get('minimum_payment') || '') || null,
          due_date: String(data.get('due_date') || '') || null,
        } });
      }
    }}>
      <input type="hidden" name="kind" value={editingDebt.kind || 'other'} />
      {editingDebt.kind === 'credit_card' ? <div className="form-grid" key={`edit-card-${editingDebt.id}`}>
        <Field label="Nombre del producto" required><Input name="card_product_name" defaultValue={editingDebt.cardProductName || editingDebt.account} required /></Field>
        <Field label="Banco emisor"><Input name="institution" defaultValue={editingDebt.institution || ''} /></Field>
        <Field label="Últimos 4 dígitos"><Input name="last_four" defaultValue={editingDebt.lastFour || ''} maxLength={4} inputMode="numeric" pattern="[0-9]{4}" /></Field>
        <Field label="Límite aprobado (DOP)" required><Input name="approved_credit_limit" type="number" min="0" step="0.01" defaultValue={editingDebt.approvedCreditLimit?.replace(/[^0-9.]/g, '') || '0'} required /></Field>
        <Field label="Saldo utilizado (DOP)" required><Input name="outstanding_amount" type="number" min="0" step="0.01" defaultValue={editingDebt.outstanding.replace(/[^0-9.]/g, '')} required /></Field>
        <Field label="Balance al corte (DOP)"><Input name="statement_balance" type="number" min="0" step="0.01" defaultValue={editingDebt.statementBalance?.replace(/[^0-9.]/g, '') || '0'} /></Field>
        <Field label="Pago mínimo (DOP)"><Input name="minimum_payment" type="number" min="0" step="0.01" defaultValue={editingDebt.minimum?.replace(/[^0-9.]/g, '') || '0'} /></Field>
        <Field label="Día de corte" required><Input name="statement_day" type="number" min="1" max="31" defaultValue={editingDebt.statementDay ? String(editingDebt.statementDay) : ''} required /></Field>
        <Field label="Días para el vencimiento" required><Input name="payment_grace_days" type="number" min="1" max="365" defaultValue={String(editingDebt.paymentGraceDays || 25)} required /></Field>
        <Field label="Sobregiro (DOP)"><Input name="authorized_overdraft_limit" type="number" min="0" step="0.01" defaultValue={editingDebt.authorizedOverdraftLimit?.replace(/[^0-9.]/g, '') || '0'} /></Field>
      </div> : editingDebt.kind === 'loan' ? <div className="form-grid" key={`edit-loan-${editingDebt.id}`}>
        <Field label="Nombre del préstamo" required><Input name="name" defaultValue={editingDebt.account} required /></Field>
        <Field label="Número del préstamo" required><Input name="loan_number" defaultValue={editingDebt.referenceNumber || ''} required /></Field>
        <Field label="Banco" required><Input name="institution" defaultValue={editingDebt.institution || ''} required /></Field>
        <Field label="Balance a la fecha (DOP)" required><Input name="outstanding_amount" type="number" min="0" step="0.01" defaultValue={editingDebt.outstanding.replace(/[^0-9.]/g, '')} required /></Field>
        <Field label="Monto fijo de la cuota (DOP)" required><Input name="installment_amount" type="number" min="0.01" step="0.01" defaultValue={editingDebt.minimum?.replace(/[^0-9.]/g, '') || '0'} required /></Field>
        <Field label="Fecha final del préstamo" required><Input name="maturity_date" type="date" defaultValue={editingDebt.maturityDate?.match(/^\d{4}-\d{2}-\d{2}/)?.[0] || ''} required /></Field>
        <Field label="Fecha del próximo pago" required><Input name="next_payment_date" type="date" defaultValue={(editingDebt.effectiveDueDate || editingDebt.dueDate || '').match(/^\d{4}-\d{2}-\d{2}/)?.[0] || ''} required /></Field>
        <Field label="Recurrencia" required><select name="recurrence" defaultValue={editingDebt.amortizationType || 'monthly'}><option value="monthly">Mensual</option><option value="biweekly">Quincenal</option></select></Field>
      </div> : <div className="form-grid" key={`edit-debt-${editingDebt.id}`}>
        <Field label="Nombre" required><Input name="name" defaultValue={editingDebt.account} required /></Field>
        <Field label="Monto pendiente (DOP)" required><Input name="outstanding_amount" type="number" min="0" step="0.01" defaultValue={editingDebt.outstanding.replace(/[^0-9.]/g, '')} required /></Field>
        <Field label="Pago mínimo (DOP)"><Input name="minimum_payment" type="number" min="0" step="0.01" defaultValue={editingDebt.minimum?.replace(/[^0-9.]/g, '') || '0'} /></Field>
      </div>}
      <div style={{ display: 'flex', gap: '8px' }}><Button type="submit" appearance="primary" disabled={update.isPending}>Guardar cambios</Button><Button type="button" appearance="subtle" onClick={() => setEditingDebt(null)}>Cancelar</Button></div>
    </form></Card>}

    {debts.data.length === 0 ? <EmptyState label="No hay deudas registradas." /> : (
      <section className="record-list">
        {debts.data.map((debt: DebtRecord) => (
          <Card className="content-card record-card" key={debt.id}>
            <div className="record-heading">
              <div>
                <Subtitle1>{debt.account}</Subtitle1>
                <Caption1>
                  {debt.kind === 'loan' ? 'Préstamo' : debt.kind === 'credit_card' ? 'Tarjeta' : 'Deuda'}
                </Caption1>
              </div>
              <strong>{debt.outstanding}</strong>
            </div>

            {debt.kind === 'loan' && (
              <div style={{ display: 'grid', gap: '6px', marginTop: '10px' }}>
                {debt.referenceNumber && <Caption1>Número: {debt.referenceNumber}</Caption1>}
                {debt.institution && <Caption1>Banco: {debt.institution}</Caption1>}
                <Caption1>Cuota fija: {debt.minimum || 'RD$ 0.00'}</Caption1>
                {debt.maturityDate && <Caption1>Finaliza: {debt.maturityDate}</Caption1>}
                {debt.remainingInstallments !== null && debt.remainingInstallments !== undefined && <Caption1>Cuotas pendientes: {debt.remainingInstallments}</Caption1>}
                {debt.dueDate !== '—' && <Caption1>Próximo pago: {debt.dueDate}</Caption1>}
                {debt.amortizationType && <Caption1>Recurrencia: {debt.amortizationType === 'biweekly' ? 'Quincenal' : 'Mensual'}</Caption1>}
              </div>
            )}

            {debt.kind === 'credit_card' && (
              <div style={{ display: 'grid', gap: '6px', marginTop: '10px' }}>
                <div style={{ color: '#2563eb', fontWeight: 700 }}>Límite aprobado: {debt.approvedCreditLimit || 'RD$ 0.00'}</div>
                <Caption1>Sobregiro disponible: {debt.authorizedOverdraftLimit || 'RD$ 0.00'}</Caption1>
                <Caption1>
                  Pago mínimo: {debt.minimum || 'RD$ 0.00'}
                  {debt.nextCutoffDate && ` · Próximo corte: ${new Date(`${debt.nextCutoffDate}T12:00:00`).toLocaleDateString('es-DO')}`}
                </Caption1>
                {debt.dueDate !== '—' && <Caption1>Vencimiento: {debt.dueDate}</Caption1>}
              </div>
            )}

            {liquidAccounts.length > 0 && (
              <details className="debt-action">
                <summary>Registrar abono</summary>
                <form className="debt-action-form" onSubmit={(event) => {
                  event.preventDefault();
                  const data = new FormData(event.currentTarget);
                  const type = debt.kind === 'credit_card'
                    ? String(data.get('payment_type') || 'custom') as 'total' | 'statement' | 'minimum' | 'custom'
                    : 'custom';
                  const amount = type === 'total'
                    ? debt.outstanding.replace(/[^0-9.]/g, '')
                    : type === 'statement'
                      ? (debt.statementBalance?.replace(/[^0-9.]/g, '') || '0')
                      : type === 'minimum'
                        ? (debt.minimum?.replace(/[^0-9.]/g, '') || '0')
                        : String(data.get('amount') || '0');
                  pay.mutate({ id: debt.id!, input: { amount, account_id: String(data.get('account_id')), paid_at: String(data.get('paid_at')), idempotency_key: createUuid(), payment_type: type } });
                }}>
                  <div className="form-grid">
                    {debt.kind === 'credit_card' && <>
                      <Field label="Tipo de pago" required>
                        <select key={`payment-type-${debt.id}-${cardPaymentType}`} name="payment_type" value={cardPaymentType} onChange={(event) => setCardPaymentType(event.target.value as 'total' | 'statement' | 'minimum' | 'custom')}>
                          <option value="total">Saldo total - Balance a la fecha</option>
                          <option value="statement">Pago al corte - Balance al corte</option>
                          <option value="minimum">Pago mínimo</option>
                          <option value="custom">Abono - monto libre</option>
                        </select>
                      </Field>
                      {cardPaymentType !== 'custom' && <Caption1>
                        Monto seleccionado: {cardPaymentType === 'total'
                          ? debt.outstanding
                          : cardPaymentType === 'statement'
                            ? (debt.statementBalance || 'RD$ 0.00')
                            : (debt.minimum || 'RD$ 0.00')}
                      </Caption1>}
                    </>}
                    {(debt.kind !== 'credit_card' || cardPaymentType === 'custom') && <Field label="Monto" required><Input name="amount" type="number" min="0.01" step="0.01" required /></Field>}
                    <Field label="Cuenta" required>
                      <select name="account_id" required defaultValue="">
                        <option value="" disabled>Seleccionar cuenta</option>
                        {liquidAccounts.map((account) => <option key={account.id} value={account.id}>{account.name}</option>)}
                      </select>
                    </Field>
                    <Field label="Fecha" required><Input name="paid_at" type="date" defaultValue={today} required /></Field>
                  </div>
                  <Button type="submit" appearance="primary" disabled={pay.isPending}>Abonar</Button>
                </form>
              </details>
            )}

            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '8px', marginTop: '10px' }}>
              <Button appearance="subtle" size="small" onClick={() => { setEditingDebt(debt); setShowCreate(false); }}>Editar</Button>
              <Button appearance="subtle" size="small" style={{ color: '#ff4d4f' }} onClick={() => { if (confirm(`¿Eliminar la deuda "${debt.account}"?`)) remove.mutate(debt.id!); }}>Eliminar</Button>
            </div>
          </Card>
        ))}
      </section>
    )}
  </>;
}

export function SimpleCommitmentsPage() {
  const [showCreate, setShowCreate] = useState(false);
  const [error, setError] = useState('');
  const queryClient = useQueryClient();
  const commitments = useQuery({ queryKey: ['commitments'], queryFn: () => api.listCommitments({ status: 'active', sort: 'due' }) });
  const accounts = useQuery({ queryKey: ['accounts'], queryFn: api.listAccounts });
  const create = useMutation({ mutationFn: api.createCommitment, onSuccess: async () => { await queryClient.invalidateQueries(); setShowCreate(false); }, onError: (cause) => setError(cause.message) });
  const remove = useMutation({ mutationFn: api.deleteCommitment, onSuccess: async () => { await queryClient.invalidateQueries(); }, onError: (cause) => setError(cause.message) });
  const pay = useMutation({ mutationFn: ({ id, occurrenceId, input }: { id: string; occurrenceId: string; input: { amount: string; account_id: string; paid_at: string; idempotency_key: string } }) => api.recordCommitmentPayment(id, occurrenceId, input), onSuccess: async () => { await queryClient.invalidateQueries(); }, onError: (cause) => setError(cause.message) });

  if (commitments.isPending || accounts.isPending) return <LoadingState />;
  if (commitments.isError) return <ErrorState onRetry={() => void commitments.refetch()} />;
  if (accounts.isError) return <ErrorState onRetry={() => void accounts.refetch()} />;

  const liquidAccounts = accounts.data.filter((account) => account.type !== 'credit_card');

  return <>
    <div className="page-intro"><p>Lista tus pagos recurrentes y registra cuándo los pagaste.</p><Button appearance="primary" onClick={() => setShowCreate((value) => !value)}>{showCreate ? 'Cancelar' : 'Agregar compromiso'}</Button></div>
    {error && <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar>}
    {showCreate && <Card className="inline-form"><Subtitle1>Nuevo compromiso</Subtitle1><form className="finance-form" onSubmit={(event) => {
      event.preventDefault(); setError(''); const data = new FormData(event.currentTarget);
      create.mutate({ name: String(data.get('name')), type: 'other', amount: String(data.get('amount')), frequency: String(data.get('frequency')), next_due_date: String(data.get('next_due_date')), category: String(data.get('category') || 'Otros'), notes: null });
    }}><div className="form-grid"><Field label="Nombre" required><Input name="name" required placeholder="ej. Internet" /></Field><Field label="Monto (DOP)" required><Input name="amount" type="number" min="0.01" step="0.01" required /></Field><Field label="Frecuencia" required><select name="frequency" defaultValue="monthly"><option value="once">Una sola vez</option><option value="weekly">Semanal</option><option value="biweekly">Cada dos semanas</option><option value="monthly">Mensual</option><option value="yearly">Anual</option></select></Field><Field label="Próximo vencimiento" required><Input name="next_due_date" type="date" defaultValue={today} required /></Field><Field label="Tipo"><select name="category" defaultValue="Otros"><option>Servicios</option><option>Alquiler</option><option>Seguro</option><option>Suscripción</option><option>Otro</option></select></Field></div><Button type="submit" appearance="primary" disabled={create.isPending}>Guardar compromiso</Button></form></Card>}

    {commitments.data.commitments.length === 0 ? <EmptyState label="No hay compromisos registrados." /> : <section className="record-list">{commitments.data.commitments.map((item: CommitmentRecord) => {
      const occurrence = commitments.data.occurrences.find((row) => row.commitmentId === item.id);
      return <Card className="content-card record-card" key={item.id}><div className="record-heading"><div><Subtitle1>{item.name}</Subtitle1><Caption1>{item.dueDate}</Caption1></div><strong>{item.amount}</strong></div>{occurrence && liquidAccounts.length > 0 && <details className="debt-action"><summary>Registrar pago</summary><form className="debt-action-form" onSubmit={(event) => {
        event.preventDefault(); const data = new FormData(event.currentTarget);
        pay.mutate({ id: item.commitmentId ?? item.id!, occurrenceId: String(occurrence.id), input: { amount: String(data.get('amount')), account_id: String(data.get('account_id')), paid_at: String(data.get('paid_at')), idempotency_key: createUuid() } });
      }}><div className="form-grid"><Field label="Monto" required><Input name="amount" type="number" min="0.01" step="0.01" defaultValue={item.amount.replace(/[^0-9.]/g, '')} required /></Field><Field label="Cuenta" required><select name="account_id" required defaultValue=""><option value="" disabled>Seleccionar cuenta</option>{liquidAccounts.map((account) => <option key={account.id} value={account.id}>{account.name}</option>)}</select></Field><Field label="Fecha" required><Input name="paid_at" type="date" defaultValue={today} required /></Field></div><Button type="submit" appearance="primary" disabled={pay.isPending}>Registrar pago</Button></form></details>}<div style={{ textAlign: 'right', marginTop: '8px' }}><Button appearance="subtle" size="small" style={{ color: '#ff4d4f' }} onClick={() => { if (confirm('¿Eliminar este compromiso?')) remove.mutate(item.commitmentId ?? item.id!); }}>Eliminar</Button></div></Card>;
    })}</section>}
  </>;
}
