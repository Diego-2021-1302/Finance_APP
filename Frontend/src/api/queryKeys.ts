export const financialQueryKeys = {
  commitments: ['commitments'] as const,
  occurrences: ['commitments'] as const,
  accounts: ['accounts'] as const,
  debts: ['debts'] as const,
  debtStatements: (debtId: string) => ['debt-statements', debtId] as const,
  transactions: ['transactions'] as const,
  calendar: ['calendar'] as const,
  cashFlow: ['cash-flow'] as const,
  dashboard: ['dashboard'] as const,
  financialPlan: (days: number) => ['financial-plan', days] as const,
  income: ['income'] as const,
  expenses: ['expenses'] as const,
  reports: ['cash-flow'] as const,
};

export const commitmentInvalidationKeys = [
  ['financial-plan'] as const,
  financialQueryKeys.commitments,
  financialQueryKeys.accounts,
  financialQueryKeys.debts,
  financialQueryKeys.transactions,
  financialQueryKeys.calendar,
  financialQueryKeys.cashFlow,
  financialQueryKeys.dashboard,
  financialQueryKeys.income,
  financialQueryKeys.expenses,
] as const;
