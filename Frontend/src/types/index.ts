export interface User {
  id: string;
  name: string;
  email: string;
}

export interface DashboardSummary {
  balances: { confirmed: string; totalDebt?: string; accountCount: number };
  income: { expected: string; received: string };
  commitments: { dueSoon: string; dueSoonLabel: string };
  upcoming: Array<{ label: string; dueDate: string; amount: string; kind: string }>;
}

export interface IncomeRecord {
  id?: string;
  source: string;
  amount: string;
  expectedDate: string;
  status: string;
  receivedAmount?: string;
}

export interface CommitmentRecord {
  id?: string;
  commitmentId?: string;
  remainingAmount?: string;
  frequency?: string;
  type?: 'loan' | 'credit_card' | 'utility' | 'subscription' | 'insurance' | 'rent' | 'other';
  referenceNumber?: string | null;
  institution?: string | null;
  currentBalance?: string | null;
  statementBalance?: string | null;
  statementDay?: number | null;
  paymentGraceDays?: number | null;
  interestRate?: string | null;
  category?: string | null;
  maturityDate?: string | null;
  notes?: string | null;
  name: string;
  amount: string;
  dueDate: string;
  status: string;
}

export interface DebtRecord {
  id?: string;
  account: string;
  kind?: string;
  outstanding: string;
  minimum: string;
  dueDate: string;
}

export interface DebtBalancePoint {
  month: string;
  balance: number;
}

export interface FinanceInsights {
  summary: string;
  good_habits: string[];
  habits_to_improve: string[];
  recommendations: Array<{ title: string; detail: string; priority: 'high' | 'medium' | 'low' }>;
}

export interface CalendarEvent {
  event: string;
  date: string;
  amount: string;
  type: string;
}

export interface TransactionRecord {
  id?: string;
  paymentId?: string | null;
  reversedAt?: string | null;
  movement: string;
  account: string;
  amount: string;
  recorded: string;
}

export interface AccountRecord {
  id: string;
  name: string;
  institution?: string | null;
  type: string;
  currency: string;
  balance: string;
}

export interface ExpenseRecord {
  id?: string;
  account_id: string;
  account?: string;
  category: string;
  amount: string;
  occurred_at: string;
  description: string;
  reference_number?: string | null;
}

export interface TransferRecord {
  id?: string;
  source_account: string;
  destination_account: string;
  amount: string;
  transferred_at: string;
  notes?: string | null;
}

export interface CashFlowReport {
  totals: { forecast: string; actual: string; variance: string };
  series: Array<{ month: string; forecastMinor: number; actualMinor: number }>;
  categories: Array<{ category: string; forecast: string; actual: string; variance: string }>;
}

export interface Preferences {
  currency: string;
  timezone: string;
  reminders: number;
  weekStarts: string;
  appearance: 'light' | 'dark' | 'system';
}

export interface ChatMessageItem {
  id: string;
  role: 'user' | 'assistant' | 'system';
  content: string;
  actions_taken?: Array<{
    type: string;
    title: string;
    detail: string;
  }> | null;
  pending_action?: {
    action_type?: string;
    missing_fields?: string[];
    options?: Array<{ label: string; value: string }>;
    status?: string;
  } | null;
  created_at?: string;
}

export interface ApiErrorPayload {
  error: {
    code: 'VALIDATION_ERROR' | 'NOT_FOUND' | 'CONFLICT' | 'UNAUTHENTICATED' | 'FORBIDDEN' | 'INTERNAL_ERROR';
    message: string;
    details: Record<string, string[]> | null;
  };
}
