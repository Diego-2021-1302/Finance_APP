import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Card, Caption1, Field, Input, MessageBar, MessageBarBody, Subtitle1 } from '@fluentui/react-components';
import { useState, type FormEvent } from 'react';
import { api, createUuid } from '../api';
import { EmptyState, ErrorState, LoadingState } from '../components/DataState';
import { FinanceTable } from '../components/FinanceTable';
import { DebtBalancesSection } from './DebtsPage';
import type { AccountRecord, CalendarEvent, CommitmentRecord, ExpenseRecord, IncomeRecord, TransactionRecord, TransferRecord } from '../types';

type RecordKind = 'income' | 'expenses' | 'transfers' | 'commitments' | 'calendar' | 'transactions' | 'accounts';
type CommitmentType = NonNullable<CommitmentRecord['type']>;
const commitmentTypes: Array<{ value: CommitmentType; label: string }> = [
  { value: 'loan', label: 'Préstamo' },
  { value: 'credit_card', label: 'Tarjeta de crédito' },
  { value: 'utility', label: 'Servicio público / Factura' },
  { value: 'subscription', label: 'Suscripción / Streaming' },
  { value: 'insurance', label: 'Seguro' },
  { value: 'rent', label: 'Alquiler / Vivienda' },
  { value: 'other', label: 'Otro' },
];
const billCategories = ['Electricidad', 'Agua', 'Internet', 'Teléfono', 'Streaming', 'Gas', 'Seguro', 'Alquiler', 'Educación', 'Alimentación', 'Combustible', 'Otros'];
const now = new Date();
const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;

export function RecordsPage({ kind }: { kind: RecordKind }) {
  if (kind === 'income') return <IncomePage />;
  if (kind === 'expenses') return <ExpensesPage />;
  if (kind === 'transfers') return <TransfersPage />;
  if (kind === 'commitments') return <CommitmentsPage />;
  if (kind === 'calendar') return <CalendarPage />;
  if (kind === 'transactions') return <TransactionsPage />;
  return <AccountsPage />;
}

function IncomePage() {
  const [showForm, setShowForm] = useState(false);
  const [error, setError] = useState('');
  const queryClient = useQueryClient();
  const incomes = useQuery({ queryKey: ['income'], queryFn: api.listIncomes });
  const accounts = useQuery({ queryKey: ['accounts'], queryFn: api.listAccounts });
  const create = useMutation({
    mutationFn: api.createIncome,
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['income'] }),
        queryClient.invalidateQueries({ queryKey: ['dashboard'] }),
        queryClient.invalidateQueries({ queryKey: ['cash-flow'] }),
        queryClient.invalidateQueries({ queryKey: ['calendar'] }),
      ]);
    },
    onError: (cause) => setError(cause.message),
  });
  const receive = useMutation({
    mutationFn: ({ id, input }: { id: string; input: { amount: string; account_id: string; received_at: string; idempotency_key: string } }) => api.recordReceipt(id, input),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['income'] }),
        queryClient.invalidateQueries({ queryKey: ['accounts'] }),
        queryClient.invalidateQueries({ queryKey: ['transactions'] }),
        queryClient.invalidateQueries({ queryKey: ['dashboard'] }),
        queryClient.invalidateQueries({ queryKey: ['cash-flow'] }),
      ]);
    },
    onError: (cause) => setError(cause.message),
  });

  function submitCreate(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError('');
    const form = event.currentTarget;
    const data = new FormData(form);
    create.mutate({
      source: String(data.get('source')),
      amount: String(data.get('amount')),
      expected_date: String(data.get('expected_date')),
    }, { onSuccess: () => { form.reset(); setShowForm(false); } });
  }

  function submitReceipt(event: FormEvent<HTMLFormElement>, income: IncomeRecord) {
    event.preventDefault();
    setError('');
    const form = event.currentTarget;
    const data = new FormData(form);
    receive.mutate({
      id: income.id!,
      input: {
        amount: String(data.get('amount')),
        account_id: String(data.get('account_id')),
        received_at: String(data.get('received_at')),
        idempotency_key: createUuid(),
      },
    }, { onSuccess: () => form.reset() });
  }

  if (incomes.isPending || accounts.isPending) return <LoadingState />;
  if (incomes.isError) return <ErrorState onRetry={() => void incomes.refetch()} />;
  if (accounts.isError) return <ErrorState onRetry={() => void accounts.refetch()} />;
  return <>
    <div className="page-intro">
      <p>Planifica ingresos esperados y registra los depósitos en tus cuentas.</p>
      <Button appearance="primary" onClick={() => setShowForm((value) => !value)}>{showForm ? 'Cancelar' : 'Agregar ingreso'}</Button>
    </div>
    {error && <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar>}
    {showForm && <Card className="inline-form">
      <div><Subtitle1>Nuevo ingreso esperado</Subtitle1><Caption1>Agrega una proyección de ingresos a tu flujo de caja.</Caption1></div>
      <form className="finance-form" onSubmit={submitCreate}>
        <div className="form-grid">
          <Field label="Fuente / Concepto" required><Input name="source" required maxLength={160} placeholder="ej. Salario o Venta" /></Field>
          <Field label="Monto esperado (DOP)" required><Input name="amount" type="number" min="0.01" step="0.01" required /></Field>
          <Field label="Fecha esperada" required><Input name="expected_date" type="date" defaultValue={today} required /></Field>
        </div>
        <Button type="submit" appearance="primary" disabled={create.isPending}>{create.isPending ? 'Guardando…' : 'Guardar ingreso'}</Button>
      </form>
    </Card>}
    {accounts.data.length === 0 && <MessageBar intent="warning"><MessageBarBody>Crea una cuenta antes de registrar depósitos de ingresos.</MessageBarBody></MessageBar>}
    {incomes.data.length === 0 ? <EmptyState label="No se han registrado ingresos esperados aún." /> : <section className="record-list">
      {incomes.data.map((income) => <Card className="content-card record-card" key={income.id}>
        <div className="record-heading"><div><Subtitle1>{income.source}</Subtitle1><Caption1>Esperado {income.expectedDate} · {income.status}</Caption1></div><strong>{income.amount}</strong></div>
        <Caption1>Recibido hasta ahora: {income.receivedAmount ?? 'RD$ 0.00'}</Caption1>
        {income.status !== 'Recibido' && accounts.data.length > 0 && <details className="debt-action">
          <summary>Registrar depósito en cuenta</summary>
          <form className="debt-action-form" onSubmit={(event) => submitReceipt(event, income)}>
            <Field label="Monto a depositar (DOP)" required><Input name="amount" type="number" min="0.01" step="0.01" required /></Field>
            <Field label="Depositar en cuenta" required><select name="account_id" required defaultValue=""><option value="" disabled>Seleccionar cuenta</option>{accounts.data.map((account) => <option key={account.id} value={account.id}>{account.name}</option>)}</select></Field>
            <Field label="Fecha de recepción" required><Input name="received_at" type="date" defaultValue={today} required /></Field>
            <Button type="submit" appearance="primary" disabled={receive.isPending}>Registrar depósito</Button>
          </form>
        </details>}
      </Card>)}
    </section>}
  </>;
}

