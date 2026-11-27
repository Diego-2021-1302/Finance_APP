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

export interface DebtHistoryPoint {
  month: string;
  label: string;
  total_debt: string;
  card_debt: string;
  loan_debt: string;
  other_debt: string;
  captured_at: string;
  source: 'month_end' | 'current_estimate';
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
  activeCommitmentsCount?: number;
  commitments: { dueSoon: string; dueSoonLabel: string };
  commitmentGroups?: Array<{ month: string; label: string; total: string; items: Array<{ source: 'compromiso' | 'tarjeta' | 'prestamo'; name: string; date: string; amount: string }> }>;
  upcoming: Array<{ label: string; dueDate: string; amount: string; kind: string }>;
}

export interface IncomeRecord {
  id?: string;
  source: string;
  amount: string;
  amountDecimal?: string;
  receivedAmountDecimal?: string;
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
  cardPaymentMode?: 'minimum' | 'statement';
  minimumPayment?: string | null;
  minimumPaymentConfirmed?: boolean;
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
  standaloneDebt?: boolean;
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
  dueDateIso?: string | null;
  status: string;
}

export interface DebtRecord {
  id?: string;
  account: string;
  kind?: string;
  outstanding: string;
  minimum: string | null;
  dueDate: string;
  dueDateIso?: string | null;
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
  cardProductName?: string | null;
  institution?: string | null;
  statementBalance?: string | null;
  statementDay?: number | null;
  paymentGraceDays?: number | null;
  nextCutoffDate?: string | null;
  effectiveDueDate?: string | null;
  referenceNumber?: string | null;
  maturityDate?: string | null;
  remainingInstallments?: number | null;
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
  approvedCreditLimit?: string | null;
  authorizedOverdraftLimit?: string | null;
  outstandingAmount?: string | null;
  availableCreditCapacity?: string | null;
  lastFour?: string | null;
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

export type PayFrequency = 'weekly' | 'biweekly' | 'monthly';

export interface Preferences {
  currency: string;
  timezone: string;
  reminders: number;
  weekStarts: string;
  appearance: 'light' | 'dark' | 'system';
  payFrequency: PayFrequency;
  profilePhotoUrl: string | null;
  piggyBankPhotoUrl: string | null;
}

export interface InterestCutReport {
  period: {
    pay_frequency: PayFrequency;
    label: string;
    starts_on: string;
    ends_on: string;
    calculated_through: string;
    days_elapsed: number;
  };
  summary: {
    card_financing_interest: string;
    loan_interest: string;
    financing_interest: string;
    overdraft_cut_charge: string;
    total: string;
  };
  items: Array<{
    id: string;
    debt_id: number | null;
    name: string;
    kind: 'credit_card' | 'loan';
    kind_label: string;
    balance: string;
    balance_source: 'current_balance' | 'current_balance_and_period_movements';
    statement_balance: string | null;
    financed_balance: string;
    projected_financed_balance: string;
    daily_interest_if_unpaid: string;
    financing_starts_on: string | null;
    financing_status: 'accruing' | 'grace_period' | 'paid' | 'statement_unavailable';
    annual_rate: string;
    rate_source: string;
    daily_interest_rate: string;
    financing_interest: string;
    financing_interest_label: string;
    financing_interest_detail: string;
    overdraft_cut_charge: string;
    overdraft_charge_status: 'posted' | 'estimated' | 'not_configured' | 'missing_limit' | 'not_applicable';
    overdraft_charge_detail: string;
    total: string;
    rate_missing: boolean;
  }>;
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
  data_completeness?: { status: 'complete' | 'incomplete'; missing_information: string[] };
  summary?: { expected_income: string; pending_obligations: string; maximum_shortfall: string; projected_closing_balance: string; uncovered_total: string };
  funding_sources?: Array<{ id: string; type: 'current_balance' | 'expected_income'; date: string; currency: string; amount_original: string; amount_remaining: string; name: string }>;
  allocations?: Array<{ id: string; obligation_id: string; source_id: string; amount: string; availability_date: string; due_date: string; coverage_type: 'current' | 'expected_income'; status: string }>;
  recommendations?: string[];
  obligations: Array<{
    id?: string;
    occurrence_id: number | null;
    commitment_id: number | null;
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
    amount_pending?: string;
    coverage_status?: 'covered' | 'partially_covered' | 'uncovered';
  }>;
  expected_incomes: Array<{ source: string; date: string; remaining: string }>;
  warnings: string[];
  projection: {
    expected_total: string;
    commitments_total: string;
    ending_balance: string;
    free_to_spend?: string;
    peak_shortfall: string;
    first_shortfall_date: string | null;
    events: Array<{ date: string; due_date?: string; kind: 'income' | 'payment'; name: string; amount: string; balance: string; occurrence_id?: number; timing_uncertain: boolean }>;
  };
}
