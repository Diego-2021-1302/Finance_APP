import type { ApiClient } from './types';
import { createUuid } from './uuid';
import type {
  AccountRecord, CalendarEvent, CashFlowReport, CommitmentRecord, DashboardSummary, DebtBalancePoint, DebtRecord, ExpenseRecord, FinanceInsights,
  IncomeRecord, Preferences, TransactionRecord, TransferRecord, User,
} from '../types';

type Envelope<T> = { data: T };
type BackendUser = { id: number | string; name: string; email: string };
type AuthResponse = { data: { user: BackendUser; token: string } };
type IncomeResponse = {
  id: number;
  source: string;
  amount: string;
  expected_date: string;
  status: string;
  received_amount: string;
};
type CommitmentResponse = {
  id: number;
  commitment_id?: number;
  name: string | null;
  type?: CommitmentRecord['type'];
  amount: string;
  remaining_amount?: string;
  frequency?: string;
  due_date: string | null;
  status?: string;
  reference_number?: string | null;
  institution?: string | null;
  current_balance?: string | null;
  statement_balance?: string | null;
  statement_day?: number | null;
  payment_grace_days?: number | null;
  interest_rate?: string | null;
  category?: string | null;
  maturity_date?: string | null;
  notes?: string | null;
};
type RawSettings = {
  currency: 'DOP' | 'USD';
  timezone: string;
  reminders: number;
  week_starts: 'Monday' | 'Sunday';
  appearance: 'light' | 'dark' | 'system';
};

const apiBase = (import.meta.env.VITE_API_BASE_URL || '/api/v1').replace(/\/+$/, '');
const accessTokenStorageKey = 'finance-app.access-token';
const deviceIdStorageKey = 'finance-app.device-id';
let accessToken: string | null = globalThis.localStorage.getItem(accessTokenStorageKey);
let currentUserRequest: Promise<User> | null = null;

export class ApiRequestError extends Error {
  constructor(message: string, readonly status: number) {
    super(message);
    this.name = 'ApiRequestError';
  }
}

function getDeviceId(): string {
  const existingId = globalThis.localStorage.getItem(deviceIdStorageKey);
  if (existingId) return existingId;

  const deviceId = createUuid();
  globalThis.localStorage.setItem(deviceIdStorageKey, deviceId);
  return deviceId;
}

function toMoney(value: string | number | null | undefined): string {
  const amount = Number(value ?? 0);
  const sign = amount < 0 ? '−' : '';
  return `${sign}RD$ ${Math.abs(amount).toLocaleString('es-DO', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`;
}

function displayDate(value: string | null | undefined, includeYear = true): string {
  if (!value) return '—';
  const dateOnly = value.slice(0, 10);
  const [year, month, day] = dateOnly.split('-').map(Number);
  if (!year || !month || !day) return value;
  return new Date(year, month - 1, day).toLocaleDateString('es-DO', {
    month: 'short',
    day: 'numeric',
    ...(includeYear ? { year: 'numeric' as const } : {}),
  });
}

function displayStatus(value: string | undefined): string {
  if (!value) return 'Programado';
  const labels: Record<string, string> = {
    expected: 'Esperado',
    partially_received: 'Recibido parcialmente',
    received: 'Recibido',
    scheduled: 'Programado',
    paid: 'Pagado',
    due_soon: 'Vence pronto',
  };
  return labels[value] ?? value;
}

function toUser(user: BackendUser): User {
  return { id: String(user.id), name: user.name, email: user.email };
}

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const headers = new Headers(init.headers);
  headers.set('Accept', 'application/json');
  if (init.body !== undefined) headers.set('Content-Type', 'application/json');
  if (accessToken) headers.set('Authorization', `Bearer ${accessToken}`);
  const response = await fetch(`${apiBase}${path}`, { ...init, headers });
  const payload: unknown = await response.json().catch(() => null);
  if (!response.ok) {
    const message = typeof payload === 'object' && payload !== null
      && 'error' in payload && typeof payload.error === 'object' && payload.error !== null
      && 'message' in payload.error && typeof payload.error.message === 'string'
      ? payload.error.message
      : `La solicitud falló (${response.status})`;
    throw new ApiRequestError(message, response.status);
  }
  return payload as T;
}

function jsonBody(value: unknown): string {
  return JSON.stringify(value);
}

