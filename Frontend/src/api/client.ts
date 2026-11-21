import type { ApiClient } from './types';
import { createUuid } from './uuid';
import type {
  AccountRecord, CalendarEvent, CashFlowReport, CommitmentRecord, DashboardSummary, DebtBalancePoint, DebtRecord, ExpenseRecord, FinanceInsights,
  IncomeRecord, Preferences, TransactionRecord, TransferRecord, User,
  ImportedTransaction, PiggyBankData,
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
  is_active?: boolean;
  next_due_date?: string;
  approved_credit_limit?: string | null;
  authorized_overdraft_limit?: string | null;
  overdraft_fee_type?: string | null;
  overdraft_fee_amount?: string | null;
  overdraft_fee_percentage?: string | null;
  limit_status?: 'unknown' | 'confirmed' | 'pending_verification' | 'special_rules';
  special_rules_documented?: boolean;
  special_rules_source?: string | null;
  special_rules_reference?: string | null;
  card_product_name?: string | null;
  card_variant?: string | null;
  card_network?: 'Visa' | 'Mastercard' | 'Amex' | 'Discover' | 'Otra' | null;
  card_currency?: 'DOP' | 'USD' | 'EUR' | null;
  issued_at?: string | null;
  annual_fee?: string | null;
  replacement_fee?: string | null;
  cash_advance_fee?: string | null;
  late_fee?: string | null;
  card_benefits?: string[] | null;
  card_payment_methods?: string[] | null;
  debt_id?: number | null;
  account_id?: number | null;
  term_months?: number | null;
  amortization_type?: string | null;
  contract_conditions?: {
    annual_interest_rate?: string;
    amortization_type?: string;
    term_months?: number;
    source?: Record<string, unknown>;
  } | null;
  amortization?: {
    installment: string;
    schedule: Array<{ month: number; installment: string; principal_portion: string; interest_portion: string; remaining_balance: string }>;
  } | null;
  capacity_metrics?: {
    approved_credit_limit: string;
    authorized_overdraft_limit: string;
    total_authorized_capacity: string;
    used_balance: string;
    remaining_approved_credit: string;
    used_overdraft: string;
    remaining_overdraft: string;
    remaining_total_capacity: string;
    limit_status?: 'unknown' | 'confirmed' | 'pending_verification' | 'special_rules';
  } | null;
};
type RawSettings = {
  currency: 'DOP' | 'USD';
  timezone: string;
  reminders: number;
  week_starts: 'Monday' | 'Sunday';
  appearance: 'light' | 'dark' | 'system';
};

const tunnelUrlStorageKey = 'finance-app.active-tunnel-url';
const accessTokenStorageKey = 'finance-app.access-token';
const deviceIdStorageKey = 'finance-app.device-id';
const tunnelPollIntervalMs = 5000;
let accessToken: string | null = globalThis.localStorage.getItem(accessTokenStorageKey);
let currentUserRequest: Promise<User> | null = null;
let tunnelResolutionPromise: Promise<string | null> | null = null;
let lastTunnelResolutionAt = 0;

export class ApiRequestError extends Error {
  constructor(message: string, readonly status: number) {
    super(message);
    this.name = 'ApiRequestError';
  }
}

function getApiBaseUrl(): string {
  const cachedUrl = globalThis.localStorage.getItem(tunnelUrlStorageKey);
  if (cachedUrl) {
    return `${cachedUrl.replace(/\/+$/, '')}/api/v1`;
  }

  const envUrl = import.meta.env.VITE_API_BASE_URL;
  if (envUrl) {
    return envUrl.replace(/\/+$/, '');
  }

  return '/api/v1';
}

function extractCleanUrl(raw: unknown): string | null {
  if (typeof raw !== 'string') return null;
  const match = raw.match(/https:\/\/[a-z0-9\-]+\.trycloudflare\.com/i);
  if (match) return match[0];
  const cleaned = raw.replace(/^s:\d+:"/, '').replace(/";$/, '').trim();
  if (cleaned.startsWith('http')) return cleaned;
  return null;
}