function ExpensesPage() {
  const [showForm, setShowForm] = useState(false);
  const [error, setError] = useState('');
  const queryClient = useQueryClient();
  const expenses = useQuery({ queryKey: ['expenses'], queryFn: () => api.listExpenses() });
  const accounts = useQuery({ queryKey: ['accounts'], queryFn: api.listAccounts });
  const create = useMutation({
    mutationFn: api.createExpense,
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['expenses'] }),
        queryClient.invalidateQueries({ queryKey: ['accounts'] }),
        queryClient.invalidateQueries({ queryKey: ['transactions'] }),
        queryClient.invalidateQueries({ queryKey: ['dashboard'] }),
        queryClient.invalidateQueries({ queryKey: ['cash-flow'] }),
      ]);
    },
    onError: (cause) => setError(cause.message),
  });

  function submitCreate(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError('');
    const form = event.currentTarget;
    const data = new FormData(form);
    create.mutate({
      account_id: String(data.get('account_id')),
      category: String(data.get('category')),
      amount: String(data.get('amount')),
      occurred_at: String(data.get('occurred_at')),
      description: String(data.get('description')),
      reference_number: String(data.get('reference_number') || '') || null,
      idempotency_key: createUuid(),
    }, { onSuccess: () => { form.reset(); setShowForm(false); } });
  }

  if (expenses.isPending || accounts.isPending) return <LoadingState />;
  if (expenses.isError) return <ErrorState onRetry={() => void expenses.refetch()} />;
  if (accounts.isError) return <ErrorState onRetry={() => void accounts.refetch()} />;

  return <>
    <div className="page-intro">
      <p>Registra compras o consumos directos debitando inmediatamente de tu cuenta.</p>
      <Button appearance="primary" onClick={() => setShowForm((value) => !value)}>{showForm ? 'Cancelar' : 'Registrar gasto'}</Button>
    </div>
    {error && <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar>}
    {showForm && <Card className="inline-form">
      <div><Subtitle1>Nuevo gasto directo</Subtitle1><Caption1>Se creará un débito inmediato en la cuenta seleccionada.</Caption1></div>
      <form className="finance-form" onSubmit={submitCreate}>
        <div className="form-grid">
          <Field label="Descripción / Concepto" required><Input name="description" required maxLength={500} placeholder="ej. Supermercado o Combustible" /></Field>
          <Field label="Categoría" required><select name="category" required defaultValue="Alimentación">{billCategories.map((cat) => <option key={cat} value={cat}>{cat}</option>)}</select></Field>
          <Field label="Monto (DOP)" required><Input name="amount" type="number" min="0.01" step="0.01" required /></Field>
          <Field label="Cuenta de origen" required><select name="account_id" required defaultValue=""><option value="" disabled>Seleccionar cuenta</option>{accounts.data.map((account) => <option key={account.id} value={account.id}>{account.name}</option>)}</select></Field>
          <Field label="Fecha del gasto" required><Input name="occurred_at" type="date" defaultValue={today} required /></Field>
          <Field label="Referencia / NCF (opcional)"><Input name="reference_number" maxLength={80} /></Field>
        </div>
        <Button type="submit" appearance="primary" disabled={create.isPending}>{create.isPending ? 'Guardando…' : 'Guardar gasto'}</Button>
      </form>
    </Card>}
    {accounts.data.length === 0 && <MessageBar intent="warning"><MessageBarBody>Crea una cuenta antes de registrar un gasto.</MessageBarBody></MessageBar>}
    {expenses.data.length === 0 ? <EmptyState label="No hay gastos directos registrados aún." /> :
      <FinanceTable columns={['Descripción', 'Categoría', 'Cuenta', 'Fecha', 'Monto']} rows={expenses.data.map((exp: ExpenseRecord) => ({ Descripción: exp.description, Categoría: exp.category, Cuenta: exp.account || '—', Fecha: exp.occurred_at, Monto: exp.amount }))} />}
  </>;
}

