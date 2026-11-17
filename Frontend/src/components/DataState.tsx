import {
  Button, Card, MessageBar, MessageBarBody, MessageBarTitle, Skeleton, SkeletonItem, Body1,
} from '@fluentui/react-components';
import { ArrowClockwiseRegular, DocumentSearchRegular } from '@fluentui/react-icons';

export function LoadingState() {
  return <Card className="state-card"><Skeleton aria-label="Loading content"><SkeletonItem size={24} /><SkeletonItem size={16} /><SkeletonItem size={16} /></Skeleton></Card>;
}

export function ErrorState({ onRetry }: { onRetry: () => void }) {
  return <MessageBar intent="error"><MessageBarBody><MessageBarTitle>No pudimos cargar esta vista</MessageBarTitle>Inténtalo de nuevo en un momento. <Button appearance="subtle" icon={<ArrowClockwiseRegular />} onClick={onRetry}>Reintentar</Button></MessageBarBody></MessageBar>;
}

export function EmptyState({ label }: { label: string }) {
  return <Card className="empty-state"><DocumentSearchRegular fontSize={28} /><Body1>{label}</Body1></Card>;
}