async function verifyTunnelHealth(candidateUrl: string): Promise<boolean> {
  try {
    const cleanCandidate = extractCleanUrl(candidateUrl);
    if (!cleanCandidate) return false;

    const cleanUrl = cleanCandidate.replace(/\/+$/, '');
    const baseUrl = cleanUrl.endsWith('/api/v1') ? cleanUrl : `${cleanUrl}/api/v1`;

    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 3000);

    const response = await fetch(`${baseUrl}/health`, {
      method: 'GET',
      headers: { Accept: 'application/json' },
      signal: controller.signal,
    }).catch(() => null);

    clearTimeout(timeoutId);
    if (response && response.ok) {
      const data = await response.json().catch(() => null);
      if (data?.status === 'healthy') {
        const tunnelBase = cleanUrl.replace(/\/api\/v1$/, '');
        globalThis.localStorage.setItem(tunnelUrlStorageKey, tunnelBase);
        return true;
      }
    }
  } catch {
    return false;
  }
  return false;
}

function isVercelDeployment(): boolean {
  return Boolean(globalThis.location?.hostname?.endsWith('.vercel.app'));
}

async function fetchActiveTunnelFromGateway(): Promise<string | null> {
  if (tunnelResolutionPromise) return tunnelResolutionPromise;

  tunnelResolutionPromise = (async () => {
    try {
      const controller = new AbortController();
      const timeoutId = setTimeout(() => controller.abort(), 4000);

      const response = await fetch(`/api/tunnel?t=${Date.now()}`, {
        headers: { Accept: 'application/json' },
        signal: controller.signal,
      }).catch(() => null);

      clearTimeout(timeoutId);

      if (response && response.ok) {
        if (!response.headers.get('content-type')?.includes('application/json')) {
          return null;
        }
        const data = await response.json().catch(() => null);
        const candidate = extractCleanUrl(data?.tunnel_url);
        if (candidate) {
          const cachedUrl = globalThis.localStorage.getItem(tunnelUrlStorageKey)?.replace(/\/+$/, '');
          if (cachedUrl === candidate.replace(/\/+$/, '') || await verifyTunnelHealth(candidate)) {
            return `${candidate.replace(/\/+$/, '')}/api/v1`;
          }
        }
      }
    } catch {
      // Continue to the configured backend URL fallback.
    }

    const envUrl = import.meta.env.VITE_API_BASE_URL;
    if (envUrl && (await verifyTunnelHealth(envUrl))) {
      return envUrl.replace(/\/+$/, '');
    }

    return null;
  })();

  try {
    return await tunnelResolutionPromise;
  } finally {
    tunnelResolutionPromise = null;
  }
}

function refreshActiveTunnel(force = false): Promise<string | null> {
  if (!isVercelDeployment()) return Promise.resolve(null);
  if (tunnelResolutionPromise) return tunnelResolutionPromise;
  if (!force && Date.now() - lastTunnelResolutionAt < tunnelPollIntervalMs) {
    return Promise.resolve(null);
  }
  lastTunnelResolutionAt = Date.now();
  return fetchActiveTunnelFromGateway();
}

