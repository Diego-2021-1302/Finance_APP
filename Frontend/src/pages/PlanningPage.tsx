import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Card, Caption1, Field, Input, MessageBar, MessageBarBody, Subtitle1 } from '@fluentui/react-components';
import { useState, type FormEvent } from 'react';
import { api } from '../api';
import { EmptyState, ErrorState, LoadingState } from '../components/DataState';

export function PlanningPage() {
  const queryClient = useQueryClient();
  const [error, setError] = useState('');
  const [yearMonth, setYearMonth] = useState(new Date().toISOString().slice(0, 7));
  const [csvAccountId, setCsvAccountId] = useState('');
  const [csvContent, setCsvContent] = useState('');

  const budgets = useQuery({ queryKey: ['budgets', yearMonth], queryFn: () => api.listBudgets(yearMonth) });
  const reserves = useQuery({ queryKey: ['reserves'], queryFn: api.listReserves });
  const commitments = useQuery({ queryKey: ['commitments'], queryFn: api.listCommitments });
  const accounts = useQuery({ queryKey: ['accounts'], queryFn: api.listAccounts });
  const imports = useQuery({ queryKey: ['imports'], queryFn: api.listImports });

  const invalidatePlanning = async () => {
    await queryClient.invalidateQueries({ queryKey: ['budgets', 'reserves', 'imports', 'accounts', 'dashboard-summary'] });
  };

  const createBudget = useMutation({
    mutationFn: api.createBudget,
    onSuccess: invalidatePlanning,
    onError: (cause) => setError(cause.message),
  });
  const createReserve = useMutation({
    mutationFn: api.createReserve,
    onSuccess: invalidatePlanning,
    onError: (cause) => setError(cause.message),
  });
  const updateReserve = useMutation({
    mutationFn: ({ id, amount }: { id: string; amount: string }) =>
      api.updateReserveAmount(id, { current_amount: amount }),
    onSuccess: invalidatePlanning,
    onError: (cause) => setError(cause.message),
  });
  const deleteReserve = useMutation({
    mutationFn: api.deleteReserve,
    onSuccess: invalidatePlanning,
    onError: (cause) => setError(cause.message),
  });
  const importStatement = useMutation({
    mutationFn: api.importStatement,
    onSuccess: invalidatePlanning,
    onError: (cause) => setError(cause.message),
  });
  const updateImport = useMutation({
    mutationFn: ({ id, status }: { id: string; status: 'pending' | 'confirmed' | 'discarded' | 'duplicate' }) =>
      api.updateImportStatus(id, status),
    onSuccess: invalidatePlanning,
    onError: (cause) => setError(cause.message),
  });

  function submitBudget(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError('');
    const form = event.currentTarget;
    const data = new FormData(form);
    createBudget.mutate({
      category: String(data.get('category')),
      monthly_limit: String(data.get('monthly_limit')),
      year_month: yearMonth,
    }, { onSuccess: () => form.reset() });
  }

  if (budgets.isPending || reserves.isPending || commitments.isPending || accounts.isPending || imports.isPending) {
    return <LoadingState />;
  }
  if (budgets.isError || reserves.isError || commitments.isError || accounts.isError || imports.isError) {
    return <ErrorState onRetry={() => void Promise.all([budgets.refetch(), reserves.refetch(), commitments.refetch(), accounts.refetch(), imports.refetch()])} />;
  }

  return (
    <>
      <div className="page-intro">
        <p>Administra presupuestos, reservas e importaciones bancarias sin crear registros contables duplicados.</p>
      </div>
      {error && <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar>}

      <Card className="content-card">
        <Subtitle1>Presupuestos mensuales</Subtitle1>
        <Caption1>Mes activo</Caption1>
        <Input type="month" value={yearMonth} onChange={(event) => setYearMonth(event.target.value)} />
        <form className="finance-form" onSubmit={submitBudget} style={{ marginTop: '12px' }}>
          <div className="form-grid">
            <Field label="Categoría" required><Input name="category" required maxLength={100} /></Field>
            <Field label="Límite mensual (DOP)" required><Input name="monthly_limit" type="number" min="0.01" step="0.01" required /></Field>
          </div>
          <Button type="submit" appearance="primary" disabled={createBudget.isPending}>
            {createBudget.isPending ? 'Guardando…' : 'Guardar presupuesto'}
          </Button>
        </form>
        {budgets.data.length === 0 ? <EmptyState label="No hay presupuestos para este mes." /> : (
          <div style={{ display: 'grid', gap: '8px', marginTop: '12px' }}>
            {budgets.data.map((budget) => (
              <div key={budget.id} className="record-heading">
                <div><Subtitle1>{budget.category}</Subtitle1><Caption1>{budget.year_month}</Caption1></div>
                <strong>RD$ {budget.monthly_limit}</strong>
              </div>
            ))}
          </div>
        )}
      </Card>

      <Card className="content-card">
        <Subtitle1>Reservas</Subtitle1>
        <form className="finance-form" onSubmit={(event) => {
          event.preventDefault();
          setError('');
          const data = new FormData(event.currentTarget);
          createReserve.mutate({
            name: String(data.get('name')),
            target_amount: String(data.get('target_amount')),
            current_amount: String(data.get('current_amount') || '0'),
            commitment_id: String(data.get('commitment_id') || '') || null,
          }, { onSuccess: () => event.currentTarget.reset() });
        }} style={{ marginTop: '12px' }}>
          <div className="form-grid">
            <Field label="Nombre" required><Input name="name" required maxLength={160} /></Field>
            <Field label="Monto objetivo" required><Input name="target_amount" type="number" min="0.01" step="0.01" required /></Field>
            <Field label="Monto reservado inicial"><Input name="current_amount" type="number" min="0" step="0.01" defaultValue="0" /></Field>
            <Field label="Vincular compromiso">
              <select name="commitment_id" defaultValue="">
                <option value="">Sin vínculo</option>
                {commitments.data.commitments.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
              </select>
            </Field>
          </div>
          <Button type="submit" appearance="primary" disabled={createReserve.isPending}>
            {createReserve.isPending ? 'Guardando…' : 'Crear reserva'}
          </Button>
        </form>
        {reserves.data.length === 0 ? <EmptyState label="No hay reservas registradas." /> : (
          <div style={{ display: 'grid', gap: '10px', marginTop: '12px' }}>
            {reserves.data.map((reserve) => (
              <div key={reserve.id} className="record-heading">
                <div><Subtitle1>{reserve.name}</Subtitle1><Caption1>Objetivo RD$ {reserve.target_amount}</Caption1></div>
                <strong>Reservado RD$ {reserve.current_amount}</strong>
                <div style={{ display: 'flex', gap: '6px' }}>
                  <Button size="small" onClick={() => {
                    const amount = window.prompt('Nuevo importe reservado', reserve.current_amount);
                    if (amount !== null) updateReserve.mutate({ id: String(reserve.id), amount });
                  }}>Actualizar importe</Button>
                  <Button size="small" appearance="subtle" onClick={() => deleteReserve.mutate(String(reserve.id))}>Eliminar reserva</Button>
                </div>
              </div>
            ))}
          </div>
        )}
      </Card>

      <Card className="content-card">
        <Subtitle1>Importación bancaria CSV</Subtitle1>
        <Caption1>Formato: date,description,amount (una fila por movimiento).</Caption1>
        <div className="form-grid" style={{ marginTop: '10px' }}>
          <Field label="Cuenta" required>
            <select value={csvAccountId} onChange={(event) => setCsvAccountId(event.target.value)}>
              <option value="" disabled>Seleccionar cuenta</option>
              {accounts.data.map((account) => <option key={account.id} value={account.id}>{account.name}</option>)}
            </select>
          </Field>
          <Field label="Contenido CSV" required>
            <textarea value={csvContent} onChange={(event) => setCsvContent(event.target.value)} rows={8} />
          </Field>
        </div>
        <Button
          appearance="primary"
          disabled={!csvAccountId || !csvContent.trim() || importStatement.isPending}
          onClick={() => importStatement.mutate({ account_id: csvAccountId, csv_content: csvContent })}
        >
          {importStatement.isPending ? 'Importando…' : 'Importar y detectar duplicados'}
        </Button>

        {imports.data.length === 0 ? <EmptyState label="No hay movimientos importados." /> : (
          <div style={{ display: 'grid', gap: '8px', marginTop: '12px' }}>
            {imports.data.map((item) => (
              <div key={item.id} className="record-heading">
                <div><Subtitle1>{item.description}</Subtitle1><Caption1>{item.occurred_at} · {item.status}</Caption1></div>
                <strong>RD$ {item.amount}</strong>
                {item.status !== 'confirmed' && (
                  <div style={{ display: 'flex', gap: '6px' }}>
                    <Button size="small" disabled={item.status === 'duplicate'} onClick={() => updateImport.mutate({ id: String(item.id), status: 'confirmed' })}>Confirmar</Button>
                    <Button size="small" appearance="subtle" onClick={() => updateImport.mutate({ id: String(item.id), status: 'discarded' })}>Descartar</Button>
                  </div>
                )}
              </div>
            ))}
          </div>
        )}
      </Card>
    </>
  );
}