function TransfersPage() {
  const [showForm, setShowForm] = useState(false);
  const [error, setError] = useState('');
  const queryClient = useQueryClient();
  const transfers = useQuery({ queryKey: ['transfers'], queryFn: () => api.listTransfers() });
  const accounts = useQuery({ queryKey: ['accounts'], queryFn: api.listAccounts });
  const create = useMutation({
    mutationFn: api.createTransfer,
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['transfers'] }),
        queryClient.invalidateQueries({ queryKey: ['accounts'] }),
        queryClient.invalidateQueries({ queryKey: ['transactions'] }),
        queryClient.invalidateQueries({ queryKey: ['dashboard'] }),
      ]);
    },
    onError: (cause) => setError(cause.message),
  });

  function submitCreate(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError('');
    const form = event.currentTarget;
    const data = new FormData(form);
    const source = String(data.get('source_account_id'));
    const dest = String(data.get('destination_account_id'));
    if (source === dest) {
      setError('La cuenta de origen y destino deben ser diferentes.');
      return;
    }
    create.mutate({
      source_account_id: source,
      destination_account_id: dest,
      amount: String(data.get('amount')),
      transferred_at: String(data.get('transferred_at')),
      notes: String(data.get('notes') || '') || null,
      idempotency_key: createUuid(),
    }, { onSuccess: () => { form.reset(); setShowForm(false); } });
  }

  if (transfers.isPending || accounts.isPending) return <LoadingState />;
  if (transfers.isError) return <ErrorState onRetry={() => void transfers.refetch()} />;
  if (accounts.isError) return <ErrorState onRetry={() => void accounts.refetch()} />;

  return <>
    <div className="page-intro">
      <p>Transfiere fondos entre tus cuentas propias de forma atómica sin alterar tu patrimonio total.</p>
      <Button appearance="primary" onClick={() => setShowForm((value) => !value)}>{showForm ? 'Cancelar' : 'Nueva transferencia'}</Button>
    </div>
    {error && <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar>}
    {showForm && <Card className="inline-form">
      <div><Subtitle1>Nueva transferencia entre cuentas</Subtitle1><Caption1>Descuenta de la cuenta origen y acredita en la cuenta destino.</Caption1></div>
      <form className="finance-form" onSubmit={submitCreate}>
        <div className="form-grid">
          <Field label="Cuenta Origen" required><select name="source_account_id" required defaultValue=""><option value="" disabled>Seleccionar origen</option>{accounts.data.map((account) => <option key={account.id} value={account.id}>{account.name} ({account.balance})</option>)}</select></Field>
          <Field label="Cuenta Destino" required><select name="destination_account_id" required defaultValue=""><option value="" disabled>Seleccionar destino</option>{accounts.data.map((account) => <option key={account.id} value={account.id}>{account.name} ({account.balance})</option>)}</select></Field>
          <Field label="Monto a transferir (DOP)" required><Input name="amount" type="number" min="0.01" step="0.01" required /></Field>
          <Field label="Fecha de transferencia" required><Input name="transferred_at" type="date" defaultValue={today} required /></Field>
          <Field label="Notas / Motivo"><Input name="notes" maxLength={500} placeholder="ej. Ahorro quincenal" /></Field>
        </div>
        <Button type="submit" appearance="primary" disabled={create.isPending}>{create.isPending ? 'Procesando…' : 'Realizar transferencia'}</Button>
      </form>
    </Card>}
    {accounts.data.length < 2 && <MessageBar intent="warning"><MessageBarBody>Necesitas al menos dos cuentas activas para realizar transferencias internas.</MessageBarBody></MessageBar>}
    {transfers.data.length === 0 ? <EmptyState label="No hay transferencias entre cuentas registradas." /> :
      <FinanceTable columns={['Cuenta Origen', 'Cuenta Destino', 'Fecha', 'Monto', 'Notas']} rows={transfers.data.map((tr: TransferRecord) => ({ 'Cuenta Origen': tr.source_account, 'Cuenta Destino': tr.destination_account, Fecha: tr.transferred_at, Monto: tr.amount, Notas: tr.notes || '—' }))} />}
  </>;
}

