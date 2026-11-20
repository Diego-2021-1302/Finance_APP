import { Card, Caption1, Badge, Subtitle1 } from '@fluentui/react-components';
import { ShieldCheckmarkRegular, AlertRegular } from '@fluentui/react-icons';

export interface CreditCardCapacityMetrics {
  approved_credit_limit: string;
  authorized_overdraft_limit: string;
  total_authorized_capacity: string;
  used_balance: string;
  remaining_approved_credit: string;
  used_overdraft: string;
  remaining_overdraft: string;
  remaining_total_capacity: string;
}

interface CardCapacityPanelProps {
  cardName: string;
  institutionName?: string | null;
  metrics?: CreditCardCapacityMetrics | null;
  officialSource?: string | null;
}

export function CardCapacityPanel({ cardName, institutionName, metrics, officialSource }: CardCapacityPanelProps) {
  if (!metrics) return null;

  const approvedLimitNum = parseFloat(metrics.approved_credit_limit) || 0;
  const overdraftLimitNum = parseFloat(metrics.authorized_overdraft_limit) || 0;
  const usedBalanceNum = parseFloat(metrics.used_balance) || 0;

  const normalCreditUsed = Math.min(usedBalanceNum, approvedLimitNum);
  const overdraftUsed = Math.max(0, usedBalanceNum - approvedLimitNum);

  const normalCreditPct = approvedLimitNum > 0 ? Math.min(100, (normalCreditUsed / approvedLimitNum) * 100) : 0;
  const overdraftPct = overdraftLimitNum > 0 ? Math.min(100, (overdraftUsed / overdraftLimitNum) * 100) : 0;

  const isOverdraftActive = overdraftUsed > 0;

  return (
    <Card className="content-card card-capacity-panel" style={{ background: 'var(--raised)', border: '1px solid var(--border)' }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <div>
          <Subtitle1>{cardName}</Subtitle1>
          <Caption1>{institutionName ?? 'Banco Emisor'} · Estado de Capacidad</Caption1>
        </div>
        <Badge appearance="tint" color={isOverdraftActive ? 'danger' : 'success'} icon={isOverdraftActive ? <AlertRegular /> : <ShieldCheckmarkRegular />}>
          {isOverdraftActive ? 'EN SOBREGIRO' : 'DENTRO DE LÍMITE'}
        </Badge>
      </div>

      {/* Visual Dual Capacity Progress Bar */}
      <div style={{ marginTop: '14px' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '11px', marginBottom: '6px', color: 'var(--muted)' }}>
          <span>Crédito Normal: {normalCreditPct.toFixed(0)}%</span>
          {overdraftLimitNum > 0 && <span>Sobregiro: {overdraftPct.toFixed(0)}%</span>}
        </div>
        <div style={{ display: 'flex', height: '10px', background: 'rgba(255, 255, 255, 0.08)', borderRadius: '6px', overflow: 'hidden' }}>
          <div style={{ width: `${normalCreditPct}%`, background: 'var(--primary)', height: '100%', transition: 'width 0.3s' }} title="Crédito aprobado utilizado" />
          {overdraftLimitNum > 0 && (
            <div style={{ width: `${overdraftPct}%`, background: '#ff4d4f', height: '100%', transition: 'width 0.3s' }} title="Sobregiro utilizado" />
          )}
        </div>
      </div>

      {/* Structured Metrics Grid */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(130px, 1fr))', gap: '12px', marginTop: '16px', fontSize: '12px' }}>
        <div>
          <Caption1>Límite Aprobado</Caption1>
          <div><strong>RD$ {numberFormat(approvedLimitNum)}</strong></div>
        </div>
        <div>
          <Caption1>Sobregiro Autorizado</Caption1>
          <div><strong>RD$ {numberFormat(overdraftLimitNum)}</strong></div>
        </div>
        <div>
          <Caption1>Capacidad Total</Caption1>
          <div><strong style={{ color: 'var(--primary)' }}>RD$ {numberFormat(approvedLimitNum + overdraftLimitNum)}</strong></div>
        </div>
        <div>
          <Caption1>Saldo Utilizado</Caption1>
          <div><strong style={{ color: isOverdraftActive ? '#ff4d4f' : 'var(--text)' }}>RD$ {numberFormat(usedBalanceNum)}</strong></div>
        </div>
        <div>
          <Caption1>Aprobado Restante</Caption1>
          <div><strong>RD$ {metrics.remaining_approved_credit}</strong></div>
        </div>
        <div>
          <Caption1>Sobregiro Restante</Caption1>
          <div><strong>RD$ {metrics.remaining_overdraft}</strong></div>
        </div>
      </div>

      {officialSource && (
        <div style={{ marginTop: '10px', paddingTop: '8px', borderTop: '1px solid var(--border-subtle)', textAlign: 'right' }}>
          <a href={officialSource} target="_blank" rel="noopener noreferrer" style={{ fontSize: '11px', color: 'var(--primary)' }}>
            Tarifario oficial publicado por la institución →
          </a>
        </div>
      )}
    </Card>
  );
}

function numberFormat(val: number): string {
  return val.toLocaleString('es-DO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
