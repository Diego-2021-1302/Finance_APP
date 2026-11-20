import { useState } from 'react';
import { Button, Card, MessageBar, MessageBarBody, Subtitle1, Caption1, Badge } from '@fluentui/react-components';
import { AlertRegular, CheckmarkCircleFilled, ReceiptRegular } from '@fluentui/react-icons';

interface CreditCardPaymentConfirmationProps {
  cardName: string;
  amount: number;
  availableApprovedCredit: number;
  availableOverdraft: number;
  estimatedFee?: number;
  onConfirm: () => void;
  onCancel: () => void;
}

export function CreditCardPaymentConfirmation({
  cardName,
  amount,
  availableApprovedCredit,
  availableOverdraft,
  estimatedFee = 0,
  onConfirm,
  onCancel,
}: CreditCardPaymentConfirmationProps) {
  const [confirmed, setConfirmed] = useState(false);
  const totalAvailable = availableApprovedCredit + availableOverdraft;
  const usesOverdraft = amount > availableApprovedCredit;
  const overdraftAmount = usesOverdraft ? amount - availableApprovedCredit : 0;
  const exceedsTotal = amount + estimatedFee > totalAvailable;

  if (confirmed) {
    return (
      <Card className="content-card receipt-card" style={{ border: '1px solid var(--primary)', background: 'var(--raised)' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: '10px', color: 'var(--primary)' }}>
          <CheckmarkCircleFilled fontSize={24} />
          <Subtitle1>Comprobante Interno de Operación</Subtitle1>
        </div>
        <div style={{ display: 'grid', gap: '8px', marginTop: '12px', fontSize: '13px' }}>
          <div><strong>Medio de Pago:</strong> {cardName}</div>
          <div><strong>Monto Cargado:</strong> RD$ {amount.toLocaleString('es-DO', { minimumFractionDigits: 2 })}</div>
          {estimatedFee > 0 && <div><strong>Comisión por Sobregiro:</strong> RD$ {estimatedFee.toLocaleString('es-DO', { minimumFractionDigits: 2 })}</div>}
          <Caption1 style={{ marginTop: '8px', color: 'var(--muted)' }}>
            Nota: Registro contable interno. No afirma haber ejecutado una transacción bancaria en vivo sin integración autorizada del emisor.
          </Caption1>
        </div>
      </Card>
    );
  }

  return (
    <Card className="content-card confirmation-card" style={{ border: '1px solid var(--border)', background: 'var(--raised)', marginTop: '12px' }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
        <Subtitle1 style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
          <ReceiptRegular style={{ color: 'var(--primary)' }} />
          Confirmación de Pago con Tarjeta
        </Subtitle1>
        <Badge appearance="tint" color={usesOverdraft ? 'warning' : 'brand'}>
          {usesOverdraft ? 'USARÁ SOBREGIRO' : 'DENTRO DE LÍMITE'}
        </Badge>
      </div>

      <div style={{ display: 'grid', gap: '8px', marginTop: '12px', fontSize: '13px' }}>
        <div><strong>Tarjeta Seleccionada:</strong> {cardName}</div>
        <div><strong>Crédito Aprobado Disponible:</strong> RD$ {availableApprovedCredit.toLocaleString('es-DO', { minimumFractionDigits: 2 })}</div>
        <div><strong>Sobregiro Disponible:</strong> RD$ {availableOverdraft.toLocaleString('es-DO', { minimumFractionDigits: 2 })}</div>
        <div><strong>Monto a Cargar:</strong> <strong style={{ color: 'var(--primary)' }}>RD$ {amount.toLocaleString('es-DO', { minimumFractionDigits: 2 })}</strong></div>
      </div>

      {usesOverdraft && (
        <MessageBar intent="warning" style={{ marginTop: '12px' }}>
          <MessageBarBody>
            <AlertRegular style={{ verticalAlign: 'middle', marginRight: '6px' }} />
            <strong>Atención:</strong> Esta compra utiliza RD$ {overdraftAmount.toLocaleString('es-DO', { minimumFractionDigits: 2 })} de sobregiro autorizado. Se evaluarán los cargos contractuales por sobregiro según las condiciones del producto.
          </MessageBarBody>
        </MessageBar>
      )}

      {exceedsTotal && (
        <MessageBar intent="error" style={{ marginTop: '12px' }}>
          <MessageBarBody>
            <strong>Operación rechazada:</strong> El monto solicitado supera la capacidad total autorizada (Límite + Sobregiro) de la tarjeta.
          </MessageBarBody>
        </MessageBar>
      )}

      <div style={{ display: 'flex', gap: '10px', marginTop: '16px' }}>
        <Button
          appearance="primary"
          disabled={exceedsTotal}
          onClick={() => {
            setConfirmed(true);
            onConfirm();
          }}
        >
          Confirmar e Imputar Cargo
        </Button>
        <Button appearance="subtle" onClick={onCancel}>
          Cancelar
        </Button>
      </div>
    </Card>
  );
}
