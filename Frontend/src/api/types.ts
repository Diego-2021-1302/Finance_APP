import type {
  AccountRecord, CalendarEvent, CashFlowReport, CommitmentRecord, DashboardSummary, DebtBalancePoint, DebtRecord, ExpenseRecord, FinanceInsights,
  IncomeRecord, Preferences, TransactionRecord, TransferRecord, User, ChatMessageItem,
} from '../types';

export interface ApiClient {
  getCurrentUser(): Promise<User>;
  login(input: { email: string; password: string }): Promise<User>;
  logout(): Promise<void>;
  createAccount(input: { name: string; email: string; password: string }): Promise<User>;
  getDashboardSummary(): Promise<DashboardSummary>;
  getDebtBalanceHistory(): Promise<DebtBalancePoint[]>;
  getFinanceInsights(): Promise<FinanceInsights>;
  listIncomes(): Promise<IncomeRecord[]>;
  createIncome(input: { source: string; amount: string; expected_date: string }): Promise<IncomeRecord>;
  recordReceipt(incomeId: string, input: { amount: string; account_id: string; received_at: string; idempotency_key: string }): Promise<{ amount: string; balance: string }>;
  listCommitments(): Promise<{ commitments: CommitmentRecord[]; occurrences: CommitmentRecord[] }>;
  createCommitment(input: {
    name: string;
    type: NonNullable<CommitmentRecord['type']>;
    amount?: string;
    frequency?: string;
    next_due_date?: string;
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
  }): Promise<CommitmentRecord>;
  updateCommitment(commitmentId: string, input: {
    name: string;
    type: NonNullable<CommitmentRecord['type']>;
    amount?: string;
    frequency?: string;
    next_due_date?: string;
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
  }): Promise<CommitmentRecord>;
  deleteCommitment(commitmentId: string): Promise<{ id: string; deleted: boolean }>;
  recordCommitmentPayment(commitmentId: string, occurrenceId: string, input: { amount: string; account_id: string; paid_at: string; idempotency_key: string }): Promise<{ amount: string; remaining_due: string }>;
  reversePayment(paymentId: string, input: { reason: string; idempotency_key: string }): Promise<{ reversed: boolean; reason: string }>;
  listAccounts(): Promise<AccountRecord[]>;
  createFinancialAccount(input: { name: string; institution: string; type: string; currency: string; opening_balance: string }): Promise<AccountRecord>;
  updateFinancialAccount(accountId: string, input: { name: string; institution?: string | null; type: string }): Promise<AccountRecord>;
  deleteFinancialAccount(accountId: string): Promise<{ id: string; deleted: boolean }>;
  archiveAccount(accountId: string): Promise<{ id: string; archived: boolean }>;
  reconcileAccount(accountId: string, input: { target_balance: string; reason: string }): Promise<AccountRecord>;
  listExpenses(filters?: { from?: string; to?: string; category?: string; account_id?: string }): Promise<ExpenseRecord[]>;
  createExpense(input: { account_id: string; category: string; amount: string; occurred_at: string; description: string; reference_number?: string | null; idempotency_key: string }): Promise<ExpenseRecord>;
  listTransfers(): Promise<TransferRecord[]>;
  createTransfer(input: { source_account_id: string; destination_account_id: string; amount: string; transferred_at: string; notes?: string | null; idempotency_key: string }): Promise<TransferRecord>;
  listTransactions(filters?: { from?: string; to?: string; account_id?: string; q?: string }): Promise<TransactionRecord[]>;
  listDebts(): Promise<DebtRecord[]>;
  createDebt(input: { name: string; kind: string; outstanding_amount: string; minimum_payment: string; due_date: string | null; last_four: string | null; idempotency_key: string }): Promise<DebtRecord>;
  increaseDebt(debtId: string, input: { amount: string; description: string; occurred_at: string; idempotency_key: string }): Promise<DebtRecord>;
  recordDebtPayment(debtId: string, input: { amount: string; account_id: string | null; paid_at: string; idempotency_key: string }): Promise<{ outstanding: string }>;
  listCalendar(from: string, to: string): Promise<CalendarEvent[]>;
  getCashFlowReport(from: string, to: string, groupBy: string): Promise<CashFlowReport>;
  getSettings(): Promise<Preferences>;
  updateSettings(input: Partial<Preferences>): Promise<Preferences>;
  listChatMessages(): Promise<ChatMessageItem[]>;
  sendChatMessage(message: string): Promise<ChatMessageItem>;
  clearChatMessages(): Promise<void>;
}