function toPreferences(settings: RawSettings): Preferences {
  return {
    currency: settings.currency === 'USD' ? 'USD ($)' : 'DOP (RD$)',
    timezone: settings.timezone,
    reminders: settings.reminders,
    weekStarts: settings.week_starts,
    appearance: settings.appearance,
  };
}

function toRawPreferences(input: Partial<Preferences>): Partial<RawSettings> {
  const result: Partial<RawSettings> = {};
  if (input.currency !== undefined) result.currency = input.currency.startsWith('USD') ? 'USD' : 'DOP';
  if (input.timezone !== undefined) result.timezone = input.timezone;
  if (input.reminders !== undefined) result.reminders = input.reminders;
  if (input.weekStarts !== undefined) result.week_starts = input.weekStarts as RawSettings['week_starts'];
  if (input.appearance !== undefined) result.appearance = input.appearance;
  return result;
}

export const liveClient: ApiClient = {
  getCurrentUser() {
    if (!accessToken) return Promise.reject(new ApiRequestError('No has iniciado sesión.', 401));
    if (currentUserRequest) return currentUserRequest;

    currentUserRequest = request<Envelope<BackendUser>>('/auth/me')
      .then((result) => toUser(result.data))
      .catch((error: unknown) => {
        if (error instanceof ApiRequestError && error.status === 401) {
          accessToken = null;
          globalThis.localStorage.removeItem(accessTokenStorageKey);
        }
        throw error;
      })
      .finally(() => {
        currentUserRequest = null;
      });
    return currentUserRequest;
  },

  async login(input) {
    const result = await request<AuthResponse>('/auth/login', {
      method: 'POST',
      body: jsonBody({ ...input, device_id: getDeviceId() }),
    });
    accessToken = result.data.token;
    globalThis.localStorage.setItem(accessTokenStorageKey, accessToken);
    return toUser(result.data.user);
  },

  async logout() {
    if (!accessToken) return;
    try {
      await request<Envelope<{ signed_out: boolean }>>('/auth/logout', { method: 'POST' });
    } finally {
      accessToken = null;
      globalThis.localStorage.removeItem(accessTokenStorageKey);
    }
  },

  async createAccount(input) {
    const result = await request<Envelope<{ user: BackendUser }>>('/auth/register', {
      method: 'POST',
      body: jsonBody({ ...input, password_confirmation: input.password }),
    });
    return toUser(result.data.user);
  },

  getDashboardSummary() {
    return request<DashboardSummary>('/dashboard/summary');
  },

  async getDebtBalanceHistory() {
    const result = await request<{ data: Array<{ month: string; balance: string }> }>('/debts/history');
    return result.data.map((item): DebtBalancePoint => ({
      month: item.month,
      balance: Number(item.balance),
    }));
  },

  getFinanceInsights() {
    return request<Envelope<FinanceInsights>>('/insights/recommendations', {
      method: 'POST',
      body: jsonBody({ consent: true }),
    }).then((result) => result.data);
  },

  async listIncomes() {
    const result = await request<{ data: IncomeResponse[]; meta: { total: number } }>('/incomes');
    return result.data.map((item): IncomeRecord => ({
      id: String(item.id),
      source: item.source,
      amount: toMoney(item.amount),
      expectedDate: displayDate(item.expected_date),
      status: displayStatus(item.status),
      receivedAmount: toMoney(item.received_amount),
    }));
  },

  async createIncome(input) {
    const result = await request<Envelope<IncomeResponse>>('/incomes', {
      method: 'POST',
      body: jsonBody(input),
    });
    return {
      id: String(result.data.id),
      source: result.data.source,
      amount: toMoney(result.data.amount),
      expectedDate: displayDate(result.data.expected_date),
      status: displayStatus(result.data.status),
    };
  },

  async recordReceipt(incomeId, input) {
    const result = await request<{
      data: { id: number; amount: string };
      balance: { confirmed: string };
    }>(`/incomes/${encodeURIComponent(incomeId)}/receipts`, {
      method: 'POST',
      body: jsonBody(input),
    });
    return { amount: toMoney(result.data.amount), balance: result.balance.confirmed };
  },

  async listCommitments() {
    const result = await request<{
      commitments: CommitmentResponse[];
      occurrences: CommitmentResponse[];
    }>('/commitments');
    const mapRecord = (item: CommitmentResponse): CommitmentRecord => ({
      id: String(item.id),
      name: item.name ?? 'Commitment',
      type: item.type ?? 'other',
      referenceNumber: item.reference_number,
      institution: item.institution,
      currentBalance: item.current_balance == null ? null : toMoney(item.current_balance),
      statementBalance: item.statement_balance == null ? null : toMoney(item.statement_balance),
      statementDay: item.statement_day ?? null,
      paymentGraceDays: item.payment_grace_days ?? null,
      interestRate: item.interest_rate,
      category: item.category,
      maturityDate: item.maturity_date ? displayDate(item.maturity_date) : null,
      notes: item.notes,
      amount: toMoney(item.amount),
      dueDate: displayDate(item.due_date),
      status: displayStatus(item.status),
    });
    return {
      commitments: result.commitments.map(mapRecord),
      occurrences: result.occurrences.map((item) => ({
        ...mapRecord(item),
        commitmentId: String(item.commitment_id),
        remainingAmount: toMoney(item.remaining_amount ?? item.amount),
      })),
    };
  },

  async createCommitment(input) {
    const result = await request<Envelope<CommitmentResponse & { frequency: string; next_due_date: string }>>('/commitments', {
      method: 'POST',
      body: jsonBody(input),
    });
    return {
      id: String(result.data.id),
      name: result.data.name ?? input.name,
      type: result.data.type ?? input.type,
      referenceNumber: result.data.reference_number,
      institution: result.data.institution,
      currentBalance: result.data.current_balance == null ? null : toMoney(result.data.current_balance),
      interestRate: result.data.interest_rate,
      category: result.data.category,
      maturityDate: result.data.maturity_date ? displayDate(result.data.maturity_date) : null,
      notes: result.data.notes,
      amount: toMoney(result.data.amount),
      frequency: result.data.frequency,
      dueDate: displayDate(result.data.next_due_date),
      status: 'Programado',
    };
  },

  async updateCommitment(commitmentId, input) {
    const result = await request<Envelope<CommitmentResponse & { frequency: string; next_due_date: string }>>(`/commitments/${encodeURIComponent(commitmentId)}`, {
      method: 'PUT',
      body: jsonBody(input),
    });
    return {
      id: String(result.data.id),
      name: result.data.name ?? input.name,
      type: result.data.type ?? input.type,
      referenceNumber: result.data.reference_number,
      institution: result.data.institution,
      currentBalance: result.data.current_balance == null ? null : toMoney(result.data.current_balance),
      interestRate: result.data.interest_rate,
      category: result.data.category,
      maturityDate: result.data.maturity_date ? displayDate(result.data.maturity_date) : null,
      notes: result.data.notes,
      amount: toMoney(result.data.amount),
      frequency: result.data.frequency,
      dueDate: displayDate(result.data.next_due_date),
      status: 'Programado',
    };
  },

  async deleteCommitment(commitmentId) {
    const result = await request<{ data: { id: number; deleted: boolean } }>(`/commitments/${encodeURIComponent(commitmentId)}`, {
      method: 'DELETE',
    });
    return { id: String(result.data.id), deleted: result.data.deleted };
  },

  async recordCommitmentPayment(commitmentId, occurrenceId, input) {
    const result = await request<{
      data: { id: number; amount: string };
      remaining_due: string;
    }>(`/commitments/${encodeURIComponent(commitmentId)}/occurrences/${encodeURIComponent(occurrenceId)}/payments`, {
      method: 'POST',
      body: jsonBody(input),
    });
    return { amount: toMoney(result.data.amount), remaining_due: toMoney(result.remaining_due) };
  },

  async reversePayment(paymentId, input) {
    const result = await request<{
      data: { id: number };
      reversal: { reason: string; reversed_at: string };
    }>(`/payments/${encodeURIComponent(paymentId)}/reversals`, {
      method: 'POST',
      body: jsonBody(input),
    });
    return { reversed: true, reason: result.reversal.reason };
  },

  async listAccounts() {
    const result = await request<{ data: Array<{ id: number; name: string; institution: string | null; type: string; currency: string; balance: string }> }>('/accounts');
    return result.data.map((item): AccountRecord => ({ ...item, id: String(item.id), balance: toMoney(item.balance) }));
  },

  async createFinancialAccount(input) {
    const result = await request<Envelope<{ id: number; name: string; institution: string | null; type: string; currency: string; balance: string }>>('/accounts', {
      method: 'POST',
      body: jsonBody(input),
    });
    return { ...result.data, id: String(result.data.id), balance: toMoney(result.data.balance) };
  },

  async updateFinancialAccount(accountId, input) {
    const result = await request<Envelope<{ id: number; name: string; institution: string | null; type: string; currency: string; balance: string }>>(`/accounts/${encodeURIComponent(accountId)}`, {
      method: 'PUT',
      body: jsonBody(input),
    });
    return { ...result.data, id: String(result.data.id), balance: toMoney(result.data.balance) };
  },

  async deleteFinancialAccount(accountId) {
    const result = await request<{ data: { id: number; deleted: boolean } }>(`/accounts/${encodeURIComponent(accountId)}`, {
      method: 'DELETE',
    });
    return { id: String(result.data.id), deleted: result.data.deleted };
  },

  async archiveAccount(accountId) {
    const result = await request<{ data: { id: number; archived: boolean } }>(`/accounts/${encodeURIComponent(accountId)}/archive`, {
      method: 'POST',
    });
    return { id: String(result.data.id), archived: result.data.archived };
  },

  async reconcileAccount(accountId, input) {
    const result = await request<Envelope<{ id: number; name: string; institution: string | null; type: string; currency: string; balance: string }>>(
      `/accounts/${encodeURIComponent(accountId)}/reconcile`,
      { method: 'POST', body: jsonBody(input) },
    );
    return { ...result.data, id: String(result.data.id), balance: toMoney(result.data.balance) };
  },

  async listExpenses(filters = {}) {
    const params = new URLSearchParams(Object.entries(filters).filter((entry): entry is [string, string] => entry[1] !== undefined && entry[1] !== ''));
    const result = await request<{ data: Array<{
      id: number; account_id: number; account: string; category: string; amount: string; occurred_at: string; description: string; reference_number: string | null;
    }> }>(`/expenses${params.size ? `?${params.toString()}` : ''}`);
    return result.data.map((item): ExpenseRecord => ({
      id: String(item.id),
      account_id: String(item.account_id),
      account: item.account,
      category: item.category,
      amount: toMoney(item.amount),
      occurred_at: displayDate(item.occurred_at),
      description: item.description,
      reference_number: item.reference_number,
    }));
  },

  async createExpense(input) {
    const result = await request<{
      data: { id: number; account_id: number; category: string; amount: string; occurred_at: string; description: string };
      balance: { confirmed: string };
    }>('/expenses', {
      method: 'POST',
      body: jsonBody(input),
    });
    return {
      id: String(result.data.id),
      account_id: String(result.data.account_id),
      category: result.data.category,
      amount: toMoney(result.data.amount),
      occurred_at: displayDate(result.data.occurred_at),
      description: result.data.description,
    };
  },

  async listTransfers() {
    const result = await request<{ data: Array<{
      id: number; source_account: string; destination_account: string; amount: string; transferred_at: string; notes: string | null;
    }> }>('/transfers');
    return result.data.map((item): TransferRecord => ({
      id: String(item.id),
      source_account: item.source_account,
      destination_account: item.destination_account,
      amount: toMoney(item.amount),
      transferred_at: displayDate(item.transferred_at),
      notes: item.notes,
    }));
  },

  async createTransfer(input) {
    const result = await request<{ data: {
      id: number; source_account: string; destination_account: string; amount: string; transferred_at: string; notes: string | null;
    } }>('/transfers', {
      method: 'POST',
      body: jsonBody(input),
    });
    return {
      id: String(result.data.id),
      source_account: result.data.source_account,
      destination_account: result.data.destination_account,
      amount: toMoney(result.data.amount),
      transferred_at: displayDate(result.data.transferred_at),
      notes: result.data.notes,
    };
  },

  async listTransactions(filters = {}) {
    const params = new URLSearchParams(Object.entries(filters).filter((entry): entry is [string, string] => entry[1] !== undefined && entry[1] !== ''));
    const result = await request<{ data: Array<{
      id: number; movement: string; account: string | null; amount: string; recorded: string; payment_id: number | null; reversed_at: string | null;
    }> }>(`/transactions${params.size ? `?${params.toString()}` : ''}`);
    return result.data.map((item): TransactionRecord => ({
      id: String(item.id),
      paymentId: item.payment_id === null ? null : String(item.payment_id),
      reversedAt: item.reversed_at,
      movement: item.movement,
      account: item.account ?? '—',
      amount: toMoney(item.amount),
      recorded: new Date(item.recorded).toLocaleDateString('es-DO', {
        month: 'short', day: 'numeric', year: 'numeric',
      }),
    }));
  },

  async listDebts() {
    const result = await request<{ data: Array<{
      id: number; account: string; kind: string; outstanding: string; minimum: string; due_date: string | null;
    }> }>('/debts');
    return result.data.map((item): DebtRecord => ({
      id: String(item.id),
      account: item.account,
      kind: item.kind,
      outstanding: toMoney(item.outstanding),
      minimum: toMoney(item.minimum),
      dueDate: displayDate(item.due_date),
    }));
  },

  async createDebt(input) {
    const result = await request<Envelope<{
      id: number; account: string; kind: string; outstanding: string; minimum: string; due_date: string | null;
    }>>('/debts', { method: 'POST', body: jsonBody(input) });
    return {
      id: String(result.data.id),
      account: result.data.account,
      kind: result.data.kind,
      outstanding: toMoney(result.data.outstanding),
      minimum: toMoney(result.data.minimum),
      dueDate: displayDate(result.data.due_date),
    };
  },

  async increaseDebt(debtId, input) {
    const result = await request<Envelope<{
      id: number; account: string; kind: string; outstanding: string; minimum: string; due_date: string | null;
    }>>(`/debts/${encodeURIComponent(debtId)}/increases`, { method: 'POST', body: jsonBody(input) });
    return {
      id: String(result.data.id),
      account: result.data.account,
      kind: result.data.kind,
      outstanding: toMoney(result.data.outstanding),
      minimum: toMoney(result.data.minimum),
      dueDate: displayDate(result.data.due_date),
    };
  },

  async recordDebtPayment(debtId, input) {
    const result = await request<Envelope<{ debt_id: number; outstanding: string }>>(
      `/debts/${encodeURIComponent(debtId)}/payments`,
      { method: 'POST', body: jsonBody(input) },
    );
    return { outstanding: toMoney(result.data.outstanding) };
  },

  async listCalendar(from, to) {
    const params = new URLSearchParams({ from, to });
    const result = await request<{ events: Array<{
      event: string; date: string; amount: string; type: string;
    }> }>(`/calendar?${params.toString()}`);
    return result.events.map((item): CalendarEvent => ({
      event: item.event,
      date: displayDate(item.date, false),
      amount: toMoney(item.amount),
      type: item.type === 'income' ? 'Ingreso' : item.type === 'commitment' ? 'Compromiso' : 'Deuda',
    }));
  },

  async getCashFlowReport(from, to, groupBy) {
    const params = new URLSearchParams({ from, to, group_by: groupBy });
    const result = await request<{
      totals: { forecast: string; actual: string; variance: string };
      series: CashFlowReport['series'];
      categories: CashFlowReport['categories'];
    }>(`/reports/cash-flow?${params.toString()}`);
    return {
      totals: {
        forecast: toMoney(result.totals.forecast),
        actual: toMoney(result.totals.actual),
        variance: toMoney(result.totals.variance),
      },
      series: result.series,
      categories: result.categories.map((item) => ({
        ...item,
        forecast: toMoney(item.forecast),
        actual: toMoney(item.actual),
        variance: toMoney(item.variance),
      })),
    };
  },

  async getSettings() {
    const result = await request<Envelope<RawSettings>>('/settings');
    return toPreferences(result.data);
  },

  async updateSettings(input) {
    const result = await request<Envelope<RawSettings>>('/settings', {
      method: 'PATCH',
      body: jsonBody(toRawPreferences(input)),
    });
    return toPreferences(result.data);
  },

  async listChatMessages() {
    const result = await request<{ data: Array<{
      id: number; role: 'user' | 'assistant' | 'system'; content: string; actions_taken?: any; created_at?: string;
    }> }>('/chat/messages');
    return result.data.map((msg) => ({
      ...msg,
      id: String(msg.id),
    }));
  },

  async sendChatMessage(message: string) {
    const result = await request<{ data: {
      id: number; role: 'user' | 'assistant' | 'system'; content: string; actions_taken?: any; created_at?: string;
    } }>('/chat/messages', {
      method: 'POST',
      body: jsonBody({ message }),
    });
    return {
      ...result.data,
      id: String(result.data.id),
    };
  },

  async clearChatMessages() {
    await request<{ data: { cleared: boolean } }>('/chat/messages', { method: 'DELETE' });
  },
};
