export interface User {
  id: string;
  name: string;
  email: string;
}

export interface BudgetRecord {
  category: string;
  limit: string;
  spent: string;
  remaining: string;
  percentage: number;
}

export interface Budget {
  id: number;
  category: string;
  monthly_limit: string;
  year_month: string;
}

export interface Reserve {
  id: number;
  name: string;
  target_amount: string;
  current_amount: string;
  commitment_id: number | null;
}

export interface ImportedTransaction {
  id: number;
  account_id: number;
  occurred_at: string;
  description: string;
  amount: string;
  status: 'pending' | 'confirmed' | 'discarded' | 'duplicate';
  external_reference: string | null;
  financial_transaction_id: number | null;
}

export interface DashboardSummary {
  balances: {
    real: string;
    confirmed: string;
    reserved?: string;
    availableUncommitted?: string;
    totalDebt?: string;
    accountCount: number;
    cards?: Array<{
      id: number;
      name: string;
      balance: string;
      approved_credit_limit: string;
      authorized_overdraft_limit: string;
      remaining_total_capacity: string;
      remaining_overdraft: string;
      limit_status: 'unknown' | 'confirmed' | 'pending_verification' | 'special_rules';
      classification: 'confirmed';
    }>;
    debtBreakdown?: Array<{ id: number; name: string; kind: string | null; outstanding: string; classification: 'confirmed' }>;
    reservesBreakdown?: Array<{ id: number; name: string; currentAmount: string; targetAmount: string; classification: 'confirmed' }>;
  };
  budgets?: BudgetRecord[];
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

export interface CreditCardCapacityMetrics {
  approved_credit_limit: string;
  authorized_overdraft_limit: string;
  total_authorized_capacity: string;
  used_balance: string;
  remaining_approved_credit: string;
  used_overdraft: string;
  remaining_overdraft: string;
  remaining_total_capacity: string;
  limit_status?: 'unknown' | 'confirmed' | 'pending_verification' | 'special_rules';
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
  approvedCreditLimit?: string | null;
  authorizedOverdraftLimit?: string | null;
  overdraftFeeType?: string | null;
  overdraftFeeAmount?: string | null;
  overdraftFeePercentage?: string | null;
  limitStatus?: 'unknown' | 'confirmed' | 'pending_verification' | 'special_rules';
  specialRulesDocumented?: boolean;
  specialRulesSource?: string | null;
  specialRulesReference?: string | null;
  capacityMetrics?: CreditCardCapacityMetrics | null;
  cardProductName?: string | null;
  cardVariant?: string | null;
  cardNetwork?: 'Visa' | 'Mastercard' | 'Amex' | 'Discover' | 'Otra' | null;
  cardCurrency?: 'DOP' | 'USD' | 'EUR' | null;
  issuedAt?: string | null;
  annualFee?: string | null;
  replacementFee?: string | null;
  cashAdvanceFee?: string | null;
  lateFee?: string | null;
  cardBenefits?: string[] | null;
  cardPaymentMethods?: string[] | null;
  isActive?: boolean;
  debtId?: string | null;
  accountId?: string | null;
  termMonths?: number | null;
  amortizationType?: string | null;
  contractConditions?: {
    annual_interest_rate?: string;
    amortization_type?: string;
    term_months?: number;
    source?: Record<string, unknown>;
  } | null;
  amortization?: {
    installment: string;
    schedule: Array<{ month: number; installment: string; principal_portion: string; interest_portion: string; remaining_balance: string }>;
  } | null;
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
  minimum: string | null;
  dueDate: string;
  minimumConfirmed?: boolean;
  lastFour?: string | null;
  approvedCreditLimit?: string | null;
  authorizedOverdraftLimit?: string | null;
  overdraftFeeType?: string | null;
  overdraftFeeAmount?: string | null;
  overdraftFeePercentage?: string | null;
  limitStatus?: 'unknown' | 'confirmed' | 'pending_verification' | 'special_rules';
  specialRulesDocumented?: boolean;
  specialRulesSource?: string | null;
  specialRulesReference?: string | null;
  commitmentId?: string | null;
  accountId?: string | null;
  termMonths?: number | null;
  amortizationType?: string | null;
}

export interface DebtStatementRecord {
  id: string;
  statementDate: string;
  closingBalance: string;
  minimumPayment: string | null;
  minimumPaymentConfirmed: boolean;
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
  commitmentId?: string;
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
  debtId?: string | null;
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

export interface PiggyBankDepositItem {
  id: number;
  amount: string;
  formatted_amount: string;
  type: 'deposit' | 'withdrawal' | 'reset';
  note: string | null;
  occurred_at: string;
  time: string;
}

export interface PiggyBankCalendarDay {
  date: string;
  day: number;
  total_amount: string;
  count: number;
  items: PiggyBankDepositItem[];
}

export interface PiggyBankData {
  total_balance: string;
  formatted_balance: string;
  monthly_summary: {
    month: string;
    total_saved: string;
    formatted_saved: string;
    total_withdrawn: string;
    formatted_withdrawn: string;
    deposit_count: number;
  };
  calendar: PiggyBankCalendarDay[];
  history: PiggyBankDepositItem[];
}

export interface FinancialPlan {
  currency: 'DOP';
  days: number;
  account_balance: string;
  virtual_reserves: string;
  allocatable_now: string;
  suggested_total: string;
  free_after_plan: string;
  uncovered_total: string;
  obligations: Array<{
    occurrence_id: number;
    commitment_id: number;
    name: string;
    type: string;
    due_date: string;
    overdue: boolean;
    amount_due: string;
    suggested_reserve: string;
    shortfall: string;
    minimum_required: string;
    minimum_confirmed: boolean;
    annual_interest_rate: string;
    estimated_30_day_interest_if_unpaid: string;
    estimated_late_fee: string;
    reason: string;
  }>;
  expected_incomes: Array<{ source: string; date: string; remaining: string }>;
  warnings: string[];
}