function CommitmentsPage() {
  const [showForm, setShowForm] = useState(false);
  const [editingCommitment, setEditingCommitment] = useState<CommitmentRecord | null>(null);
  const [error, setError] = useState('');
  const [commitmentType, setCommitmentType] = useState<CommitmentType>('utility');
  const queryClient = useQueryClient();
  const query = useQuery({ queryKey: ['commitments'], queryFn: api.listCommitments });
  const accounts = useQuery({ queryKey: ['accounts'], queryFn: api.listAccounts });

  const create = useMutation({
    mutationFn: api.createCommitment,
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['commitments'] }),
        queryClient.invalidateQueries({ queryKey: ['calendar'] }),
        queryClient.invalidateQueries({ queryKey: ['dashboard'] }),
        queryClient.invalidateQueries({ queryKey: ['cash-flow'] }),
      ]);
    },
    onError: (cause) => setError(cause.message),
  });

  const updateCommitment = useMutation({
    mutationFn: ({ commitmentId, input }: { commitmentId: string; input: any }) => api.updateCommitment(commitmentId, input),
    onSuccess: async () => {
      setEditingCommitment(null);
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['commitments'] }),
        queryClient.invalidateQueries({ queryKey: ['calendar'] }),
        queryClient.invalidateQueries({ queryKey: ['dashboard'] }),
      ]);
    },
    onError: (cause) => setError(cause.message),
  });

  const deleteCommitment = useMutation({
    mutationFn: (commitmentId: string) => api.deleteCommitment(commitmentId),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['commitments'] }),
        queryClient.invalidateQueries({ queryKey: ['calendar'] }),
        queryClient.invalidateQueries({ queryKey: ['dashboard'] }),
      ]);
    },
    onError: (cause) => setError(cause.message),
  });

  const pay = useMutation({
    mutationFn: ({ id, occurrenceId, input }: { id: string; occurrenceId: string; input: { amount: string; account_id: string; paid_at: string; idempotency_key: string } }) => api.recordCommitmentPayment(id, occurrenceId, input),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['commitments'] }),
        queryClient.invalidateQueries({ queryKey: ['calendar'] }),
        queryClient.invalidateQueries({ queryKey: ['accounts'] }),
        queryClient.invalidateQueries({ queryKey: ['transactions'] }),
        queryClient.invalidateQueries({ queryKey: ['dashboard'] }),
        queryClient.invalidateQueries({ queryKey: ['cash-flow'] }),
      ]);
    },
    onError: (cause) => setError(cause.message),
  });
  function submitCreate(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError('');
    const form = event.currentTarget;
    const data = new FormData(form);
    const type = String(data.get('type')) as CommitmentType;

    if (type === 'credit_card') {
      const currentBal = String(data.get('current_balance') || '0');
      const stmtBal = String(data.get('statement_balance') || currentBal);
      const stmtDay = Number(data.get('statement_day') || 15);
      const graceDays = Number(data.get('payment_grace_days') || 21);

      create.mutate({
        name: String(data.get('name')),
        type,
        reference_number: String(data.get('reference_number') || '') || null,
        institution: String(data.get('institution') || '') || null,
        current_balance: currentBal,
        statement_balance: stmtBal,
        statement_day: stmtDay,
        payment_grace_days: graceDays,
        notes: String(data.get('notes') || '') || null,
      }, { onSuccess: () => { form.reset(); setShowForm(false); } });
      return;
    }

    create.mutate({
      name: String(data.get('name')),
      type,
      amount: String(data.get('amount') || '0'),
      frequency: String(data.get('frequency') || 'monthly'),
      next_due_date: String(data.get('next_due_date') || today),
      reference_number: String(data.get('reference_number') || '') || null,
      institution: String(data.get('institution') || '') || null,
      current_balance: String(data.get('current_balance') || '') || null,
      interest_rate: String(data.get('interest_rate') || '') || null,
      category: String(data.get('category') || '') || null,
      maturity_date: String(data.get('maturity_date') || '') || null,
      notes: String(data.get('notes') || '') || null,
    }, { onSuccess: () => { form.reset(); setShowForm(false); } });
  }

  function submitUpdate(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!editingCommitment) return;
    setError('');
    const form = event.currentTarget;
    const data = new FormData(form);
    const type = String(data.get('type')) as CommitmentType;

    if (type === 'credit_card') {
      const currentBal = String(data.get('current_balance') || '0');
      const stmtBal = String(data.get('statement_balance') || currentBal);
      const stmtDay = Number(data.get('statement_day') || 15);
      const graceDays = Number(data.get('payment_grace_days') || 21);

      updateCommitment.mutate({
        commitmentId: editingCommitment.commitmentId ?? editingCommitment.id!,
        input: {
          name: String(data.get('name')),
          type,
          reference_number: String(data.get('reference_number') || '') || null,
          institution: String(data.get('institution') || '') || null,
          current_balance: currentBal,
          statement_balance: stmtBal,
          statement_day: stmtDay,
          payment_grace_days: graceDays,
          notes: String(data.get('notes') || '') || null,
        },
      });
      return;
    }

    const amountVal = String(data.get('amount'));
    const rawAmount = amountVal.replace(/[^\d.]/g, '') || '0';

    updateCommitment.mutate({
      commitmentId: editingCommitment.commitmentId ?? editingCommitment.id!,
      input: {
        name: String(data.get('name')),
        type,
        amount: rawAmount,
        frequency: String(data.get('frequency')),
        next_due_date: String(data.get('next_due_date')),
        reference_number: String(data.get('reference_number') || '') || null,
        institution: String(data.get('institution') || '') || null,
        category: String(data.get('category') || '') || null,
        notes: String(data.get('notes') || '') || null,
      },
    });
  }

  function submitPayment(event: FormEvent<HTMLFormElement>, commitment: CommitmentRecord) {
    event.preventDefault();
    setError('');
    const form = event.currentTarget;
    const data = new FormData(form);
    pay.mutate({
      id: commitment.commitmentId!,
      occurrenceId: commitment.id!,
      input: {
        amount: String(data.get('amount')),
        account_id: String(data.get('account_id')),
        paid_at: String(data.get('paid_at')),
        idempotency_key: createUuid(),
      },
    }, { onSuccess: () => form.reset() });
  }

  if (query.isPending || accounts.isPending) return <LoadingState />;
  if (query.isError) return <ErrorState onRetry={() => void query.refetch()} />;
  if (accounts.isError) return <ErrorState onRetry={() => void accounts.refetch()} />;
  return <>
    <div className="page-intro"><p>Gestiona préstamos, tarjetas, facturas del hogar y servicios recurrentes con sus detalles correspondientes.</p><Button appearance="primary" onClick={() => setShowForm((value) => !value)}>{showForm ? 'Cancelar' : 'Agregar compromiso'}</Button></div>
    {error && <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar>}
    {showForm && <Card className="inline-form">
      <div><Subtitle1>Nuevo compromiso recurrente</Subtitle1><Caption1>Elige un tipo para ver los detalles financieros correspondientes.</Caption1></div>
      <form className="finance-form" onSubmit={submitCreate}>
        <div className="form-grid">
          <Field label="Tipo de compromiso" required><select name="type" value={commitmentType} onChange={(event) => setCommitmentType(event.target.value as CommitmentType)}>{commitmentTypes.map((type) => <option key={type.value} value={type.value}>{type.label}</option>)}</select></Field>
          <Field label={commitmentType === 'utility' ? 'Nombre de la factura' : commitmentType === 'subscription' ? 'Nombre del servicio' : commitmentType === 'credit_card' ? 'Nombre de la tarjeta' : commitmentType === 'loan' ? 'Nombre del préstamo' : 'Nombre'} required><Input name="name" required maxLength={160} placeholder={commitmentType === 'utility' ? 'ej. Electricidad' : commitmentType === 'subscription' ? 'ej. Streaming' : commitmentType === 'loan' ? 'ej. Préstamo de vehículo' : commitmentType === 'credit_card' ? 'ej. Visa Banco Popular' : 'ej. Alquiler'} /></Field>

          {commitmentType === 'credit_card' && <>
            <Field label="Referencia (últimos 4 dígitos)" required><Input name="reference_number" required maxLength={4} inputMode="numeric" pattern="[0-9]{4}" placeholder="ej. 4321" /></Field>
            <Field label="Banco emisor" required><Input name="institution" required maxLength={160} placeholder="ej. Banco Popular" /></Field>
            <Field label="Balance a la fecha (DOP)" required><Input name="current_balance" type="number" min="0" step="0.01" required placeholder="Monto consumido hasta hoy" /></Field>
            <Field label="Día de corte (1-31)" required><Input name="statement_day" type="number" min="1" max="31" defaultValue="15" required placeholder="ej. 15" /></Field>
            <Field label="Días para pago / Vencimiento de pago" required><Input name="payment_grace_days" type="number" min="1" max="31" defaultValue="21" required placeholder="ej. 21 días" /></Field>
            <Field label="Balance al corte (DOP)"><Input name="statement_balance" type="number" min="0" step="0.01" placeholder="Balance del último estado de cuenta" /></Field>
          </>}

          {commitmentType === 'loan' && <>
            <Field label="Número de préstamo / referencia" required><Input name="reference_number" required maxLength={80} placeholder="Referencia de préstamo" /></Field>
            <Field label="Banco / prestamista" required><Input name="institution" required maxLength={160} /></Field>
            <Field label="Saldo actual del préstamo (DOP)" required><Input name="current_balance" type="number" min="0" step="0.01" required /></Field>
            <Field label="Tasa de interés anual (%)" required><Input name="interest_rate" type="number" min="0" max="100" step="0.001" required /></Field>
            <Field label="Fecha de vencimiento final" required><Input name="maturity_date" type="date" required /></Field>
            <Field label="Monto de la cuota (DOP)" required><Input name="amount" type="number" min="0.01" step="0.01" required /></Field>
            <Field label="Frecuencia de pago" required><select name="frequency" defaultValue="monthly"><option value="monthly">Mensual</option><option value="weekly">Semanal</option><option value="biweekly">Cada dos semanas</option><option value="quarterly">Trimestral</option><option value="yearly">Anual</option></select></Field>
            <Field label="Próxima fecha de vencimiento" required><Input name="next_due_date" type="date" defaultValue={today} required /></Field>
          </>}

          {(commitmentType === 'utility' || commitmentType === 'subscription' || commitmentType === 'insurance' || commitmentType === 'rent' || commitmentType === 'other') && <>
            {(commitmentType === 'utility' || commitmentType === 'subscription' || commitmentType === 'insurance') && <Field label="Categoría de factura / servicio" required><select name="category" required defaultValue=""><option value="" disabled>Seleccionar categoría</option>{billCategories.map((category) => <option key={category} value={category}>{category}</option>)}</select></Field>}
            <Field label={commitmentType === 'rent' ? 'Arrendador / Administrador' : 'Proveedor / Institución'}><Input name="institution" maxLength={160} placeholder="ej. Compañía de servicios" /></Field>
            <Field label="Número de contrato / referencia"><Input name="reference_number" maxLength={80} /></Field>
            <Field label="Monto a pagar (DOP)" required><Input name="amount" type="number" min="0.01" step="0.01" required /></Field>
            <Field label="Frecuencia de pago" required><select name="frequency" defaultValue="monthly"><option value="monthly">Mensual</option><option value="once">Una sola vez</option><option value="weekly">Semanal</option><option value="biweekly">Cada dos semanas</option><option value="quarterly">Trimestral</option><option value="yearly">Anual</option></select></Field>
            <Field label="Próxima fecha de vencimiento" required><Input name="next_due_date" type="date" defaultValue={today} required /></Field>
          </>}

          <Field label="Notas adicionales"><Input name="notes" maxLength={2000} /></Field>
        </div>
        <Button type="submit" appearance="primary" disabled={create.isPending}>{create.isPending ? 'Guardando…' : 'Guardar compromiso'}</Button>
      </form>
    </Card>}
    {editingCommitment && <Card className="inline-form">
      <div><Subtitle1>Editar compromiso: {editingCommitment.name}</Subtitle1><Caption1>Modifica los datos del compromiso o tarjeta.</Caption1></div>
      <form className="finance-form" onSubmit={submitUpdate}>
        <div className="form-grid">
          <Field label="Tipo de compromiso" required><select name="type" defaultValue={editingCommitment.type ?? 'utility'} onChange={(e) => setCommitmentType(e.target.value as CommitmentType)}>{commitmentTypes.map((type) => <option key={type.value} value={type.value}>{type.label}</option>)}</select></Field>
          <Field label="Nombre" required><Input name="name" defaultValue={editingCommitment.name} required maxLength={160} /></Field>

          {commitmentType === 'credit_card' ? <>
            <Field label="Referencia (últimos 4 dígitos)" required><Input name="reference_number" defaultValue={editingCommitment.referenceNumber ?? ''} required maxLength={4} inputMode="numeric" pattern="[0-9]{4}" /></Field>
            <Field label="Banco emisor" required><Input name="institution" defaultValue={editingCommitment.institution ?? ''} required maxLength={160} /></Field>
            <Field label="Balance a la fecha (DOP)" required><Input name="current_balance" type="number" min="0" step="0.01" defaultValue={String(Number((editingCommitment.currentBalance ?? '0').replace(/[^\d.]/g, '')) || 0)} required /></Field>
            <Field label="Día de corte (1-31)" required><Input name="statement_day" type="number" min="1" max="31" defaultValue={String(editingCommitment.statementDay ?? 15)} required /></Field>
            <Field label="Días para pago / Vencimiento de pago" required><Input name="payment_grace_days" type="number" min="1" max="31" defaultValue={String(editingCommitment.paymentGraceDays ?? 21)} required /></Field>
            <Field label="Balance al corte (DOP)"><Input name="statement_balance" type="number" min="0" step="0.01" defaultValue={String(Number((editingCommitment.statementBalance ?? '0').replace(/[^\d.]/g, '')) || 0)} /></Field>
          </> : <>
            <Field label="Monto a pagar (DOP)" required><Input name="amount" type="number" min="0.01" step="0.01" defaultValue={String(Number(editingCommitment.amount.replace(/[^\d.]/g, '')) || 0)} required /></Field>
            <Field label="Frecuencia de pago" required><select name="frequency" defaultValue="monthly"><option value="monthly">Mensual</option><option value="once">Una sola vez</option><option value="weekly">Semanal</option><option value="biweekly">Cada dos semanas</option><option value="quarterly">Trimestral</option><option value="yearly">Anual</option></select></Field>
            <Field label="Próxima fecha de vencimiento" required><Input name="next_due_date" type="date" defaultValue={today} required /></Field>
          </>}

          <Field label="Notas adicionales"><Input name="notes" defaultValue={editingCommitment.notes ?? ''} maxLength={2000} /></Field>
        </div>
        <div style={{ display: 'flex', gap: '8px', marginTop: '10px' }}>
          <Button type="submit" appearance="primary" disabled={updateCommitment.isPending}>{updateCommitment.isPending ? 'Guardando…' : 'Guardar cambios'}</Button>
          <Button type="button" onClick={() => setEditingCommitment(null)}>Cancelar</Button>
        </div>
      </form>
    </Card>}
    {accounts.data.length === 0 && <MessageBar intent="warning"><MessageBarBody>Crea una cuenta antes de registrar pagos de compromisos.</MessageBarBody></MessageBar>}
    {query.data.occurrences.length === 0 ? <EmptyState label="No hay compromisos programados aún." /> :
      <section className="record-list">{query.data.occurrences.map((item) => {
        const remaining = item.remainingAmount ?? item.amount;
        const paid = item.status === 'Paid' || remaining === 'RD$ 0.00';
        return <Card className="content-card record-card" key={item.id}>
          <div className="record-heading"><div><Subtitle1>{item.name}</Subtitle1><Caption1>{item.dueDate} · {item.status}</Caption1></div><strong>{remaining} a vencer</strong></div>
          <Caption1>{commitmentTypes.find((type) => type.value === item.type)?.label ?? 'Compromiso'}{item.category ? ` · ${item.category}` : ''}{item.institution ? ` · ${item.institution}` : ''}</Caption1>
          <Caption1>Monto programado: {item.amount}</Caption1>
          {!paid && accounts.data.length > 0 && <details className="debt-action">
            <summary>Registrar pago de compromiso</summary>
            <form className="debt-action-form" onSubmit={(event) => submitPayment(event, item)}>
              <Field label={item.type === 'loan' || item.type === 'credit_card' ? 'Monto a pagar (DOP; se permiten abonos)' : 'Monto a pagar (DOP)'} required><Input name="amount" type="number" min="0.01" step="0.01" max={item.type === 'loan' || item.type === 'credit_card' ? undefined : Number(remaining.replace(/[^\d.]/g, ''))} defaultValue={Number(remaining.replace(/[^\d.]/g, '')).toFixed(2)} required /></Field>
              <Field label="Pagar desde la cuenta" required><select name="account_id" required defaultValue=""><option value="" disabled>Seleccionar cuenta</option>{accounts.data.map((account) => <option key={account.id} value={account.id}>{account.name}</option>)}</select></Field>
              <Field label="Fecha de pago" required><Input name="paid_at" type="date" defaultValue={today} required /></Field>
              <Button type="submit" appearance="primary" disabled={pay.isPending}>Registrar pago</Button>
            </form>
          </details>}
          <div style={{ display: 'flex', gap: '8px', marginTop: '10px' }}>
            <Button appearance="subtle" size="small" onClick={() => {
              setCommitmentType(item.type ?? 'utility');
              setEditingCommitment(item);
            }}>Editar compromiso</Button>
            <Button appearance="subtle" size="small" disabled={deleteCommitment.isPending} onClick={() => {
              if (window.confirm(`¿Estás seguro de eliminar el compromiso "${item.name}"?`)) {
                deleteCommitment.mutate(item.commitmentId ?? item.id!);
              }
            }}>Eliminar compromiso</Button>
          </div>
        </Card>;
      })}</section>}
    <DebtBalancesSection />
  </>;
}

