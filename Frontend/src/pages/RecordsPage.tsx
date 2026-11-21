import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Card, Caption1, Field, Input, MessageBar, MessageBarBody, Subtitle1, Tab, TabList } from '@fluentui/react-components';
import { useState, type FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { api, createUuid } from '../api';
import { EmptyState, ErrorState, LoadingState } from '../components/DataState';
import { FinanceTable } from '../components/FinanceTable';
import { DebtBalancesSection } from './DebtsPage';
import { CardCapacityPanel } from '../components/CardCapacityPanel';

import type { AccountRecord, CalendarEvent, CommitmentRecord, ExpenseRecord, IncomeRecord, TransactionRecord, TransferRecord } from '../types';
import { commitmentInvalidationKeys, financialQueryKeys } from '../api/queryKeys';

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
function cardCyclePreview(day: number, grace: number, reference = new Date()) {
  if (!Number.isInteger(day) || day < 1 || day > 31 || !Number.isInteger(grace) || grace < 1 || grace > 31) return null;
  // Use UTC calendar arithmetic to avoid daylight-saving shifts.
  const base = new Date(Date.UTC(reference.getFullYear(), reference.getMonth(), reference.getDate()));
  const cutoff = (year: number, month: number) => new Date(Date.UTC(year, month, Math.min(day, new Date(Date.UTC(year, month + 1, 0)).getUTCDate())));
  let last = cutoff(base.getUTCFullYear(), base.getUTCMonth());
  if (last > base) last = cutoff(base.getUTCFullYear(), base.getUTCMonth() - 1);
  const next = cutoff(last.getUTCFullYear(), last.getUTCMonth() + 1);
  const due = (date: Date) => new Date(date.getTime() + grace * 86400000);
  const lastDue = due(last);
  const iso = (date: Date) => date.toISOString().slice(0, 10);
  return { lastCutoff: iso(last), nextCutoff: iso(next), lastDue: iso(lastDue), nextCutoffDue: iso(due(next)), nextDue: iso(lastDue >= base ? lastDue : due(next)) };
}

function splitManualList(value: FormDataEntryValue | null): string[] | null {
  const items = String(value ?? '').split(/\r?\n|,/).map((item) => item.trim()).filter(Boolean);
  return items.length ? items : null;
}

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
  const [editingIncome, setEditingIncome] = useState<IncomeRecord | null>(null);
  const [error, setError] = useState('');
  const queryClient = useQueryClient();
  const incomes = useQuery({ queryKey: ['income'], queryFn: api.listIncomes, refetchOnMount: 'always' });
  const accounts = useQuery({ queryKey: ['accounts'], queryFn: api.listAccounts, refetchOnMount: 'always' });

  const create = useMutation({
    mutationFn: api.createIncome,
    onSuccess: async () => {
      await queryClient.invalidateQueries();
    },
    onError: (cause) => setError(cause.message),
  });

  const updateIncome = useMutation({
    mutationFn: ({ id, input }: { id: string; input: { source: string; amount: string; expected_date: string } }) => api.updateIncome(id, input),
    onSuccess: async () => {
      setEditingIncome(null);
      await queryClient.invalidateQueries();
    },
    onError: (cause) => setError(cause.message),
  });

  const deleteIncome = useMutation({
    mutationFn: (id: string) => api.deleteIncome(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries();
    },
    onError: (cause) => setError(cause.message),
  });

  const receive = useMutation({
    mutationFn: ({ id, input }: { id: string; input: { amount: string; account_id: string; received_at: string; idempotency_key: string } }) => api.recordReceipt(id, input),
    onSuccess: async () => {
      await queryClient.invalidateQueries();
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

  function submitUpdate(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!editingIncome?.id) return;
    setError('');
    const form = event.currentTarget;
    const data = new FormData(form);
    const rawAmount = String(data.get('amount')).replace(/[^0-9.]/g, '');
    updateIncome.mutate({
      id: editingIncome.id,
      input: {
        source: String(data.get('source')),
        amount: rawAmount,
        expected_date: String(data.get('expected_date')),
      },
    });
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
      <Button appearance="primary" onClick={() => { setEditingIncome(null); setShowForm((value) => !value); }}>{showForm ? 'Cancelar' : 'Agregar ingreso'}</Button>
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
    {editingIncome && <Card className="inline-form">
      <div><Subtitle1>Editar ingreso: {editingIncome.source}</Subtitle1><Caption1>Modifica los detalles del ingreso proyectado.</Caption1></div>
      <form className="finance-form" onSubmit={submitUpdate}>
        <div className="form-grid">
          <Field label="Fuente / Concepto" required><Input name="source" defaultValue={editingIncome.source} required maxLength={160} /></Field>
          <Field label="Monto esperado (DOP)" required><Input name="amount" type="number" min="0.01" step="0.01" defaultValue={editingIncome.amount.replace(/[^0-9.]/g, '')} required /></Field>
          <Field label="Fecha esperada" required><Input name="expected_date" type="date" defaultValue={editingIncome.expectedDate} required /></Field>
        </div>
        <div style={{ display: 'flex', gap: '8px', marginTop: '10px' }}>
          <Button type="submit" appearance="primary" disabled={updateIncome.isPending}>{updateIncome.isPending ? 'Guardando…' : 'Actualizar ingreso'}</Button>
          <Button appearance="subtle" onClick={() => setEditingIncome(null)}>Cancelar</Button>
        </div>
      </form>
    </Card>}
    {accounts.data.length === 0 && <MessageBar intent="warning"><MessageBarBody>Crea una cuenta antes de registrar depósitos de ingresos.</MessageBarBody></MessageBar>}
    {incomes.data.length === 0 ? <EmptyState label="No se han registrado ingresos esperados aún." /> : <section className="record-list">
      {incomes.data.map((income) => <Card className="content-card record-card" key={income.id}>
        <div className="record-heading">
          <div><Subtitle1>{income.source}</Subtitle1><Caption1>Esperado {income.expectedDate} · {income.status}</Caption1></div>
          <div style={{ textAlign: 'right' }}>
            <strong>{income.amount}</strong>
          </div>
        </div>
        <Caption1>Recibido hasta ahora: {income.receivedAmount ?? 'RD$ 0.00'}</Caption1>
        {income.status !== 'Recibido' && accounts.data.length > 0 && <details className="debt-action">
          <summary>Registrar depósito en cuenta</summary>
          <form className="debt-action-form" onSubmit={(event) => submitReceipt(event, income)}>
            <Field label="Monto a depositar (DOP)" required><Input name="amount" type="number" min="0.01" step="0.01" required /></Field>
            <Field label="Depositar en cuenta" required><select name="account_id" required defaultValue=""><option value="" disabled>Seleccionar cuenta</option>{accounts.data.map((account) => <option key={account.id} value={account.id}>{account.name} ({account.balance})</option>)}</select></Field>
            <Field label="Fecha de recepción" required><Input name="received_at" type="date" defaultValue={today} required /></Field>
            <Button type="submit" appearance="primary" disabled={receive.isPending}>Registrar depósito</Button>
          </form>
        </details>}
        <div style={{ display: 'flex', gap: '8px', marginTop: '12px', justifyContent: 'flex-end', borderTop: '1px solid var(--border)', paddingTop: '10px' }}>
          <Button appearance="subtle" size="small" onClick={() => { setShowForm(false); setEditingIncome(income); }}>Editar ingreso</Button>
          <Button appearance="subtle" size="small" style={{ color: '#ff4d4f' }} onClick={() => { if (confirm(`¿Deseas eliminar el ingreso "${income.source}"?`)) deleteIncome.mutate(income.id!); }}>Eliminar ingreso</Button>
        </div>
      </Card>)}
    </section>}
  </>;
}

function parseNumericBalance(balanceStr?: string): number {
  if (!balanceStr) return 0;
  const isNegative = balanceStr.includes('−') || balanceStr.includes('-');
  const cleaned = balanceStr.replace(/[^0-9.]/g, '');
  const num = parseFloat(cleaned);
  if (isNaN(num)) return 0;
  return isNegative ? -num : num;
}

function ExpensesPage() {
  const [showForm, setShowForm] = useState(false);
  const [editingExpense, setEditingExpense] = useState<ExpenseRecord | null>(null);
  const [selectedAccountId, setSelectedAccountId] = useState('');
  const [enteredAmount, setEnteredAmount] = useState('');
  const [editAccountId, setEditAccountId] = useState('');
  const [editAmount, setEditAmount] = useState('');
  const [error, setError] = useState('');
  const [confirmOverdraft, setConfirmOverdraft] = useState(false);
  const [previewAccountId, setPreviewAccountId] = useState('');
  const [cardPreview, setCardPreview] = useState<Awaited<ReturnType<typeof api.previewCreditCardAuthorization>> | null>(null);
  const queryClient = useQueryClient();
  const expenses = useQuery({ queryKey: ['expenses'], queryFn: () => api.listExpenses(), refetchOnMount: 'always' });
  const accounts = useQuery({ queryKey: ['accounts'], queryFn: api.listAccounts, refetchOnMount: 'always' });

  const create = useMutation({
    mutationFn: api.createExpense,
    onSuccess: async () => {
      await queryClient.invalidateQueries();
    },
    onError: (cause) => setError(cause.message),
  });

  const updateExpense = useMutation({
    mutationFn: ({ id, input }: { id: string; input: { account_id: string; category: string; amount: string; occurred_at: string; description: string; reference_number?: string | null } }) => api.updateExpense(id, input),
    onSuccess: async () => {
      setEditingExpense(null);
      await queryClient.invalidateQueries();
    },
    onError: (cause) => setError(cause.message),
  });

  const deleteExpense = useMutation({
    mutationFn: (id: string) => api.deleteExpense(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries();
    },
    onError: (cause) => setError(cause.message),
  });

  const previewCard = useMutation({
    mutationFn: ({ debtId, amount }: { debtId: string; amount: string }) =>
      api.previewCreditCardAuthorization(debtId, amount),
    onSuccess: (preview) => {
      setCardPreview(preview);
      setPreviewAccountId(selectedAccountId);
      setConfirmOverdraft(false);
      setError('');
    },
    onError: (cause) => {
      setCardPreview(null);
      setError(cause.message);
    },
  });

  const selectedAcc = accounts.data?.find((a) => a.id === selectedAccountId);
  const accBalance = selectedAcc ? parseNumericBalance(selectedAcc.balance) : 0;
  const numAmount = parseFloat(enteredAmount) || 0;
  const isCreditCard = selectedAcc?.type === 'credit_card';
  const isInsufficient = selectedAcc && !isCreditCard && numAmount > 0 && accBalance < numAmount;
  const validCardPreview = Boolean(isCreditCard && selectedAcc?.debtId && cardPreview && previewAccountId === selectedAccountId && Number(cardPreview.purchase_amount) === numAmount);
  const requiresOverdraftConsent = validCardPreview && Boolean(cardPreview?.uses_overdraft);
  const overdraftConsentMissing = requiresOverdraftConsent && !confirmOverdraft;

  // Edit form balance calculation
  const editAcc = accounts.data?.find((a) => a.id === editAccountId);
  const editAccBal = editAcc ? parseNumericBalance(editAcc.balance) : 0;
  const editNumAmt = parseFloat(editAmount) || 0;
  const isEditCc = editAcc?.type === 'credit_card';
  const oldAmt = editingExpense && editingExpense.account_id === editAccountId ? parseNumericBalance(editingExpense.amount) : 0;
  const editAvailable = editAccBal + oldAmt;
  const isEditInsufficient = editAcc && !isEditCc && editNumAmt > 0 && editAvailable < editNumAmt;

  function submitCreate(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError('');
    const form = event.currentTarget;
    const data = new FormData(form);
    const accountId = String(data.get('account_id'));
    const amountStr = String(data.get('amount'));

    const selAcc = accounts.data?.find((a) => a.id === accountId);
    const accBal = selAcc ? parseNumericBalance(selAcc.balance) : 0;
    const numAmt = parseFloat(amountStr) || 0;
    const isCc = selAcc?.type === 'credit_card';
    if (isCc && (!validCardPreview || overdraftConsentMissing)) {
      setError('Evalúa la tarjeta y confirma el sobregiro cuando corresponda antes de registrar el gasto.');
      return;
    }

    if (selAcc && !isCc && numAmt > 0 && accBal < numAmt) {
      setError(`La cuenta "${selAcc.name}" no tiene fondos suficientes. Balance disponible: ${selAcc.balance}`);
      return;
    }

    create.mutate({
      account_id: accountId,
      category: String(data.get('category')),
      amount: amountStr,
      occurred_at: String(data.get('occurred_at')),
      description: String(data.get('description')),
      reference_number: String(data.get('reference_number') || '') || null,
      idempotency_key: createUuid(),
      confirm_overdraft: Boolean(requiresOverdraftConsent && confirmOverdraft),
    }, { onSuccess: () => { form.reset(); setShowForm(false); setSelectedAccountId(''); setEnteredAmount(''); setCardPreview(null); setConfirmOverdraft(false); } });
  }

  function submitUpdate(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!editingExpense?.id) return;
    setError('');
    const form = event.currentTarget;
    const data = new FormData(form);
    const accountId = String(data.get('account_id'));
    const amountStr = String(data.get('amount'));

    const selAcc = accounts.data?.find((a) => a.id === accountId);
    const accBal = selAcc ? parseNumericBalance(selAcc.balance) : 0;
    const numAmt = parseFloat(amountStr) || 0;
    const isCc = selAcc?.type === 'credit_card';

    const oldAmountNum = editingExpense.account_id === accountId ? parseNumericBalance(editingExpense.amount) : 0;
    const availableBal = accBal + oldAmountNum;

    if (selAcc && !isCc && numAmt > 0 && availableBal < numAmt) {
      setError(`La cuenta "${selAcc.name}" no tiene fondos suficientes. Balance disponible: RD$ ${availableBal.toFixed(2)}`);
      return;
    }

    updateExpense.mutate({
      id: editingExpense.id,
      input: {
        account_id: accountId,
        category: String(data.get('category')),
        amount: amountStr,
        occurred_at: String(data.get('occurred_at')),
        description: String(data.get('description')),
        reference_number: String(data.get('reference_number') || '') || null,
      },
    });
  }

  if (expenses.isPending || accounts.isPending) return <LoadingState />;
  if (expenses.isError) return <ErrorState onRetry={() => void expenses.refetch()} />;
  if (accounts.isError) return <ErrorState onRetry={() => void accounts.refetch()} />;

  return <>
    <div className="page-intro">
      <p>Registra compras o consumos directos debitando inmediatamente de tu cuenta.</p>
      <Button appearance="primary" onClick={() => { setEditingExpense(null); setShowForm((value) => !value); }}>{showForm ? 'Cancelar' : 'Registrar gasto'}</Button>
    </div>
    {error && <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar>}
    {showForm && <Card className="inline-form">
      <div><Subtitle1>Nuevo gasto directo</Subtitle1><Caption1>Se creará un débito inmediato en la cuenta seleccionada.</Caption1></div>
      <form className="finance-form" onSubmit={submitCreate}>
        <div className="form-grid">
          <Field label="Descripción / Concepto" required><Input name="description" required maxLength={500} placeholder="ej. Supermercado o Combustible" /></Field>
          <Field label="Categoría" required><select name="category" required defaultValue="Alimentación">{billCategories.map((cat) => <option key={cat} value={cat}>{cat}</option>)}</select></Field>
          <Field label="Monto (DOP)" required><Input name="amount" type="number" min="0.01" step="0.01" value={enteredAmount} onChange={(e) => { setEnteredAmount(e.target.value); setCardPreview(null); setConfirmOverdraft(false); }} required /></Field>
          <Field label="Cuenta de origen" required>
            <select name="account_id" required value={selectedAccountId} onChange={(e) => { setSelectedAccountId(e.target.value); setCardPreview(null); setConfirmOverdraft(false); }}>
              <option value="" disabled>Seleccionar cuenta</option>
              {accounts.data.map((account) => {
                const bal = parseNumericBalance(account.balance);
                const isCc = account.type === 'credit_card';
                const noFunds = !isCc && numAmount > 0 && bal < numAmount;
                return (
                  <option key={account.id} value={account.id} disabled={noFunds}>
                    {account.name} ({account.balance}){noFunds ? ' ❌ (Sin fondos suficientes)' : ''}
                  </option>
                );
              })}
            </select>
          </Field>
          <Field label="Fecha del gasto" required><Input name="occurred_at" type="date" defaultValue={today} required /></Field>
          <Field label="Referencia / NCF (opcional)"><Input name="reference_number" maxLength={80} /></Field>
        </div>
        {isInsufficient && (
          <MessageBar intent="error" style={{ marginTop: '10px' }}>
            <MessageBarBody>⚠️ Balance insuficiente: La cuenta "{selectedAcc?.name}" dispone de {selectedAcc?.balance} y no cubre el gasto de RD$ {numAmount.toFixed(2)}.</MessageBarBody>
          </MessageBar>
        )}
        {isCreditCard && selectedAcc?.debtId && (
          <div style={{ marginTop: '12px', display: 'grid', gap: '8px' }}>
            <Button
              type="button"
              appearance="secondary"
              disabled={numAmount <= 0 || previewCard.isPending}
              onClick={() => previewCard.mutate({ debtId: selectedAcc.debtId!, amount: enteredAmount })}
            >
              {previewCard.isPending ? 'Evaluando…' : 'Evaluar autorización en el servidor'}
            </Button>
            {cardPreview && (
              <Card style={{ padding: '12px', border: '1px solid var(--border)' }}>
                <Subtitle1>Evaluación de crédito y sobregiro</Subtitle1>
                <div style={{ display: 'grid', gap: '6px', marginTop: '8px', fontSize: '13px' }}>
                  {cardPreview.uses_overdraft && (
                    <MessageBar intent="warning">
                      <MessageBarBody>
                        <strong>Advertencia: esta compra utilizará sobregiro.</strong> El saldo proyectado superará el límite ordinario de RD$ {cardPreview.approved_limit}. Sobregiro proyectado (incluidos cargos estimados): RD$ {cardPreview.projected_overdraft}. Las comisiones y los intereses pueden variar según las condiciones de la tarjeta.
                      </MessageBarBody>
                    </MessageBar>
                  )}
                  <div><strong>Importe:</strong> RD$ {cardPreview.purchase_amount}</div>
                  <div><strong>Saldo anterior:</strong> RD$ {cardPreview.previous_balance}</div>
                  <div><strong>Saldo proyectado:</strong> RD$ {cardPreview.projected_balance}</div>
                  <div><strong>Límite ordinario:</strong> RD$ {cardPreview.approved_limit}</div>
                  <div><strong>Crédito disponible:</strong> RD$ {cardPreview.available_credit}</div>
                  <div><strong>Sobregiro disponible:</strong> RD$ {cardPreview.available_overdraft}</div>
                  <div><strong>Comisión:</strong> RD$ {cardPreview.total_fee}</div>
                  <div><strong>Impuestos:</strong> RD$ {cardPreview.tax}</div>
                  <div><strong>Total proyectado:</strong> RD$ {cardPreview.total_charge}</div>
                  {cardPreview.fee_rule ? (
                    <div>
                      <strong>Condición:</strong>{' '}
                      {cardPreview.fee_rule.verification_status === 'verified'
                        ? `Regla verificada (${cardPreview.fee_rule.trigger})`
                        : 'Configuración individual no oficial'}
                      {cardPreview.fee_rule.source_url && <> · <a href={cardPreview.fee_rule.source_url} target="_blank" rel="noopener noreferrer">Fuente</a></>}
                    </div>
                  ) : (
                    <div><strong>Condición:</strong> Sin comisión aplicable confirmada</div>
                  )}
                  <div><strong>Tasa aplicable:</strong> {cardPreview.contractual_conditions.annual_interest_rate}% anual{cardPreview.contractual_conditions.is_promotional ? ' (promoción)' : ''}</div>
                  <div><strong>Fuente de tasa:</strong> {cardPreview.contractual_conditions.effective_source}</div>
                  <div><strong>Fuente de comisión:</strong> {cardPreview.contractual_conditions.fee_source}</div>
                  <div><strong>Fecha de vigencia:</strong> {cardPreview.contractual_conditions.operation_date}</div>
                  <div><strong>Explicación:</strong> {cardPreview.contractual_conditions.rule_used}</div>
                  {cardPreview.contractual_conditions.verified_official_source && (
                    <div><a href={cardPreview.contractual_conditions.verified_official_source} target="_blank" rel="noopener noreferrer">Fuente oficial verificada</a></div>
                  )}
                  {cardPreview.uses_overdraft && (
                    <label style={{ display: 'flex', alignItems: 'flex-start', gap: '8px', marginTop: '8px' }}>
                      <input type="checkbox" checked={confirmOverdraft} onChange={(e) => setConfirmOverdraft(e.target.checked)} />
                      <span>Comprendo que esta compra utilizará sobregiro y puede generar cargos adicionales.</span>
                    </label>
                  )}
                </div>
              </Card>
            )}
          </div>
        )}
        <Button
          type="submit"
          appearance="primary"
          disabled={create.isPending || Boolean(isInsufficient) || (Boolean(isCreditCard) && (!validCardPreview || Boolean(overdraftConsentMissing)))}
          style={{ marginTop: '12px' }}
        >
          {create.isPending ? 'Guardando…' : 'Guardar gasto'}
        </Button>
      </form>
    </Card>}

    {editingExpense && <Card className="inline-form">
      <div><Subtitle1>Editar gasto directo: {editingExpense.description}</Subtitle1><Caption1>Modifica la categoría, cuenta o monto del gasto.</Caption1></div>
      <form className="finance-form" onSubmit={submitUpdate}>
        <div className="form-grid">
          <Field label="Descripción / Concepto" required><Input name="description" defaultValue={editingExpense.description} required maxLength={500} /></Field>
          <Field label="Categoría" required><select name="category" required defaultValue={editingExpense.category}>{billCategories.map((cat) => <option key={cat} value={cat}>{cat}</option>)}</select></Field>
          <Field label="Monto (DOP)" required><Input name="amount" type="number" min="0.01" step="0.01" defaultValue={editAmount || editingExpense.amount.replace(/[^0-9.]/g, '')} onChange={(e) => setEditAmount(e.target.value)} required /></Field>
          <Field label="Cuenta de origen" required>
            <select name="account_id" required defaultValue={editAccountId || editingExpense.account_id} onChange={(e) => setEditAccountId(e.target.value)}>
              <option value="" disabled>Seleccionar cuenta</option>
              {accounts.data.map((account) => {
                const bal = parseNumericBalance(account.balance);
                const isCc = account.type === 'credit_card';
                const oldAmtNum = editingExpense?.account_id === account.id ? parseNumericBalance(editingExpense.amount) : 0;
                const availableBal = bal + oldAmtNum;
                const noFunds = !isCc && editNumAmt > 0 && availableBal < editNumAmt;
                return (
                  <option key={account.id} value={account.id} disabled={noFunds}>
                    {account.name} ({account.balance}){noFunds ? ' ❌ (Sin fondos suficientes)' : ''}
                  </option>
                );
              })}
            </select>
          </Field>
          <Field label="Fecha del gasto" required><Input name="occurred_at" type="date" defaultValue={editingExpense.occurred_at} required /></Field>
          <Field label="Referencia / NCF (opcional)"><Input name="reference_number" defaultValue={editingExpense.reference_number || ''} maxLength={80} /></Field>
        </div>
        {isEditInsufficient && (
          <MessageBar intent="error" style={{ marginTop: '10px' }}>
            <MessageBarBody>⚠️ Balance insuficiente: La cuenta "{editAcc?.name}" dispone de RD$ {editAvailable.toFixed(2)} disponible y no cubre el monto de RD$ {editNumAmt.toFixed(2)}.</MessageBarBody>
          </MessageBar>
        )}
        <div style={{ display: 'flex', gap: '8px', marginTop: '12px' }}>
          <Button type="submit" appearance="primary" disabled={updateExpense.isPending || Boolean(isEditInsufficient)}>{updateExpense.isPending ? 'Guardando…' : 'Actualizar gasto'}</Button>
          <Button appearance="subtle" onClick={() => setEditingExpense(null)}>Cancelar</Button>
        </div>
      </form>
    </Card>}

    {accounts.data.length === 0 && <MessageBar intent="warning"><MessageBarBody>Crea una cuenta antes de registrar un gasto.</MessageBarBody></MessageBar>}
    {expenses.data.length === 0 ? <EmptyState label="No hay gastos directos registrados aún." /> :
      <FinanceTable
        columns={['Descripción', 'Categoría', 'Cuenta', 'Fecha', 'Monto', 'Acciones']}
        rows={expenses.data.map((exp: ExpenseRecord) => ({
          Descripción: exp.description,
          Categoría: exp.category,
          Cuenta: exp.account || '—',
          Fecha: exp.occurred_at,
          Monto: exp.amount,
          Acciones: (
            <div style={{ display: 'flex', gap: '6px' }}>
              <Button appearance="subtle" size="small" onClick={() => { setShowForm(false); setEditingExpense(exp); setEditAccountId(exp.account_id); setEditAmount(exp.amount.replace(/[^0-9.]/g, '')); }}>Editar</Button>
              <Button appearance="subtle" size="small" style={{ color: '#ff4d4f' }} onClick={() => { if (confirm(`¿Deseas eliminar este gasto de ${exp.amount}?`)) deleteExpense.mutate(exp.id!); }}>Eliminar</Button>
            </div>
          ),
        }))}
      />}
  </>;
}

function TransfersPage() {
  const [showForm, setShowForm] = useState(false);
  const [error, setError] = useState('');
  const queryClient = useQueryClient();
  const transfers = useQuery({ queryKey: ['transfers'], queryFn: () => api.listTransfers(), refetchOnMount: 'always' });
  const accounts = useQuery({ queryKey: ['accounts'], queryFn: api.listAccounts, refetchOnMount: 'always' });
  const create = useMutation({
    mutationFn: api.createTransfer,
    onSuccess: async () => {
      await queryClient.invalidateQueries();
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

function CommitmentStatementDetail({ debtId }: { debtId: string }) {
  const statements = useQuery({
    queryKey: ['debt-statements', debtId],
    queryFn: () => api.listDebtStatements(debtId),
  });

  if (statements.isPending) return <Caption1>Cargando estados de cuenta…</Caption1>;
  if (statements.isError) return <ErrorState onRetry={() => void statements.refetch()} />;
  if (statements.data.length === 0) return <Caption1>No hay estados de cuenta registrados.</Caption1>;

  return <div className="record-list" style={{ marginTop: '8px' }}>{statements.data.slice(0, 6).map((statement) => (
    <Card key={statement.id} className="content-card" style={{ padding: '10px' }}>
      <div className="record-heading">
        <div><Caption1>Corte {statement.statementDate}</Caption1><strong>Vence {statement.dueDate}</strong></div>
        <strong>{statement.closingBalance}</strong>
      </div>
      <Caption1>Mínimo: {statement.minimumPaymentConfirmed ? statement.minimumPayment ?? 'Confirmado sin importe' : 'No confirmado'}</Caption1>
    </Card>
  ))}</div>;
}

function CommitmentsPage() {
  const [showForm, setShowForm] = useState(false);
  const [createCutDay, setCreateCutDay] = useState('');
  const [createGrace, setCreateGrace] = useState('');
  const [editCutDay, setEditCutDay] = useState<string | null>(null);
  const [editGrace, setEditGrace] = useState<string | null>(null);
  const [editingCommitment, setEditingCommitment] = useState<CommitmentRecord | null>(null);
  const [error, setError] = useState('');
  const [createCommitmentType, setCreateCommitmentType] = useState<CommitmentType>('utility');
  const [editCommitmentType, setEditCommitmentType] = useState<CommitmentType>('utility');
  const [activeTab, setActiveTab] = useState<'all' | 'credit_card' | 'loan' | 'utility' | 'rent' | 'subscription' | 'insurance' | 'archived'>('all');
  const [search, setSearch] = useState('');
  const [institution, setInstitution] = useState('');
  const [sort, setSort] = useState<'due' | 'amount' | 'name'>('due');
  const [page, setPage] = useState(1);
  const [cardPaymentPreview, setCardPaymentPreview] = useState<{
    commitment: CommitmentRecord;
    occurrenceId: string;
    accountId: string;
    amount: string;
    paidAt: string;
  } | null>(null);
  const queryClient = useQueryClient();
  const query = useQuery({
    queryKey: [...financialQueryKeys.commitments, activeTab, search, institution, sort, page],
    queryFn: () => api.listCommitments({
      status: activeTab === 'archived' ? 'inactive' : 'active',
      type: activeTab === 'all' || activeTab === 'archived' ? undefined : activeTab,
      institution: institution || undefined,
      search: search || undefined,
      sort,
      page,
      per_page: 25,
    }),
    refetchOnMount: 'always',
  });
  const accounts = useQuery({ queryKey: financialQueryKeys.accounts, queryFn: api.listAccounts, refetchOnMount: 'always' });

  const invalidateCommitmentData = async () => {
    await Promise.all(commitmentInvalidationKeys.map((queryKey) =>
      queryClient.invalidateQueries({ queryKey: [...queryKey] }),
    ));
  };

  const create = useMutation({
    mutationFn: api.createCommitment,
    onSuccess: async () => {
      await invalidateCommitmentData();
    },
    onError: (cause) => setError(cause.message),
  });

  const updateCommitment = useMutation({
    mutationFn: ({ commitmentId, input }: { commitmentId: string; input: any }) => api.updateCommitment(commitmentId, input),
    onSuccess: async () => {
      setEditingCommitment(null);
      await invalidateCommitmentData();
    },
    onError: (cause) => setError(cause.message),
  });

  const pauseCommitment = useMutation({
    mutationFn: (commitmentId: string) => api.pauseCommitment(commitmentId),
    onSuccess: async () => {
      await invalidateCommitmentData();
    },
    onError: (cause) => setError(cause.message),
  });

  const resumeCommitment = useMutation({
    mutationFn: (commitmentId: string) => api.resumeCommitment(commitmentId),
    onSuccess: async () => {
      await invalidateCommitmentData();
    },
    onError: (cause) => setError(cause.message),
  });

  const deleteCommitment = useMutation({
    mutationFn: (commitmentId: string) => api.deleteCommitment(commitmentId),
    onSuccess: async () => {
      await invalidateCommitmentData();
    },
    onError: (cause) => setError(cause.message),
  });

  const previewCardPayment = useMutation({
    mutationFn: ({ debtId, amount }: { debtId: string; amount: string }) => api.previewCreditCardAuthorization(debtId, amount),
    onError: (cause) => setError(cause.message),
  });

  const pay = useMutation({
    mutationFn: ({ id, occurrenceId, input }: { id: string; occurrenceId: string; input: { amount: string; account_id: string; paid_at: string; idempotency_key: string } }) => api.recordCommitmentPayment(id, occurrenceId, input),
    onSuccess: async () => {
      setCardPaymentPreview(null);
      await invalidateCommitmentData();
    },
    onError: (cause) => setError(cause.message),
  });
  const newCardCycle = cardCyclePreview(Number(createCutDay), Number(createGrace));

  function submitCreate(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError('');
    const form = event.currentTarget;
    const data = new FormData(form);
    const type = createCommitmentType;

    if (type === 'credit_card') {
      const currentBal = String(data.get('current_balance') || '0');
      const stmtBal = String(data.get('statement_balance') || currentBal);
      const stmtDay = Number(data.get('statement_day'));
      const graceDays = Number(data.get('payment_grace_days'));

      create.mutate({
        name: String(data.get('name')),
        type,
        reference_number: String(data.get('reference_number') || '') || null,
        institution: String(data.get('institution') || '') || null,
        current_balance: currentBal,
        statement_balance: stmtBal,
        minimum_payment: String(data.get('minimum_payment') || '') || null,
        statement_day: stmtDay,
        payment_grace_days: graceDays,
        interest_rate: String(data.get('interest_rate') || '0'),
        notes: String(data.get('notes') || '') || null,
        approved_credit_limit: String(data.get('approved_credit_limit') || '') || null,
        authorized_overdraft_limit: String(data.get('authorized_overdraft_limit') || '') || null,
        overdraft_fee_type: String(data.get('overdraft_fee_type') || 'none'),
        overdraft_fee_amount: String(data.get('overdraft_fee_amount') || '0'),
        overdraft_fee_percentage: String(data.get('overdraft_fee_percentage') || '0'),
        limit_status: String(data.get('approved_credit_limit') ?? '').trim() !== '' ? 'confirmed' : 'unknown',
        card_product_name: String(data.get('card_product_name') || '') || null,
        card_variant: String(data.get('card_variant') || '') || null,
        card_network: String(data.get('card_network') || '') as 'Visa' | 'Mastercard' | 'Amex' | 'Discover' | 'Otra' | null,
        card_currency: String(data.get('card_currency') || '') as 'DOP' | 'USD' | 'EUR' | null,
        issued_at: String(data.get('issued_at') || '') || null,
        annual_fee: String(data.get('annual_fee') || '') || null,
        replacement_fee: String(data.get('replacement_fee') || '') || null,
        cash_advance_fee: String(data.get('cash_advance_fee') || '') || null,
        late_fee: String(data.get('late_fee') || '') || null,
        card_benefits: splitManualList(data.get('card_benefits')),
        card_payment_methods: splitManualList(data.get('card_payment_methods')),
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
    const type = editCommitmentType;

    if (type === 'credit_card') {
      const currentBal = String(data.get('current_balance') || '0');
      const stmtBal = String(data.get('statement_balance') || currentBal);
      const stmtDay = Number(data.get('statement_day'));
      const graceDays = Number(data.get('payment_grace_days'));

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
        interest_rate: String(data.get('interest_rate') || '0'),
          notes: String(data.get('notes') || '') || null,
          approved_credit_limit: String(data.get('approved_credit_limit') || '') || null,
          authorized_overdraft_limit: String(data.get('authorized_overdraft_limit') || '') || null,
        overdraft_fee_type: String(data.get('overdraft_fee_type') || 'none'),
        overdraft_fee_amount: String(data.get('overdraft_fee_amount') || '0'),
        overdraft_fee_percentage: String(data.get('overdraft_fee_percentage') || '0'),
          limit_status: String(data.get('approved_credit_limit') ?? '').trim() !== '' ? 'confirmed' : 'unknown',
          card_product_name: String(data.get('card_product_name') || '') || null,
          card_variant: String(data.get('card_variant') || '') || null,
          card_network: String(data.get('card_network') || '') as 'Visa' | 'Mastercard' | 'Amex' | 'Discover' | 'Otra' | null,
          card_currency: String(data.get('card_currency') || '') as 'DOP' | 'USD' | 'EUR' | null,
          issued_at: String(data.get('issued_at') || '') || null,
          annual_fee: String(data.get('annual_fee') || '') || null,
          replacement_fee: String(data.get('replacement_fee') || '') || null,
          cash_advance_fee: String(data.get('cash_advance_fee') || '') || null,
          late_fee: String(data.get('late_fee') || '') || null,
          card_benefits: splitManualList(data.get('card_benefits')),
          card_payment_methods: splitManualList(data.get('card_payment_methods')),
        },
      });
      return;
    }

    if (type === 'loan') {
      updateCommitment.mutate({
        commitmentId: editingCommitment.commitmentId ?? editingCommitment.id!,
        input: {
          name: String(data.get('name')),
          type,
          amount: String(data.get('amount')),
          frequency: String(data.get('frequency')),
          next_due_date: String(data.get('next_due_date')),
          reference_number: String(data.get('reference_number') || '') || null,
          institution: String(data.get('institution') || '') || null,
          current_balance: String(data.get('current_balance') || '') || null,
          interest_rate: String(data.get('interest_rate') || '') || null,
          maturity_date: String(data.get('maturity_date') || '') || null,
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
    const accountId = String(data.get('account_id'));
    const amount = String(data.get('amount'));
    const paidAt = String(data.get('paid_at'));
    const selectedAccount = accounts.data?.find((account) => account.id === accountId);

    if (selectedAccount?.type === 'credit_card') {
      if (!selectedAccount.debtId) {
        setError('No se encontró la deuda vinculada a la tarjeta seleccionada como medio de pago.');
        return;
      }
      previewCardPayment.mutate({ debtId: selectedAccount.debtId, amount }, {
        onSuccess: () => setCardPaymentPreview({
          commitment,
          occurrenceId: commitment.id!,
          accountId,
          amount,
          paidAt,
        }),
      });
      return;
    }

    pay.mutate({
      id: commitment.commitmentId!,
      occurrenceId: commitment.id!,
      input: { amount, account_id: accountId, paid_at: paidAt, idempotency_key: createUuid() },
    }, { onSuccess: () => form.reset() });
  }

  if (query.isPending || accounts.isPending) return <LoadingState />;
  if (query.isError) return <ErrorState onRetry={() => void query.refetch()} />;
  if (accounts.isError) return <ErrorState onRetry={() => void accounts.refetch()} />;
  return <>
    <div className="page-intro"><p>Gestiona obligaciones financieras de forma organizada y registra sus vencimientos, pagos y datos contractuales.</p><Button appearance="primary" onClick={() => setShowForm((value) => !value)}>{showForm ? 'Cerrar formulario' : activeTab === 'credit_card' ? 'Agregar tarjeta' : activeTab === 'loan' ? 'Agregar préstamo' : activeTab === 'rent' ? 'Agregar alquiler / vivienda' : activeTab === 'subscription' ? 'Agregar suscripción' : activeTab === 'insurance' ? 'Agregar seguro' : activeTab === 'utility' ? 'Agregar servicio / factura' : 'Agregar compromiso'}</Button></div>
    <TabList
      selectedValue={activeTab}
      onTabSelect={(_, data) => { const nextTab = data.value as typeof activeTab; setActiveTab(nextTab); setPage(1); setEditingCommitment(null); setShowForm(false); setCreateCommitmentType(nextTab === 'credit_card' ? 'credit_card' : nextTab === 'loan' ? 'loan' : nextTab === 'rent' ? 'rent' : nextTab === 'subscription' ? 'subscription' : nextTab === 'insurance' ? 'insurance' : 'utility'); }}
      aria-label="Categorías de compromisos"
      style={{ marginBottom: '12px', overflowX: 'auto' }}
    >
      <Tab value="all">Todos</Tab>
      <Tab value="credit_card">Tarjetas</Tab>
      <Tab value="loan">Préstamos</Tab>
      <Tab value="utility">Servicios y facturas</Tab>
      <Tab value="rent">Alquiler / Vivienda</Tab>
      <Tab value="subscription">Suscripciones</Tab>
      <Tab value="insurance">Seguros</Tab>
      <Tab value="archived">Archivados</Tab>
    </TabList>
    <div className="commitment-summary" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: '10px', marginBottom: '12px' }}>
      {[
        ['Pendiente del período', query.data.summary.pending_period],
        ['Vencido', query.data.summary.overdue],
        ['Pagado del período', query.data.summary.paid_period],
        ['Deuda pendiente', query.data.summary.pending_debt],
        ['Activos', String(query.data.summary.active_count)],
      ].map(([label, value]) => <Card key={label} className="content-card" style={{ padding: '12px' }}><Caption1>{label}</Caption1><div style={{ marginTop: '5px', fontWeight: 700, color: 'var(--primary)' }}>{value}</div></Card>)}
    </div>
    <Card className="inline-form" style={{ marginBottom: '12px' }}>
      <div className="form-grid">
        <Field label="Buscar"><Input value={search} onChange={(event) => { setSearch(event.target.value); setPage(1); }} placeholder="Nombre, institución o referencia" /></Field>
        <Field label="Institución"><Input value={institution} onChange={(event) => { setInstitution(event.target.value); setPage(1); }} placeholder="Banco o proveedor" /></Field>
        <Field label="Ordenar"><select value={sort} onChange={(event) => { setSort(event.target.value as typeof sort); setPage(1); }}><option value="due">Próximo vencimiento</option><option value="amount">Importe</option><option value="name">Nombre</option></select></Field>
      </div>
    </Card>
    {error && <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar>}
    {showForm && <div className="commitment-form-overlay" role="dialog" aria-modal="true"><Card className="inline-form commitment-form-panel">
      <div><Subtitle1>{activeTab === 'credit_card' ? 'Agregar tarjeta' : activeTab === 'loan' ? 'Agregar préstamo' : activeTab === 'rent' ? 'Agregar alquiler / vivienda' : activeTab === 'subscription' ? 'Agregar suscripción' : activeTab === 'insurance' ? 'Agregar seguro' : 'Agregar servicio / factura'}</Subtitle1><Caption1>Completa los datos financieros manuales.</Caption1></div>
      <form className="finance-form" onSubmit={submitCreate}>
        <div className="form-grid">
          <Field label={createCommitmentType === 'utility' ? 'Nombre de la factura' : createCommitmentType === 'subscription' ? 'Nombre del servicio' : createCommitmentType === 'credit_card' ? 'Nombre de la tarjeta' : createCommitmentType === 'loan' ? 'Nombre del préstamo' : 'Nombre'} required><Input name="name" required maxLength={160} placeholder={createCommitmentType === 'utility' ? 'ej. Electricidad' : createCommitmentType === 'subscription' ? 'ej. Streaming' : createCommitmentType === 'loan' ? 'ej. Préstamo de vehículo' : createCommitmentType === 'credit_card' ? 'ej. Visa Banco Popular' : 'ej. Alquiler'} /></Field>

          {createCommitmentType === 'credit_card' && <>
            <Field label="Institución financiera"><Input name="institution" maxLength={160} placeholder="ej. Banco Popular" /></Field>
            <Field label="Referencia (últimos 4 dígitos)" required><Input name="reference_number" required maxLength={4} inputMode="numeric" pattern="[0-9]{4}" placeholder="ej. 4321" /></Field>
            <Field label="Saldo total utilizado (DOP)" required><Input name="current_balance" type="number" min="0" step="0.01" required placeholder="Monto consumido hasta hoy" /></Field>
            <Field label="Día de corte (1-31)" required><Input name="statement_day" type="number" min="1" max="31" required value={createCutDay} onChange={(_, data) => setCreateCutDay(data.value)} placeholder="Indica el día real del banco" /></Field>
            <Field label="Días entre corte y vencimiento (según contrato)" required><Input name="payment_grace_days" type="number" min="1" max="31" required value={createGrace} onChange={(_, data) => setCreateGrace(data.value)} placeholder="Según las condiciones de tu banco" /></Field>
            <Field label="Tasa de financiamiento anual (%)" required><Input name="interest_rate" type="number" min="0" max="100" step="0.001" required placeholder="Ej. 40" /></Field>
            <p className="form-help">Se aplica sobre el saldo financiable no pagado según las condiciones del banco; es distinta de la tasa de sobregiro. Ejemplo: 40% anual ≈ 3.3333% mensual y 0.10959% diario (base 365).</p>
            {newCardCycle && <div className="card-cycle-preview" role="status" style={{ gridColumn: '1 / -1', padding: '12px', border: '1px solid var(--colorNeutralStroke2)', borderRadius: 8 }}>
              <strong>Calendario calculado</strong>
              <div>Último corte: {newCardCycle.lastCutoff}</div>
              <div>Vencimiento del último corte: {newCardCycle.lastDue}</div>
              <div>Próximo corte: {newCardCycle.nextCutoff}</div>
              <div>Vencimiento del próximo corte: {newCardCycle.nextCutoffDue}</div>
              <div><strong>Próximo vencimiento previsto: {newCardCycle.nextDue}</strong></div>
              <Caption1>La fecha real indicada por el banco prevalece sobre esta proyección.</Caption1>
            </div>}

            <Field label="Saldo facturado del último corte (DOP)"><Input name="statement_balance" type="number" min="0" step="0.01" placeholder="Balance del último estado de cuenta" /></Field>
            <Field label="Pago mínimo informado por el banco (DOP)"><Input name="minimum_payment" type="number" min="0" step="0.01" placeholder="Déjalo vacío si aún no lo tienes" /></Field>
            <Field label="Límite aprobado (DOP)"><Input name="approved_credit_limit" type="number" min="0" step="0.01" placeholder="Déjalo vacío si es desconocido" /></Field>
            <Field label="Sobregiro autorizado (DOP)"><Input name="authorized_overdraft_limit" type="number" min="0" step="0.01" placeholder="Déjalo vacío si es desconocido" /></Field>
            <Field label="Cobro por sobregiro al corte"><select name="overdraft_fee_type" defaultValue="none"><option value="none">Sin cargo configurado</option><option value="cutoff_fixed">Comisión fija por corte</option><option value="cutoff_percentage">Porcentaje sobre el exceso al corte</option><option value="cutoff_both">Porcentaje + comisión fija</option><option value="cutoff_daily">Interés diario sobre exceso (tasa anual / 365)</option></select></Field>
            <Field label="Comisión fija por corte (DOP)"><Input name="overdraft_fee_amount" type="number" min="0" step="0.01" placeholder="Ej. 250.00" /></Field>
            <Field label="Tasa de sobregiro (%)"><Input name="overdraft_fee_percentage" type="number" min="0" max="100" step="0.01" placeholder="Por corte o anual, según modalidad" /></Field>
            <p className="form-help">El cargo se registra al cerrar el ciclo, solo si corresponde según la modalidad elegida. Para interés diario, la tasa es anual y se calcula por días.</p>
          </>}

          {createCommitmentType === 'loan' && <>
            <Field label="Banco / Prestamista"><Input name="institution" maxLength={160} placeholder="ej. Banco BHD" /></Field>
            <Field label="Número de préstamo / referencia" required><Input name="reference_number" required maxLength={80} placeholder="Referencia de préstamo" /></Field>
            <Field label="Saldo actual del préstamo (DOP)" required><Input name="current_balance" type="number" min="0" step="0.01" required /></Field>
            <Field label="Tasa de interés anual (%)" required><Input name="interest_rate" type="number" min="0" max="100" step="0.001" required /></Field>
            <Field label="Fecha de vencimiento final" required><Input name="maturity_date" type="date" required /></Field>
            <Field label="Monto de la cuota (DOP)" required><Input name="amount" type="number" min="0.01" step="0.01" required /></Field>
            <Field label="Frecuencia de pago" required><select name="frequency" defaultValue="monthly"><option value="monthly">Mensual</option><option value="weekly">Semanal</option><option value="biweekly">Cada dos semanas</option><option value="quarterly">Trimestral</option><option value="yearly">Anual</option></select></Field>
            <Field label="Próxima fecha de vencimiento" required><Input name="next_due_date" type="date" defaultValue={today} required /></Field>
          </>}

          {(createCommitmentType === 'utility' || createCommitmentType === 'subscription' || createCommitmentType === 'insurance' || createCommitmentType === 'rent' || createCommitmentType === 'other') && <>
            {(createCommitmentType === 'utility' || createCommitmentType === 'subscription' || createCommitmentType === 'insurance') && <Field label="Categoría de factura / servicio" required><select name="category" required defaultValue=""><option value="" disabled>Seleccionar categoría</option>{billCategories.map((category) => <option key={category} value={category}>{category}</option>)}</select></Field>}
            <Field label={createCommitmentType === 'rent' ? 'Arrendador / Administrador' : 'Proveedor / Institución'}><Input name="institution" maxLength={160} placeholder="ej. Compañía de servicios" /></Field>
            <Field label="Número de contrato / referencia"><Input name="reference_number" maxLength={80} /></Field>
            <Field label="Monto a pagar (DOP)" required><Input name="amount" type="number" min="0.01" step="0.01" required /></Field>
            <Field label="Frecuencia de pago" required><select name="frequency" defaultValue="monthly"><option value="monthly">Mensual</option><option value="once">Una sola vez</option><option value="weekly">Semanal</option><option value="biweekly">Cada dos semanas</option><option value="quarterly">Trimestral</option><option value="yearly">Anual</option></select></Field>
            <Field label="Próxima fecha de vencimiento" required><Input name="next_due_date" type="date" defaultValue={today} required /></Field>
          </>}

          <Field label="Notas adicionales"><Input name="notes" maxLength={2000} /></Field>
        </div>
        <div className="commitment-form-actions"><Button type="button" appearance="subtle" onClick={() => setShowForm(false)}>Cancelar</Button><Button type="submit" appearance="primary" disabled={create.isPending}>{create.isPending ? 'Guardando…' : 'Guardar'}</Button></div>
      </form>
    </Card></div>}
    {editingCommitment && <div className="commitment-form-overlay" role="dialog" aria-modal="true"><Card className="inline-form commitment-form-panel">
      <div><Subtitle1>Editar {editCommitmentType === 'credit_card' ? 'tarjeta' : editCommitmentType === 'loan' ? 'préstamo' : editCommitmentType === 'rent' ? 'alquiler / vivienda' : 'compromiso'}: {editingCommitment.name}</Subtitle1><Caption1>Los cambios se aplican manualmente sin modificar registros históricos.</Caption1></div>
      <form className="finance-form" onSubmit={submitUpdate}>
        <div className="form-grid">
          <Field label="Nombre" required><Input name="name" defaultValue={editingCommitment.name} required maxLength={160} /></Field>

          {editCommitmentType === 'credit_card' ? <>
            <Field label="Referencia (últimos 4 dígitos)" required><Input name="reference_number" defaultValue={editingCommitment.referenceNumber ?? ''} required maxLength={4} inputMode="numeric" pattern="[0-9]{4}" /></Field>
            <Field label="Banco emisor" required><Input name="institution" defaultValue={editingCommitment.institution ?? ''} required maxLength={160} /></Field>
            <Field label="Saldo total utilizado (DOP)" required><Input name="current_balance" type="number" min="0" step="0.01" defaultValue={String(Number((editingCommitment.currentBalance ?? '0').replace(/[^\d.]/g, '')) || 0)} required /></Field>
            <Field label="Día de corte (1-31)" required><Input name="statement_day" type="number" min="1" max="31" value={editCutDay ?? String(editingCommitment.statementDay ?? '')} onChange={(_, data) => setEditCutDay(data.value)} required /></Field>
            <Field label="Días entre corte y vencimiento (según contrato)" required><Input name="payment_grace_days" type="number" min="1" max="31" value={editGrace ?? String(editingCommitment.paymentGraceDays ?? '')} onChange={(_, data) => setEditGrace(data.value)} required /></Field>
            <Field label="Tasa de financiamiento anual (%)" required><Input name="interest_rate" type="number" min="0" max="100" step="0.001" defaultValue={editingCommitment.interestRate ?? ''} required /></Field>
            <p className="form-help">Tasa anual informada por el banco, independiente del sobregiro. Los intereses se calculan sobre el saldo financiable, no sobre todos los consumos nuevos.</p>
            <Field label="Saldo facturado del último corte (DOP)"><Input name="statement_balance" type="number" min="0" step="0.01" defaultValue={String(Number((editingCommitment.statementBalance ?? '0').replace(/[^\d.]/g, '')) || 0)} /></Field>
            <Field label="Límite aprobado (DOP)"><Input name="approved_credit_limit" type="number" min="0" step="0.01" defaultValue={editingCommitment.approvedCreditLimit ? editingCommitment.approvedCreditLimit.replace('RD$ ', '').replaceAll(',', '') : ''} placeholder="Déjalo vacío si es desconocido" /></Field>
            <Field label="Sobregiro autorizado (DOP)"><Input name="authorized_overdraft_limit" type="number" min="0" step="0.01" defaultValue={editingCommitment.authorizedOverdraftLimit ? editingCommitment.authorizedOverdraftLimit.replace('RD$ ', '').replaceAll(',', '') : ''} placeholder="Déjalo vacío si es desconocido" /></Field>
            <Field label="Cobro por sobregiro al corte"><select name="overdraft_fee_type" defaultValue={editingCommitment.overdraftFeeType ?? 'none'}><option value="none">Sin cargo configurado</option><option value="cutoff_fixed">Comisión fija por corte</option><option value="cutoff_percentage">Porcentaje sobre el exceso al corte</option><option value="cutoff_both">Porcentaje + comisión fija</option><option value="cutoff_daily">Interés diario sobre exceso (tasa anual / 365)</option></select></Field>
            <Field label="Comisión fija por corte (DOP)"><Input name="overdraft_fee_amount" type="number" min="0" step="0.01" defaultValue={editingCommitment.overdraftFeeAmount ?? ''} /></Field>
            <Field label="Tasa de sobregiro (%)"><Input name="overdraft_fee_percentage" type="number" min="0" max="100" step="0.01" defaultValue={editingCommitment.overdraftFeePercentage ?? ''} /></Field>
          </> : editCommitmentType === 'loan' ? <>
            <Field label="Banco / prestamista" required><Input name="institution" defaultValue={editingCommitment.institution ?? ''} required maxLength={160} /></Field>
            <Field label="Número de préstamo / referencia" required><Input name="reference_number" defaultValue={editingCommitment.referenceNumber ?? ''} required maxLength={80} /></Field>
            <Field label="Saldo actual del préstamo (DOP)" required><Input name="current_balance" type="number" min="0" step="0.01" defaultValue={editingCommitment.currentBalance ? editingCommitment.currentBalance.replace('RD$ ', '').replaceAll(',', '') : ''} required /></Field>
            <Field label="Tasa de interés anual (%)" required><Input name="interest_rate" type="number" min="0" max="100" step="0.001" defaultValue={editingCommitment.interestRate ?? ''} required /></Field>
            <Field label="Monto de la cuota (DOP)" required><Input name="amount" type="number" min="0.01" step="0.01" defaultValue={String(Number(editingCommitment.amount.replace(/[^\d.]/g, '')) || 0)} required /></Field>
            <Field label="Frecuencia de pago" required><select name="frequency" defaultValue={editingCommitment.frequency ?? 'monthly'}><option value="monthly">Mensual</option><option value="weekly">Semanal</option><option value="biweekly">Cada dos semanas</option><option value="quarterly">Trimestral</option><option value="yearly">Anual</option></select></Field>
            <Field label="Fecha de vencimiento final"><Input name="maturity_date" type="date" defaultValue={editingCommitment.maturityDate ?? ''} /></Field>
            <Field label="Próxima fecha de vencimiento" required><Input name="next_due_date" type="date" defaultValue={editingCommitment.dueDate?.slice(0, 10) || today} required /></Field>
          </> : <>
            <Field label="Monto a pagar (DOP)" required><Input name="amount" type="number" min="0.01" step="0.01" defaultValue={String(Number(editingCommitment.amount.replace(/[^\d.]/g, '')) || 0)} required /></Field>
            <Field label="Frecuencia de pago" required><select name="frequency" defaultValue={editingCommitment.frequency ?? 'monthly'}><option value="monthly">Mensual</option><option value="once">Una sola vez</option><option value="weekly">Semanal</option><option value="biweekly">Cada dos semanas</option><option value="quarterly">Trimestral</option><option value="yearly">Anual</option></select></Field>
            <Field label="Próxima fecha de vencimiento" required><Input name="next_due_date" type="date" defaultValue={editingCommitment.dueDate?.slice(0, 10) || today} required /></Field>
          </>}

          <Field label="Notas adicionales"><Input name="notes" defaultValue={editingCommitment.notes ?? ''} maxLength={2000} /></Field>
        </div>
        <div className="commitment-form-actions"><Button type="button" appearance="subtle" onClick={() => setEditingCommitment(null)}>Cancelar</Button><Button type="submit" appearance="primary" disabled={updateCommitment.isPending}>{updateCommitment.isPending ? 'Guardando…' : 'Guardar cambios'}</Button></div>
      </form>
    </Card></div>}
    {accounts.data.length === 0 && <MessageBar intent="warning"><MessageBarBody>Crea una cuenta antes de registrar pagos de compromisos.</MessageBarBody></MessageBar>}
    {query.data.commitments.length === 0 ? <EmptyState label={activeTab === 'archived' ? 'No hay compromisos archivados.' : 'No hay compromisos que coincidan con los filtros.'} /> :
      <section className="record-list">{query.data.commitments.map((item) => {
        const linkedOccurrences = query.data.occurrences.filter((occurrence) => occurrence.commitmentId === item.id);
        const nextOccurrence = linkedOccurrences.find((occurrence) => occurrence.status !== 'Pagado') ?? linkedOccurrences[0];
        const remaining = nextOccurrence?.remainingAmount ?? item.amount;
        const paid = nextOccurrence?.status === 'Pagado' || remaining === 'RD$ 0.00';
        return <Card className="content-card record-card" key={item.id}>
          <div className="record-heading"><div><Subtitle1>{item.name}</Subtitle1><Caption1>{item.dueDate} · {item.isActive === false ? 'Archivado' : 'Activo'}</Caption1></div><strong>{remaining} a vencer</strong></div>
          <Caption1>{commitmentTypes.find((type) => type.value === item.type)?.label ?? 'Compromiso'}{item.category ? ` · ${item.category}` : ''}{item.institution ? ` · ${item.institution}` : ''}</Caption1>
          <Caption1>Importe: {item.amount} · Frecuencia: {item.frequency === 'once' ? 'Una sola vez' : item.frequency ?? 'Mensual'}{(item.type === 'subscription' || item.type === 'insurance') && item.frequency !== 'once' ? ` · Costo anual estimado: ${(() => { const amount = Number(item.amount.replace(/[^\d.]/g, '')); const multiplier = item.frequency === 'weekly' ? 52 : item.frequency === 'biweekly' ? 26 : item.frequency === 'monthly' ? 12 : item.frequency === 'quarterly' ? 4 : item.frequency === 'yearly' ? 1 : 0; return multiplier ? `RD$ ${(amount * multiplier).toLocaleString('es-DO', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}` : 'No calculable'; })()}` : ''}</Caption1>
          {item.type === 'credit_card' && (item.limitStatus === 'unknown' || item.limitStatus === 'pending_verification') && (
            <MessageBar intent="warning" style={{ marginTop: '10px' }}>
              <MessageBarBody>Falta confirmar el límite aprobado y el sobregiro autorizado. Configure la tarjeta antes de registrar nuevos consumos.</MessageBarBody>
            </MessageBar>
          )}
          {item.type === 'credit_card' && item.capacityMetrics && <CardCapacityPanel cardName={item.name} institutionName={item.institution} metrics={item.capacityMetrics} />}
          {item.type === 'credit_card' && (
            <details className="debt-action">
              <summary>Detalle de tarjeta y estados de cuenta</summary>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: '10px', marginTop: '8px' }}>
                <Card className="content-card" style={{ padding: '10px' }}><Caption1>Límite aprobado</Caption1><strong>{item.approvedCreditLimit ?? 'No confirmado'}</strong></Card>
                <Card className="content-card" style={{ padding: '10px' }}><Caption1>Sobregiro autorizado</Caption1><strong>{item.authorizedOverdraftLimit ?? 'No confirmado'}</strong></Card>
                <Card className="content-card" style={{ padding: '10px' }}><Caption1>Fecha de corte</Caption1><strong>{item.statementDay ? `Día ${item.statementDay}` : 'No registrada'}</strong></Card>
                <Card className="content-card" style={{ padding: '10px' }}><Caption1>Próximo vencimiento</Caption1><strong>{item.dueDate}</strong></Card>
              </div>
              {item.specialRulesSource && <Caption1 style={{ display: 'block', marginTop: '8px' }}>Fuente contractual: {item.specialRulesSource}</Caption1>}
              {item.debtId ? <CommitmentStatementDetail debtId={item.debtId} /> : <Caption1>No hay deuda vinculada registrada.</Caption1>}
            </details>
          )}
          {item.type === 'loan' && (
            <details className="debt-action">
              <summary>Detalle de préstamo y amortización</summary>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: '10px', marginTop: '8px' }}>
                <Card className="content-card" style={{ padding: '10px' }}><Caption1>Saldo pendiente</Caption1><strong>{item.currentBalance ?? item.amount}</strong></Card>
                <Card className="content-card" style={{ padding: '10px' }}><Caption1>Tasa anual</Caption1><strong>{item.contractConditions?.annual_interest_rate ? `${item.contractConditions.annual_interest_rate}%` : item.interestRate ? `${item.interestRate}%` : 'No registrada'}</strong></Card>
                <Card className="content-card" style={{ padding: '10px' }}><Caption1>Cuota calculada</Caption1><strong>{item.amortization?.installment ?? item.amount}</strong></Card>
                <Card className="content-card" style={{ padding: '10px' }}><Caption1>Vencimiento final</Caption1><strong>{item.maturityDate ?? 'No registrado'}</strong></Card>
                <Card className="content-card" style={{ padding: '10px' }}><Caption1>Plazo</Caption1><strong>{item.termMonths ?? item.contractConditions?.term_months ?? '—'} meses</strong></Card>
              </div>
              {item.amortization?.schedule?.length ? (
                <div style={{ maxHeight: '240px', overflow: 'auto', marginTop: '10px' }}>
                  {item.amortization.schedule.slice(0, 12).map((row) => <Card key={row.month} className="content-card" style={{ padding: '8px', marginBottom: '6px' }}><div className="record-heading"><div><Caption1>Cuota {row.month}</Caption1><strong>{row.installment}</strong></div><Caption1>Principal {row.principal_portion} · Interés {row.interest_portion} · Restante {row.remaining_balance}</Caption1></div></Card>)}
                </div>
              ) : <Caption1 style={{ display: 'block', marginTop: '8px' }}>No existe una tabla contractual calculable con los datos actuales.</Caption1>}
            </details>
          )}
          <details className="debt-action">
            <summary>Historial de vencimientos y pagos</summary>
            {linkedOccurrences.length === 0 ? <Caption1>No hay vencimientos registrados.</Caption1> : <div className="record-list" style={{ marginTop: '8px' }}>{linkedOccurrences.map((occurrence) => <Card key={occurrence.id} className="content-card" style={{ padding: '10px' }}><div className="record-heading"><div><Caption1>{occurrence.dueDate}</Caption1><strong>{occurrence.status}</strong></div><strong>{occurrence.remainingAmount ?? occurrence.amount}</strong></div></Card>)}</div>}
          </details>
          {!paid && nextOccurrence && accounts.data.length > 0 && <details className="debt-action">
            <summary>Registrar pago de compromiso</summary>
            <form className="debt-action-form" onSubmit={(event) => submitPayment(event, nextOccurrence)}>
              <Field label={item.type === 'loan' || item.type === 'credit_card' ? 'Monto a pagar (DOP; se permiten abonos)' : 'Monto a pagar (DOP)'} required><Input name="amount" type="number" min="0.01" step="0.01" max={item.type === 'loan' || item.type === 'credit_card' ? undefined : Number(remaining.replace(/[^\d.]/g, ''))} defaultValue={Number(remaining.replace(/[^\d.]/g, '')).toFixed(2)} required /></Field>
              <Field label="Pagar desde la cuenta" required><select name="account_id" required defaultValue=""><option value="" disabled>Seleccionar cuenta</option>{accounts.data.filter((account) => item.type === 'loan' || item.type === 'credit_card' ? account.type !== 'credit_card' : true).map((account) => <option key={account.id} value={account.id} disabled={account.type !== 'credit_card' && parseNumericBalance(account.balance) < Number(remaining.replace(/[^\d.]/g, ''))}>{account.name}{account.type === 'credit_card' ? ' (Tarjeta)' : ` (${account.balance})`}</option>)}</select></Field>
              <Field label="Fecha de pago" required><Input name="paid_at" type="date" defaultValue={today} required /></Field>
              <Button type="submit" appearance="primary" disabled={pay.isPending}>{pay.isPending ? 'Procesando pago…' : 'Registrar pago'}</Button>
            </form>
          </details>}
          {cardPaymentPreview && cardPaymentPreview.commitment.id === item.id && previewCardPayment.data?.purchase_amount === cardPaymentPreview.amount && (
            <Card className="content-card" style={{ marginTop: '10px', border: '1px solid var(--primary)' }}>
              <Subtitle1>Confirmación de pago con tarjeta</Subtitle1>
              <div style={{ display: 'grid', gap: '6px', marginTop: '8px' }}>
                <Caption1>Importe: {previewCardPayment.data.purchase_amount}</Caption1>
                <Caption1>Saldo proyectado: {previewCardPayment.data.projected_balance}</Caption1>
                <Caption1>Crédito disponible: {previewCardPayment.data.available_credit}</Caption1>
                <Caption1>Sobregiro disponible: {previewCardPayment.data.available_overdraft}</Caption1>
                <Caption1>Comisión: {previewCardPayment.data.total_fee}</Caption1>
              </div>
              {previewCardPayment.data.uses_overdraft && <MessageBar intent="warning" style={{ marginTop: '8px' }}><MessageBarBody>Esta operación utilizará sobregiro autorizado.</MessageBarBody></MessageBar>}
              {previewCardPayment.data.contractual_conditions?.verified_official_source && <Caption1 style={{ display: 'block', marginTop: '8px' }}>Fuente oficial: {previewCardPayment.data.contractual_conditions.verified_official_source}</Caption1>}
              <div style={{ display: 'flex', gap: '8px', marginTop: '12px' }}>
                <Button appearance="primary" disabled={pay.isPending} onClick={() => pay.mutate({
                  id: cardPaymentPreview.commitment.commitmentId!,
                  occurrenceId: cardPaymentPreview.occurrenceId,
                  input: {
                    amount: cardPaymentPreview.amount,
                    account_id: cardPaymentPreview.accountId,
                    paid_at: cardPaymentPreview.paidAt,
                    idempotency_key: createUuid(),
                  },
                })}>{pay.isPending ? 'Procesando…' : 'Confirmar pago'}</Button>
                <Button appearance="subtle" disabled={pay.isPending} onClick={() => setCardPaymentPreview(null)}>Cancelar</Button>
              </div>
            </Card>
          )}
          {item.isActive !== false ? <div style={{ display: 'flex', gap: '8px', marginTop: '10px', flexWrap: 'wrap' }}>
            <Button appearance="subtle" size="small" onClick={() => { setEditCommitmentType(item.type ?? 'utility'); setEditingCommitment(item); }}>Editar</Button>
            <Button appearance="subtle" size="small" disabled={pauseCommitment.isPending} onClick={() => pauseCommitment.mutate(item.id!)}>Pausar</Button>
            <Button appearance="subtle" size="small" disabled={deleteCommitment.isPending} onClick={() => { if (window.confirm(`¿Estás seguro de archivar el compromiso "${item.name}"?`)) deleteCommitment.mutate(item.id!); }}>Archivar</Button>
          </div> : <div style={{ display: 'flex', gap: '8px', marginTop: '10px' }}>
            <Button appearance="subtle" size="small" disabled={resumeCommitment.isPending} onClick={() => resumeCommitment.mutate(item.id!)}>{resumeCommitment.isPending ? 'Reanudando…' : 'Reanudar'}</Button>
          </div>}
        </Card>;
      })}</section>}
    {query.data.meta.last_page > 1 && <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: '12px' }}><Button disabled={page <= 1} onClick={() => setPage((value) => Math.max(1, value - 1))}>Anterior</Button><Caption1>Página {query.data.meta.current_page} de {query.data.meta.last_page}</Caption1><Button disabled={page >= query.data.meta.last_page} onClick={() => setPage((value) => value + 1)}>Siguiente</Button></div>}
    <DebtBalancesSection />
  </>;
}

function CalendarPage() {
  const navigate = useNavigate();
  const initialMonth = today.slice(0, 7);
  const [month, setMonth] = useState(initialMonth);
  const [year, monthNumber] = month.split('-').map(Number);
  const firstDay = new Date(Date.UTC(year, monthNumber - 1, 1));
  const lastDay = new Date(Date.UTC(year, monthNumber, 0));
  const from = firstDay.toISOString().slice(0, 10);
  const to = lastDay.toISOString().slice(0, 10);
  const query = useQuery({ queryKey: ['calendar', from, to], queryFn: () => api.listCalendar(from, to), refetchOnMount: 'always' });
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
      <section className="record-list">{query.data.map((item: CalendarEvent) => <Card key={`${item.type}-${item.event}-${item.date}`} className="content-card record-card">
        <div className="record-heading"><div><Subtitle1>{item.event}</Subtitle1><Caption1>{item.date} · {item.type}</Caption1></div><strong>{item.amount}</strong></div>
        {item.commitmentId && <Button appearance="subtle" size="small" onClick={() => navigate('/commitments')}>Abrir compromiso</Button>}
      </Card>)}</section>}
  </>;
}

function TransactionsPage() {
  const [filters, setFilters] = useState({ from: '', to: '', account_id: '', q: '' });
  const [draftFilters, setDraftFilters] = useState(filters);
  const [error, setError] = useState('');
  const queryClient = useQueryClient();
  const accounts = useQuery({ queryKey: ['accounts'], queryFn: api.listAccounts, refetchOnMount: 'always' });
  const query = useQuery({ queryKey: ['transactions', filters], queryFn: () => api.listTransactions(filters), refetchOnMount: 'always' });
  const reverse = useMutation({
    mutationFn: ({ paymentId, reason }: { paymentId: string; reason: string }) => api.reversePayment(paymentId, { reason, idempotency_key: createUuid() }),
    onSuccess: async () => {
      await queryClient.invalidateQueries();
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
  const query = useQuery({ queryKey: ['accounts'], queryFn: api.listAccounts, refetchOnMount: 'always' });

  const create = useMutation({
    mutationFn: api.createFinancialAccount,
    onSuccess: async () => {
      await queryClient.invalidateQueries();
    },
    onError: (cause) => setError(cause.message),
  });

  const updateAccount = useMutation({
    mutationFn: ({ accountId, input }: { accountId: string; input: { name: string; institution?: string | null; type: string } }) => api.updateFinancialAccount(accountId, input),
    onSuccess: async () => {
      setEditingAccount(null);
      await queryClient.invalidateQueries();
    },
    onError: (cause) => setError(cause.message),
  });

  const deleteAccount = useMutation({
    mutationFn: (accountId: string) => api.deleteFinancialAccount(accountId),
    onSuccess: async () => {
      await queryClient.invalidateQueries();
    },
    onError: (cause) => setError(cause.message),
  });

  const reconcile = useMutation({
    mutationFn: ({ accountId, input }: { accountId: string; input: { target_balance: string; reason: string } }) => api.reconcileAccount(accountId, input),
    onSuccess: async () => {
      await queryClient.invalidateQueries();
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
