import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Card, Caption1, Field, Input, MessageBar, MessageBarBody, Subtitle1 } from '@fluentui/react-components';
import { useState, type FormEvent } from 'react';
import { api, createUuid } from '../api';
import { EmptyState, ErrorState, LoadingState } from '../components/DataState';
import type { DebtRecord } from '../types';

const now = new Date();
const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;

export function DebtBalancesSection() {
  const [error, setError] = useState('');
  const queryClient = useQueryClient();
  const debts = useQuery({ queryKey: ['debts'], queryFn: api.listDebts });
  const accounts = useQuery({ queryKey: ['accounts'], queryFn: api.listAccounts });

  const invalidateDebtData = async () => {
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: ['debts'] }),
      queryClient.invalidateQueries({ queryKey: ['debt-history'] }),
      queryClient.invalidateQueries({ queryKey: ['accounts'] }),
      queryClient.invalidateQueries({ queryKey: ['transactions'] }),
      queryClient.invalidateQueries({ queryKey: ['dashboard'] }),
    ]);
  };

  const increaseDebt = useMutation({
    mutationFn: ({ id, input }: { id: string; input: { amount: string; description: string; occurred_at: string; idempotency_key: string } }) => api.increaseDebt(id, input),
    onSuccess: invalidateDebtData,
    onError: (cause) => setError(cause.message),
  });

  const payDebt = useMutation({
    mutationFn: ({ id, input }: { id: string; input: { amount: string; account_id: string | null; paid_at: string; idempotency_key: string } }) => api.recordDebtPayment(id, input),
    onSuccess: invalidateDebtData,
    onError: (cause) => setError(cause.message),
  });

  const updateMinimum = useMutation({
    mutationFn: ({ id, input }: { id: string; input: { minimum_payment: string } }) => api.updateDebtMinimumPayment(id, input),
    onSuccess: invalidateDebtData,
    onError: (cause) => setError(cause.message),
  });

  function submitIncrease(event: FormEvent<HTMLFormElement>, debt: DebtRecord) {
    event.preventDefault();
    setError('');
    const form = event.currentTarget;
    const data = new FormData(form);
    increaseDebt.mutate({
      id: debt.id!,
      input: {
        amount: String(data.get('amount')),
        description: String(data.get('description')),
        occurred_at: String(data.get('occurred_at')),
        idempotency_key: createUuid(),
      },
    }, { onSuccess: () => form.reset() });
  }

  function submitPayment(event: FormEvent<HTMLFormElement>, debt: DebtRecord) {
    event.preventDefault();
    setError('');
    const form = event.currentTarget;
    const data = new FormData(form);
    const accountId = String(data.get('account_id'));

    if (!accountId) {
      setError('Debes seleccionar una cuenta activa desde la cual se debitará el dinero.');
      return;
    }

    payDebt.mutate({
      id: debt.id!,
      input: {
        amount: String(data.get('amount')),
        account_id: accountId,
        paid_at: String(data.get('paid_at')),
        idempotency_key: createUuid(),
      },
    }, { onSuccess: () => form.reset() });
  }

  function submitMinimum(event: FormEvent<HTMLFormElement>, debt: DebtRecord) {
    event.preventDefault();
    setError('');
    const form = event.currentTarget;
    const data = new FormData(form);
    updateMinimum.mutate({
      id: debt.id!,
      input: { minimum_payment: String(data.get('minimum_payment')) },
    });
  }

  if (debts.isPending || accounts.isPending) return <LoadingState />;
  if (debts.isError) return <ErrorState onRetry={() => void debts.refetch()} />;
  if (accounts.isError) return <ErrorState onRetry={() => void accounts.refetch()} />;

  const liquidAccounts = accounts.data.filter((a) => a.type !== 'credit_card');

  return <section className="content-card" aria-label="Registros de saldos de tarjetas y préstamos">
    <div className="section-title">
      <div>
        <Subtitle1>Saldos de Tarjetas de Crédito y Préstamos</Subtitle1>
        <Caption1>Abonos a balances de tarjetas (sin cuota fija) y préstamos hasta saldar el monto total.</Caption1>
      </div>
    </div>
    {error && <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar>}
    {debts.data.length === 0 ? <EmptyState label="No hay registros de tarjetas ni préstamos guardados aún." /> :
      <section className="debt-list" aria-label="Tus tarjetas y préstamos">
        {debts.data.map((debt) => <Card className="content-card debt-card" key={debt.id}>
          <div className="debt-heading">
            <div>
              <Subtitle1>{debt.account}</Subtitle1>
              <Caption1>{debt.kind === 'credit_card' ? '💳 Tarjeta de Crédito' : debt.kind === 'loan' ? '🏦 Préstamo' : 'Otra Deuda'}{debt.dueDate !== '—' ? ` · Vence ${debt.dueDate}` : ''}</Caption1>
            </div>
            <div>
              <strong>{debt.outstanding}</strong>
              <Caption1>Pendiente · mínimo {debt.minimum ?? 'no confirmado'}</Caption1>
            </div>
          </div>
          <details className="debt-action">
            <summary>Registrar pago mínimo del estado bancario</summary>
            <form className="debt-action-form" onSubmit={(event) => submitMinimum(event, debt)}>
              <Field label="Pago mínimo informado por el acreedor (DOP)" required>
                <Input
                  name="minimum_payment"
                  type="number"
                  min="0"
                  step="0.01"
                  defaultValue={debt.minimum?.replace(/[^\d.]/g, '') ?? ''}
                  required
                />
              </Field>
              <Button type="submit" appearance="primary" disabled={updateMinimum.isPending}>Guardar mínimo informado</Button>
            </form>
          </details>
          <details className="debt-action">
            <summary>Abonar a la tarjeta / saldo</summary>
            <form className="debt-action-form" onSubmit={(event) => submitPayment(event, debt)}>
              <Field label="Monto a abonar (DOP)" required>
                <Input name="amount" type="number" min="0.01" step="0.01" max={Number(debt.outstanding.replace(/[^\d.]/g, ''))} required placeholder="ej. 1500.00" />
              </Field>
              <Field label="Pagar desde la cuenta" required>
                <select name="account_id" required defaultValue="">
                  <option value="" disabled>Seleccionar cuenta (Ahorro/Corriente/Efectivo)</option>
                  {liquidAccounts.map((account) => <option key={account.id} value={account.id}>{account.name} ({account.balance})</option>)}
                </select>
              </Field>
              <Field label="Fecha del abono" required>
                <Input name="paid_at" type="date" defaultValue={today} required />
              </Field>
              <Button type="submit" appearance="primary" disabled={payDebt.isPending}>Realizar abono</Button>
            </form>
          </details>
          <details className="debt-action">
            <summary>Registrar nuevo consumo con tarjeta / incremento</summary>
            <form className="debt-action-form" onSubmit={(event) => submitIncrease(event, debt)}>
              <Field label="Monto consumido (DOP)" required>
                <Input name="amount" type="number" min="0.01" step="0.01" required placeholder="ej. 2000.00" />
              </Field>
              <Field label="Descripción del consumo">
                <Input name="description" maxLength={255} placeholder="ej. Compra en supermercado" />
              </Field>
              <Field label="Fecha" required>
                <Input name="occurred_at" type="date" defaultValue={today} required />
              </Field>
              <Button type="submit" appearance="primary" disabled={increaseDebt.isPending}>Registrar consumo</Button>
            </form>
          </details>
        </Card>)}
      </section>}
  </section>;
}