function CalendarPage() {
  const initialMonth = today.slice(0, 7);
  const [month, setMonth] = useState(initialMonth);
  const [year, monthNumber] = month.split('-').map(Number);
  const firstDay = new Date(Date.UTC(year, monthNumber - 1, 1));
  const lastDay = new Date(Date.UTC(year, monthNumber, 0));
  const from = firstDay.toISOString().slice(0, 10);
  const to = lastDay.toISOString().slice(0, 10);
  const query = useQuery({ queryKey: ['calendar', from, to], queryFn: () => api.listCalendar(from, to) });
  const shiftMonth = (amount: number) => {
    const date = new Date(Date.UTC(year, monthNumber - 1 + amount, 1));
    setMonth(date.toISOString().slice(0, 7));
  };
  if (query.isPending) return <LoadingState />;
  if (query.isError) return <ErrorState onRetry={() => void query.refetch()} />;
  return <>
    <div className="page-intro">
      <p>Ingresos esperados, vencimientos de facturas y pagos programados del mes.</p>
      <div className="calendar-controls"><Button onClick={() => shiftMonth(-1)}>Anterior</Button><Field label="Mes"><Input type="month" value={month} onChange={(event) => setMonth(event.target.value)} /></Field><Button onClick={() => shiftMonth(1)}>Siguiente</Button></div>
    </div>
    {query.data.length === 0 ? <EmptyState label="Nada programado este mes. Agrega ingresos o compromisos para verlos aquí." /> :
      <FinanceTable columns={['Evento', 'Fecha', 'Monto', 'Tipo']} rows={query.data.map((item: CalendarEvent) => ({ Evento: item.event, Fecha: item.date, Monto: item.amount, Tipo: item.type }))} />}
  </>;
}