if (isVercelDeployment()) {
  const refresh = () => {
    void refreshActiveTunnel(true);
  };
  refresh();
  globalThis.setInterval(refresh, tunnelPollIntervalMs);
  globalThis.addEventListener?.('focus', refresh);
  globalThis.document?.addEventListener('visibilitychange', () => {
    if (globalThis.document.visibilityState === 'visible') refresh();
  });
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

async function request<T>(path: string, init: RequestInit = {}, isRetry = false): Promise<T> {
  if (isVercelDeployment()) await refreshActiveTunnel();
  const currentApiBase = getApiBaseUrl();

  const headers = new Headers(init.headers);
  headers.set('Accept', 'application/json');
  if (init.body !== undefined) headers.set('Content-Type', 'application/json');
  if (accessToken) headers.set('Authorization', `Bearer ${accessToken}`);

  const targetUrl = `${currentApiBase}${path}`;

  try {
    const response = await fetch(targetUrl, { ...init, headers });
    if (response.ok && !response.headers.get('content-type')?.includes('application/json')) {
      throw new ApiRequestError('Vercel devolvió la página web en lugar de la respuesta JSON de la API.', 502);
    }
    const payload: unknown = await response.json().catch(() => null);

    if (!response.ok) {
      if ((response.status === 404 || response.status === 405 || response.status === 502 || response.status === 503 || response.status === 504 || response.status === 530) && !isRetry) {
        globalThis.localStorage.removeItem(tunnelUrlStorageKey);
        const newApiBase = await fetchActiveTunnelFromGateway();
        if (newApiBase) {
          return request<T>(path, init, true);
        }
      }

      const message = typeof payload === 'object' && payload !== null
        && 'error' in payload && typeof payload.error === 'object' && payload.error !== null
        && 'message' in payload.error && typeof payload.error.message === 'string'
        ? payload.error.message
        : `La solicitud falló (${response.status})`;
      throw new ApiRequestError(message, response.status);
    }

    return payload as T;
  } catch (error) {
    if (!isRetry && (error instanceof TypeError || (error instanceof ApiRequestError && error.status >= 500))) {
      globalThis.localStorage.removeItem(tunnelUrlStorageKey);
      const newApiBase = await fetchActiveTunnelFromGateway();
      if (newApiBase) {
        return request<T>(path, init, true);
      }
    }
    throw error;
  }
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

  getFinancialPlan(days = 30) {
    return request<Envelope<import('../types').FinancialPlan>>(`/financial-plan?days=${days}`).then((result) => result.data);
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

  async updateIncome(incomeId: string, input: { source: string; amount: string; expected_date: string }) {
    const result = await request<Envelope<IncomeResponse>>(`/incomes/${encodeURIComponent(incomeId)}`, {
      method: 'PUT',
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

  async deleteIncome(incomeId: string) {
    return await request<{ message: string }>(`/incomes/${encodeURIComponent(incomeId)}`, {
      method: 'DELETE',
    });
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

  async listCommitments(filters: { status?: 'active' | 'inactive' | 'all'; type?: string; institution?: string; search?: string; sort?: 'due' | 'amount' | 'name'; page?: number; per_page?: number } = {}) {
    const params = new URLSearchParams();
    Object.entries(filters).forEach(([key, value]) => {
      if (value !== undefined && value !== '' && value !== null) params.set(key, String(value));
    });
    const suffix = params.size ? `?${params.toString()}` : '';
    const result = await request<{
      commitments: CommitmentResponse[];
      occurrences: CommitmentResponse[];
      summary: { pending_period: string; overdue: string; paid_period: string; pending_debt: string; active_count: number };
      meta: { total: number; per_page: number; current_page: number; last_page: number };
    }>(`/commitments${suffix}`);
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
      approvedCreditLimit: item.approved_credit_limit == null ? null : toMoney(item.approved_credit_limit),
      authorizedOverdraftLimit: item.authorized_overdraft_limit == null ? null : toMoney(item.authorized_overdraft_limit),
      overdraftFeeType: item.overdraft_fee_type ?? null,
      overdraftFeeAmount: item.overdraft_fee_amount ?? null,
      overdraftFeePercentage: item.overdraft_fee_percentage ?? null,
      limitStatus: item.limit_status ?? 'unknown',
      specialRulesDocumented: item.special_rules_documented,
      specialRulesSource: item.special_rules_source,
      specialRulesReference: item.special_rules_reference,
      cardProductName: item.card_product_name,
      cardVariant: item.card_variant,
      cardNetwork: item.card_network,
      cardCurrency: item.card_currency,
      issuedAt: item.issued_at ? displayDate(item.issued_at) : null,
      annualFee: item.annual_fee == null ? null : toMoney(item.annual_fee),
      replacementFee: item.replacement_fee == null ? null : toMoney(item.replacement_fee),
      cashAdvanceFee: item.cash_advance_fee == null ? null : toMoney(item.cash_advance_fee),
      lateFee: item.late_fee == null ? null : toMoney(item.late_fee),
      cardBenefits: item.card_benefits,
      cardPaymentMethods: item.card_payment_methods,
      capacityMetrics: item.capacity_metrics ?? null,
      isActive: item.is_active,
      debtId: item.debt_id == null ? null : String(item.debt_id),
      accountId: item.account_id == null ? null : String(item.account_id),
      termMonths: item.term_months ?? null,
      amortizationType: item.amortization_type ?? null,
      contractConditions: item.contract_conditions ?? null,
      amortization: item.amortization ?? null,
      amount: toMoney(item.amount),
      dueDate: displayDate(item.due_date ?? item.next_due_date ?? null),
      status: displayStatus(item.status),
    });
    return {
      commitments: result.commitments.map(mapRecord),
      occurrences: result.occurrences.map((item) => ({
        ...mapRecord(item),
        commitmentId: String(item.commitment_id),
        remainingAmount: toMoney(item.remaining_amount ?? item.amount),
      })),
      summary: result.summary,
      meta: result.meta,
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
      approvedCreditLimit: result.data.approved_credit_limit == null ? null : toMoney(result.data.approved_credit_limit),
      authorizedOverdraftLimit: result.data.authorized_overdraft_limit == null ? null : toMoney(result.data.authorized_overdraft_limit),
      overdraftFeeType: result.data.overdraft_fee_type ?? null,
      overdraftFeeAmount: result.data.overdraft_fee_amount ?? null,
      overdraftFeePercentage: result.data.overdraft_fee_percentage ?? null,
      limitStatus: result.data.limit_status ?? 'unknown',
      specialRulesDocumented: result.data.special_rules_documented,
      specialRulesSource: result.data.special_rules_source,
      specialRulesReference: result.data.special_rules_reference,
      capacityMetrics: result.data.capacity_metrics ?? null,
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
      approvedCreditLimit: result.data.approved_credit_limit == null ? null : toMoney(result.data.approved_credit_limit),
      authorizedOverdraftLimit: result.data.authorized_overdraft_limit == null ? null : toMoney(result.data.authorized_overdraft_limit),
      overdraftFeeType: result.data.overdraft_fee_type ?? null,
      overdraftFeeAmount: result.data.overdraft_fee_amount ?? null,
      overdraftFeePercentage: result.data.overdraft_fee_percentage ?? null,
      limitStatus: result.data.limit_status ?? 'unknown',
      specialRulesDocumented: result.data.special_rules_documented,
      specialRulesSource: result.data.special_rules_source,
      specialRulesReference: result.data.special_rules_reference,
      capacityMetrics: result.data.capacity_metrics ?? null,
      amount: toMoney(result.data.amount),
      frequency: result.data.frequency,
      dueDate: displayDate(result.data.next_due_date),
      status: 'Programado',
    };
  },

  async pauseCommitment(commitmentId: string) {
    const result = await request<Envelope<CommitmentResponse & { frequency: string; next_due_date: string }>>(`/commitments/${encodeURIComponent(commitmentId)}/pause`, { method: 'POST' });
    return {
      id: String(result.data.id),
      name: result.data.name ?? 'Compromiso',
      type: result.data.type ?? 'other',
      amount: toMoney(result.data.amount),
      frequency: result.data.frequency,
      dueDate: displayDate(result.data.next_due_date),
      status: 'Archivado',
      isActive: false,
    };
  },

  async resumeCommitment(commitmentId: string) {
    const result = await request<Envelope<CommitmentResponse & { frequency: string; next_due_date: string }>>(`/commitments/${encodeURIComponent(commitmentId)}/resume`, { method: 'POST' });
    return {
      id: String(result.data.id),
      name: result.data.name ?? 'Compromiso',
      type: result.data.type ?? 'other',
      amount: toMoney(result.data.amount),
      frequency: result.data.frequency,
      dueDate: displayDate(result.data.next_due_date),
      status: 'Activo',
      isActive: true,
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
    const result = await request<{ data: Array<{ id: number; name: string; institution: string | null; type: string; currency: string; balance: string; debt_id: number | null }> }>('/accounts');
    return result.data.map((item): AccountRecord => ({
      ...item,
      id: String(item.id),
      debtId: item.debt_id == null ? null : String(item.debt_id),
      balance: toMoney(item.balance),
    }));
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

  async updateExpense(expenseId: string, input: { account_id: string; category: string; amount: string; occurred_at: string; description: string; reference_number?: string | null }) {
    const result = await request<{
      data: { id: number; account_id: number; category: string; amount: string; occurred_at: string; description: string };
      balance: { confirmed: string };
    }>(`/expenses/${encodeURIComponent(expenseId)}`, {
      method: 'PUT',
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

  async deleteExpense(expenseId: string) {
    return await request<{ message: string }>(`/expenses/${encodeURIComponent(expenseId)}`, {
      method: 'DELETE',
    });
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
      id: number;
      account: string;
      kind: string;
      outstanding: string;
      minimum: string | null;
      minimum_confirmed: boolean;
      due_date: string | null;
      last_four: string | null;
      approved_credit_limit: string | null;
      authorized_overdraft_limit: string | null;
      limit_status: 'unknown' | 'confirmed' | 'pending_verification' | 'special_rules';
      special_rules_documented: boolean;
      special_rules_source: string | null;
      special_rules_reference: string | null;
      commitment_id: number | null;
      account_id: number | null;
      term_months: number | null;
      amortization_type: string | null;
    }> }>('/debts');
    return result.data.map((item): DebtRecord => ({
      id: String(item.id),
      account: item.account,
      kind: item.kind,
      outstanding: toMoney(item.outstanding),
      minimum: item.minimum === null ? null : toMoney(item.minimum),
      minimumConfirmed: item.minimum_confirmed,
      lastFour: item.last_four,
      approvedCreditLimit: item.approved_credit_limit === null ? null : toMoney(item.approved_credit_limit),
      authorizedOverdraftLimit: item.authorized_overdraft_limit === null ? null : toMoney(item.authorized_overdraft_limit),
      limitStatus: item.limit_status,
      specialRulesDocumented: item.special_rules_documented,
      specialRulesSource: item.special_rules_source,
      specialRulesReference: item.special_rules_reference,
      commitmentId: item.commitment_id == null ? null : String(item.commitment_id),
      accountId: item.account_id == null ? null : String(item.account_id),
      termMonths: item.term_months,
      amortizationType: item.amortization_type,
      dueDate: displayDate(item.due_date),
    }));
  },

  async listDebtStatements(debtId: string) {
    const result = await request<{ data: Array<{ id: number; statement_date: string; closing_balance: string; minimum_payment: string | null; minimum_payment_confirmed: boolean; due_date: string }> }>(`/debts/${encodeURIComponent(debtId)}/statements`);
    return result.data.map((item) => ({
      id: String(item.id),
      statementDate: displayDate(item.statement_date),
      closingBalance: toMoney(item.closing_balance),
      minimumPayment: item.minimum_payment === null ? null : toMoney(item.minimum_payment),
      minimumPaymentConfirmed: item.minimum_payment_confirmed,
      dueDate: displayDate(item.due_date),
    }));
  },

  async createDebt(input) {
    const result = await request<Envelope<{
      id: number; account: string; kind: string; outstanding: string; minimum: string | null; minimum_confirmed: boolean; due_date: string | null;
    }>>('/debts', { method: 'POST', body: jsonBody(input) });
    return {
      id: String(result.data.id),
      account: result.data.account,
      kind: result.data.kind,
      outstanding: toMoney(result.data.outstanding),
      minimum: result.data.minimum === null ? null : toMoney(result.data.minimum),
      dueDate: displayDate(result.data.due_date),
    };
  },

  async updateDebtMinimumPayment(debtId, input) {
    const result = await request<Envelope<{
      id: number; account: string; kind: string; outstanding: string; minimum: string | null; minimum_confirmed: boolean; due_date: string | null;
    }>>(`/debts/${encodeURIComponent(debtId)}/minimum-payment`, { method: 'PATCH', body: jsonBody(input) });
    return {
      id: String(result.data.id),
      account: result.data.account,
      kind: result.data.kind,
      outstanding: toMoney(result.data.outstanding),
      minimum: result.data.minimum === null ? null : toMoney(result.data.minimum),
      dueDate: displayDate(result.data.due_date),
    };
  },

  async increaseDebt(debtId, input) {
    const result = await request<Envelope<{
      id: number; account: string; kind: string; outstanding: string; minimum: string | null; minimum_confirmed: boolean; due_date: string | null;
    }>>(`/debts/${encodeURIComponent(debtId)}/increases`, { method: 'POST', body: jsonBody(input) });
    return {
      id: String(result.data.id),
      account: result.data.account,
      kind: result.data.kind,
      outstanding: toMoney(result.data.outstanding),
      minimum: result.data.minimum === null ? null : toMoney(result.data.minimum),
      dueDate: displayDate(result.data.due_date),
    };
  },

  async previewCreditCardAuthorization(debtId, amount) {
    const result = await request<{ data: {
      previous_balance: string;
      projected_balance: string;
      purchase_amount: string;
      available_credit: string;
      available_overdraft: string;
      uses_overdraft: boolean;
      approved_limit: string;
      authorized_overdraft_limit: string;
      projected_overdraft: string;
      fee: string;
      tax: string;
      total_fee: string;
      total_charge: string;
      fee_rule: { id: number | string; trigger: string; formula_type: string; source_url: string | null; verification_status: string } | null;
      contractual_conditions: {
        annual_interest_rate: string;
        is_promotional: boolean;
        effective_source: string;
        fee_source: string;
        verified_official_source: string | null;
        rule_used: string;
        operation_date: string;
      };
    } }>(`/debts/${encodeURIComponent(debtId)}/authorize-card`, {
      method: 'POST',
      body: jsonBody({ amount }),
    });
    return result.data;
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
      event: string; date: string; amount: string | null; type: string; commitment_id?: number;
    }> }>(`/calendar?${params.toString()}`);
    return result.events.map((item): CalendarEvent => ({
      event: item.event,
      date: displayDate(item.date, false),
      amount: item.amount === null ? 'Por confirmar' : toMoney(item.amount),
      type: item.type === 'income' ? 'Ingreso' : item.type === 'commitment' ? 'Compromiso' : 'Deuda',
      commitmentId: item.commitment_id == null ? undefined : String(item.commitment_id),
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


  async listBudgets(yearMonth?: string) {
    const query = yearMonth ? `?year_month=${encodeURIComponent(yearMonth)}` : '';
    const result = await request<{ data: Array<{
      id: number; category: string; monthly_limit: string; year_month: string;
    }> }>(`/budgets${query}`);
    return result.data.map((item) => ({
      ...item,
      monthly_limit: String(item.monthly_limit),
    }));
  },

  async createBudget(input) {
    const result = await request<{ data: { id: number; category: string; monthly_limit: string; year_month: string } }>('/budgets', {
      method: 'POST',
      body: jsonBody(input),
    });
    return result.data;
  },

  async listReserves() {
    const result = await request<{ data: Array<{
      id: number; name: string; target_amount: string; current_amount: string; commitment_id: number | null;
    }> }>(`/reserves`);
    return result.data;
  },

  async createReserve(input) {
    const result = await request<{ data: {
      id: number; name: string; target_amount: string; current_amount: string; commitment_id: number | null;
    } }>(`/reserves`, {
      method: 'POST',
      body: jsonBody({
        ...input,
        commitment_id: input.commitment_id ? Number(input.commitment_id) : null,
      }),
    });
    return result.data;
  },

  async updateReserveAmount(reserveId, input) {
    const result = await request<{ data: {
      id: number; name: string; target_amount: string; current_amount: string; commitment_id: number | null;
    } }>(`/reserves/${encodeURIComponent(reserveId)}`, {
      method: 'PATCH',
      body: jsonBody(input),
    });
    return result.data;
  },

  async deleteReserve(reserveId) {
    const result = await request<{ data: { deleted: boolean } }>(`/reserves/${encodeURIComponent(reserveId)}`, {
      method: 'DELETE',
    });
    return result.data;
  },

  async listImports() {
    const result = await request<{ data: ImportedTransaction[] }>('/imports');
    return result.data;
  },

  async importStatement(input) {
    const result = await request<{ data: ImportedTransaction[] }>('/imports', {
      method: 'POST',
      body: jsonBody({
        account_id: Number(input.account_id),
        csv_content: input.csv_content,
      }),
    });
    return result.data;
  },

  async updateImportStatus(importId, status) {
    const result = await request<{ data: ImportedTransaction }>(`/imports/${encodeURIComponent(importId)}`, {
      method: 'PATCH',
      body: jsonBody({ status }),
    });
    return result.data;
  },

  async getPiggyBankData(month?: string) {
    const query = month ? `?month=${encodeURIComponent(month)}` : '';
    const result = await request<{ data: PiggyBankData }>(`/piggy-bank${query}`);
    return result.data;
  },

  async depositPiggyBank(input) {
    const result = await request<{ data: {
      id: number; amount: string; formatted_amount: string; type: string; note: string | null; new_balance: string; formatted_new_balance: string;
    } }>('/piggy-bank/deposits', {
      method: 'POST',
      body: jsonBody(input),
    });
    return result.data;
  },

  async resetPiggyBank() {
    const result = await request<{ data: { message: string; new_balance: string; formatted_new_balance: string } }>('/piggy-bank/reset', {
      method: 'POST',
    });
    return result.data;
  },
};
