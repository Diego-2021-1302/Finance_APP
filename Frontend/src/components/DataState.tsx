import {
  Button, Card, MessageBar, MessageBarBody, MessageBarTitle, Skeleton, SkeletonItem, Body1,
} from '@fluentui/react-components';
import { ArrowClockwiseRegular, DocumentSearchRegular } from '@fluentui/react-icons';

export function LoadingState({ label = 'Cargando información...' }: { label?: string }) {
  return (
    <Card className="content-card modern-loading-card">
      <div className="modern-loading-content">
        <div className="mini-spinner-ring" />
        <Body1 className="loading-label">{label}</Body1>
      </div>
      <Skeleton aria-label="Cargando contenido" style={{ display: 'grid', gap: '10px', marginTop: '16px' }}>
        <SkeletonItem size={28} style={{ width: '40%', borderRadius: '8px' }} />
        <SkeletonItem size={16} style={{ width: '85%', borderRadius: '6px' }} />
        <SkeletonItem size={16} style={{ width: '65%', borderRadius: '6px' }} />
      </Skeleton>
    </Card>
  );
}

export function ErrorState({ onRetry }: { onRetry: () => void }) {
  return (
    <MessageBar intent="error" style={{ borderRadius: '12px', backdropFilter: 'blur(10px)' }}>
      <MessageBarBody>
        <MessageBarTitle>No pudimos cargar esta información</MessageBarTitle>
        Ocurrió un error al consultar el servidor.{' '}
        <Button appearance="subtle" size="small" icon={<ArrowClockwiseRegular />} onClick={onRetry}>
          Reintentar
        </Button>
      </MessageBarBody>
    </MessageBar>
  );
}

export function EmptyState({ label }: { label: string }) {
  return (
    <Card className="content-card empty-state" style={{ padding: '40px 20px', textAlign: 'center', display: 'grid', placeItems: 'center', gap: '10px' }}>
      <DocumentSearchRegular fontSize={32} style={{ color: 'var(--primary)' }} />
      <Body1 style={{ color: 'var(--muted)', fontSize: '13.5px' }}>{label}</Body1>
    </Card>
  );
}