function TransactionsPage() {
  const [filters, setFilters] = useState({ from: '', to: '', account_id: '', q: '' });
  const [draftFilters, setDraftFilters] = useState(filters);
  const [error, setError] = useState('');
  const queryClient = useQueryClient();
  const accounts = useQuery({ queryKey: ['accounts'], queryFn: api.listAccounts });
  const query = useQuery({ queryKey: ['transactions', filters], queryFn: () => api.listTransactions(filters) });
  const reverse = useMutation({
    mutationFn: ({ paymentId, reason }: { paymentId: string; reason: string }) => api.reversePayment(paymentId, { reason, idempotency_key: createUuid() }),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['transactions'] }),
        queryClient.invalidateQueries({ queryKey: ['income'] }),
        queryClient.invalidateQueries({ queryKey: ['commitments'] }),
        queryClient.invalidateQueries({ queryKey: ['accounts'] }),
        queryClient.invalidateQueries({ queryKey: ['dashboard'] }),
        queryClient.invalidateQueries({ queryKey: ['cash-flow'] }),
      ]);
    },
    onError: (cause) => setError(cause.message),
  });
  if (query.isPending || accounts.isPending) return <LoadingState />;
  if (query.isError) return <ErrorState onRetry={() => void query.refetch()} />;
  if (accounts.isError) return <ErrorState onRetry={() => void accounts.refetch()} />;
  return <>
    <div className="page-intro"><p>Consulta el libro mayor y filtra movimientos por cuenta o fecha.</p></div>
    <Card className="inline-form"><form className="finance-form" onSubmit={(event) => { event.preventDefault(); setFilters(draftFilters); }}>
      <div className="form-grid">
        <Field label="Buscar descripción"><Input value={draftFilters.q} onChange={(event) => setDraftFilters({ ...draftFilters, q: event.target.value })} placeholder="ej. Alquiler o Salario" /></Field>
        <Field label="Cuenta"><select value={draftFilters.account_id} onChange={(event) => setDraftFilters({ ...draftFilters, account_id: event.target.value })}><option value="">Todas las cuentas</option>{accounts.data.map((account) => <option key={account.id} value={account.id}>{account.name}</option>)}</select></Field>
        <Field label="Desde"><Input type="date" value={draftFilters.from} onChange={(event) => setDraftFilters({ ...draftFilters, from: event.target.value })} /></Field>
        <Field label="Hasta"><Input type="date" value={draftFilters.to} onChange={(event) => setDraftFilters({ ...draftFilters, to: event.target.value })} /></Field>
      </div>
      <Button type="submit" appearance="primary">Aplicar filtros</Button>
      <Button type="button" onClick={() => { const cleared = { from: '', to: '', account_id: '', q: '' }; setDraftFilters(cleared); setFilters(cleared); }}>Limpiar filtros</Button>
    </form></Card>
    {error && <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar>}
    {query.data.length === 0 ? <EmptyState label="No hay transacciones que coincidan con estos filtros." /> :
      <section className="record-list">{query.data.map((item: TransactionRecord) => <Card className="content-card record-card" key={item.id}>
        <div className="record-heading"><div><Subtitle1>{item.movement}</Subtitle1><Caption1>{item.recorded} · {item.account}</Caption1></div><strong>{item.amount}</strong></div>
        {item.paymentId && !item.reversedAt && <details className="debt-action">
          <summary>Revertir este pago</summary>
          <form className="debt-action-form" onSubmit={(event) => {
            event.preventDefault();
            const data = new FormData(event.currentTarget);
            const reason = String(data.get('reason'));
            if (item.paymentId) reverse.mutate({ paymentId: item.paymentId, reason });
          }}>
            <Field label="Motivo de la reversión" required><Input name="reason" minLength={3} maxLength={500} required /></Field>
            <Button type="submit" appearance="primary" disabled={reverse.isPending}>Revertir pago</Button>
          </form>
        </details>}
        {item.reversedAt && <Caption1>Pago revertido el {new Date(item.reversedAt).toLocaleDateString('es-DO')}</Caption1>}
      </Card>)}</section>}
  </>;
}

