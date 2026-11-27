import type { PayFrequency } from '../types';

export interface PayPeriod {
  days: 7 | 15 | 30;
  label: string;
  pendingLabel: string;
}

export function getPayPeriod(frequency?: PayFrequency): PayPeriod {
  if (frequency === 'weekly') {
    return { days: 7, label: 'Semanal', pendingLabel: 'Pendiente próxima semana · 7 días' };
  }
  if (frequency === 'biweekly') {
    return { days: 15, label: 'Quincenal', pendingLabel: 'Pendiente próxima quincena · 15 días' };
  }
  return { days: 30, label: 'Mensual', pendingLabel: 'Pendiente próximo mes · 30 días' };
}