function AccountsPage() {
  const [showForm, setShowForm] = useState(false);
  const [editingAccount, setEditingAccount] = useState<AccountRecord | null>(null);
  const [error, setError] = useState('');
  const queryClient = useQueryClient();
  const query = useQuery({ queryKey: ['accounts'], queryFn: api.listAccounts });

  const create = useMutation({
    mutationFn: api.createFinancialAccount,
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['accounts'] }),
        queryClient.invalidateQueries({ queryKey: ['transactions'] }),
        queryClient.invalidateQueries({ queryKey: ['dashboard'] }),
      ]);
    },
    onError: (cause) => setError(cause.message),
  });

  const updateAccount = useMutation({
    mutationFn: ({ accountId, input }: { accountId: string; input: { name: string; institution?: string | null; type: string } }) => api.updateFinancialAccount(accountId, input),
    onSuccess: async () => {
      setEditingAccount(null);
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['accounts'] }),
        queryClient.invalidateQueries({ queryKey: ['dashboard'] }),
      ]);
    },
    onError: (cause) => setError(cause.message),
  });

  const deleteAccount = useMutation({
    mutationFn: (accountId: string) => api.deleteFinancialAccount(accountId),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['accounts'] }),
        queryClient.invalidateQueries({ queryKey: ['dashboard'] }),
        queryClient.invalidateQueries({ queryKey: ['transactions'] }),
      ]);
    },
    onError: (cause) => setError(cause.message),
  });

  const reconcile = useMutation({
    mutationFn: ({ accountId, input }: { accountId: string; input: { target_balance: string; reason: string } }) => api.reconcileAccount(accountId, input),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['accounts'] }),
        queryClient.invalidateQueries({ queryKey: ['transactions'] }),
        queryClient.invalidateQueries({ queryKey: ['dashboard'] }),
      ]);
    },
    onError: (cause) => setError(cause.message),
  });

  function submitCreate(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError('');
    const form = event.currentTarget;
    const data = new FormData(form);
    create.mutate({
      name: String(data.get('name')),
      institution: String(data.get('institution') ?? ''),
      type: String(data.get('type')),
      currency: String(data.get('currency')),
      opening_balance: String(data.get('opening_balance')),
    }, { onSuccess: () => { form.reset(); setShowForm(false); } });
  }

  function submitUpdate(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!editingAccount) return;
    setError('');
    const form = event.currentTarget;
    const data = new FormData(form);
    updateAccount.mutate({
      accountId: editingAccount.id,
      input: {
        name: String(data.get('name')),
        institution: String(data.get('institution') ?? ''),
        type: String(data.get('type')),
      },
    });
  }

  function submitReconcile(event: FormEvent<HTMLFormElement>, account: AccountRecord) {
    event.preventDefault();
    setError('');
    const form = event.currentTarget;
    const data = new FormData(form);
    reconcile.mutate({
      accountId: account.id,
      input: {
        target_balance: String(data.get('target_balance')),
        reason: String(data.get('reason')),
      },
    }, { onSuccess: () => form.reset() });
  }

  if (query.isPending) return <LoadingState />;
  if (query.isError) return <ErrorState onRetry={() => void query.refetch()} />;

  const typeLabels: Record<string, string> = {
    checking: 'Cuenta Corriente',
    savings: 'Cuenta de Ahorros',
    cash: 'Efectivo',
    other: 'Otra',
  };

  return <>
    <div className="page-intro">
      <p>Administra tus cuentas financieras (Ahorro, Corriente, Efectivo), sus saldos y conciliaciones auditadas.</p>
      <Button appearance="primary" onClick={() => setShowForm((value) => !value)}>{showForm ? 'Cancelar' : 'Agregar cuenta'}</Button>
    </div>
    {error && <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar>}
    {showForm && <Card className="inline-form">
      <div><Subtitle1>Nueva cuenta financiera</Subtitle1><Caption1>El saldo inicial formará parte del libro contable.</Caption1></div>
      <form className="finance-form" onSubmit={submitCreate}>
        <div className="form-grid">
          <Field label="Nombre de la cuenta" required><Input name="name" required maxLength={160} placeholder="ej. Cuenta Corriente BHD" /></Field>
          <Field label="Banco o institución"><Input name="institution" maxLength={160} placeholder="ej. Banco Popular" /></Field>
          <Field label="Tipo de cuenta" required><select name="type" defaultValue="checking"><option value="checking">Cuenta Corriente</option><option value="savings">Cuenta de Ahorros</option><option value="cash">Efectivo</option><option value="other">Otro</option></select></Field>
          <input type="hidden" name="currency" value="DOP" />
          <Field label="Saldo inicial (DOP)" required><Input name="opening_balance" type="number" step="0.01" defaultValue="0.00" required /></Field>
        </div>
        <Button type="submit" appearance="primary" disabled={create.isPending}>{create.isPending ? 'Guardando…' : 'Guardar cuenta'}</Button>
      </form>
    </Card>}
    {editingAccount && <Card className="inline-form">
      <div><Subtitle1>Editar cuenta: {editingAccount.name}</Subtitle1><Caption1>Modifica el nombre, banco o tipo de la cuenta.</Caption1></div>
      <form className="finance-form" onSubmit={submitUpdate}>
        <div className="form-grid">
          <Field label="Nombre de la cuenta" required><Input name="name" defaultValue={editingAccount.name} required maxLength={160} /></Field>
          <Field label="Banco o institución"><Input name="institution" defaultValue={editingAccount.institution ?? ''} maxLength={160} /></Field>
          <Field label="Tipo de cuenta" required><select name="type" defaultValue={editingAccount.type}><option value="checking">Cuenta Corriente</option><option value="savings">Cuenta de Ahorros</option><option value="cash">Efectivo</option><option value="other">Otro</option></select></Field>
        </div>
        <div style={{ display: 'flex', gap: '8px' }}>
          <Button type="submit" appearance="primary" disabled={updateAccount.isPending}>{updateAccount.isPending ? 'Guardando…' : 'Guardar cambios'}</Button>
          <Button type="button" onClick={() => setEditingAccount(null)}>Cancelar</Button>
        </div>
      </form>
    </Card>}
    {query.data.length === 0 ? <EmptyState label="No hay cuentas activas aún." /> :
      <section className="record-list">{query.data.map((account: AccountRecord) => <Card className="content-card record-card" key={account.id}>
        <div className="record-heading">
          <div><Subtitle1>{account.name}</Subtitle1><Caption1>{typeLabels[account.type] ?? account.type}{account.institution ? ` · ${account.institution}` : ''}</Caption1></div>
          <strong>{account.balance}</strong>
        </div>
        <div style={{ display: 'flex', gap: '10px', alignItems: 'center', flexWrap: 'wrap' }}>
          <details className="debt-action" style={{ flex: 1, minWidth: '200px' }}>
            <summary>Conciliar saldo real</summary>
            <form className="debt-action-form" onSubmit={(event) => submitReconcile(event, account)}>
              <Field label="Saldo real actual (DOP)" required><Input name="target_balance" type="number" step="0.01" required placeholder="ej. 15000.00" /></Field>
              <Field label="Motivo de conciliación" required><Input name="reason" required maxLength={255} placeholder="ej. Ajuste de cierre de mes" /></Field>
              <Button type="submit" appearance="primary" disabled={reconcile.isPending}>Conciliar</Button>
            </form>
          </details>
          <div style={{ display: 'flex', gap: '8px' }}>
            <Button appearance="subtle" size="small" onClick={() => setEditingAccount(account)}>Editar</Button>
            <Button appearance="subtle" size="small" disabled={deleteAccount.isPending} onClick={() => {
              if (window.confirm(`¿Estás seguro de eliminar la cuenta "${account.name}"?`)) {
                deleteAccount.mutate(account.id);
              }
            }}>Eliminar</Button>
          </div>
        </div>
      </Card>)}</section>}
  </>;
}
