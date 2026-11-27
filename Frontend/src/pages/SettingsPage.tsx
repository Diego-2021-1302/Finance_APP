import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Avatar, Button, Card, Caption1, Field, Input, MessageBar, MessageBarBody, Subtitle1 } from '@fluentui/react-components';
import { useEffect, useState, type ChangeEvent } from 'react';
import { api } from '../api';
import { useAuth } from '../auth/AuthContext';
import { ErrorState, LoadingState } from '../components/DataState';
import { useAppTheme } from '../theme/ThemeContext';
import type { Preferences } from '../types';

export function SettingsPage() {
  const queryClient = useQueryClient();
  const { user } = useAuth();
  const { setAppearance } = useAppTheme();
  const query = useQuery({ queryKey: ['settings'], queryFn: api.getSettings });
  const [draft, setDraft] = useState<Preferences | null>(null);
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    if (query.data) setDraft(query.data);
  }, [query.data]);
  const save = useMutation({
    mutationFn: api.updateSettings,
    onSuccess: async (preferences) => {
      setDraft(preferences);
      setAppearance(preferences.appearance);
      setSaved(true);
      queryClient.setQueryData(['settings'], preferences);
      await queryClient.invalidateQueries({ queryKey: ['settings'] });
    },
  });
  const uploadPhoto = useMutation({
    mutationFn: ({ type, photo }: { type: 'profile' | 'piggy-bank'; photo: File }) => api.uploadSettingsPhoto(type, photo),
    onSuccess: async (preferences) => {
      setDraft(preferences);
      queryClient.setQueryData(['settings'], preferences);
      await queryClient.invalidateQueries({ queryKey: ['settings'] });
    },
  });

  if (query.isError) return <ErrorState onRetry={() => void query.refetch()} />;
  if (query.isPending || !draft) return <LoadingState />;
  const update = <K extends keyof Preferences>(key: K, value: Preferences[K]) => {
    setSaved(false);
    setDraft((current) => current ? { ...current, [key]: value } : current);
  };
  const selectPhoto = (type: 'profile' | 'piggy-bank', event: ChangeEvent<HTMLInputElement>) => {
    const photo = event.currentTarget.files?.[0];
    event.currentTarget.value = '';
    if (photo) uploadPhoto.mutate({ type, photo });
  };
  return <>
    <div className="page-intro"><p>Personaliza tu perfil, tu alcancía y el horizonte de tus próximos pagos.</p></div>
    {save.isError && <MessageBar intent="error"><MessageBarBody>{save.error.message}</MessageBarBody></MessageBar>}
    {uploadPhoto.isError && <MessageBar intent="error"><MessageBarBody>{uploadPhoto.error.message}</MessageBarBody></MessageBar>}
    <section className="settings-grid">
      <Card className="content-card settings-card">
        <div style={{ display: 'grid', gap: '4px' }}><Subtitle1>Personalización</Subtitle1><Caption1>Elige las imágenes que verás en tu espacio.</Caption1></div>
        <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
          <Avatar name={user?.name ?? 'Usuario'} image={draft.profilePhotoUrl ? { src: draft.profilePhotoUrl } : undefined} size={56} />
          <label style={{ display: 'grid', gap: '6px' }}>Foto de perfil<input type="file" accept="image/png,image/jpeg,image/webp" onChange={(event) => selectPhoto('profile', event)} /></label>
        </div>
        <Caption1>PNG, JPG o WebP; máximo 5 MB.</Caption1>
        <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
          <img src={draft.piggyBankPhotoUrl ?? '/logo.png'} alt="Vista previa de la alcancía" style={{ width: 64, height: 64, borderRadius: 8, objectFit: 'cover' }} />
          <label style={{ display: 'grid', gap: '6px' }}>Foto del botón de la alcancía<input type="file" accept="image/png,image/jpeg,image/webp" onChange={(event) => selectPhoto('piggy-bank', event)} /></label>
        </div>
        {uploadPhoto.isPending && <Caption1>Subiendo imagen…</Caption1>}
      </Card>
      <Card className="content-card settings-card">
        <div style={{ display: 'grid', gap: '4px' }}><Subtitle1>Frecuencia de cobro</Subtitle1><Caption1>Define qué vencimientos se incluyen en tu próximo periodo.</Caption1></div>
        <Field label="¿Cada cuánto cobras?"><select value={draft.payFrequency} onChange={(event) => update('payFrequency', event.target.value as Preferences['payFrequency'])}><option value="weekly">Semanal · próximos 7 días</option><option value="biweekly">Quincenal · próximos 15 días</option><option value="monthly">Mensual · próximos 30 días</option></select></Field>
        <Field label="Zona horaria"><select value={draft.timezone} onChange={(event) => update('timezone', event.target.value)}><option value="America/Santo_Domingo">America/Santo_Domingo</option><option value="America/New_York">America/New_York</option></select></Field>
        <Field label="Inicio de semana"><select value={draft.weekStarts} onChange={(event) => update('weekStarts', event.target.value)}><option value="Monday">Lunes</option><option value="Sunday">Domingo</option></select></Field>
        <Field label="Días de aviso antes del vencimiento"><Input type="number" min={1} max={14} value={String(draft.reminders)} onChange={(event) => update('reminders', Number(event.target.value))} /></Field>
        <Field label="Apariencia"><select value={draft.appearance} onChange={(event) => update('appearance', event.target.value as Preferences['appearance'])}><option value="dark">Oscuro</option><option value="light">Claro</option><option value="system">Según el sistema</option></select></Field>
        <Button appearance="primary" disabled={save.isPending || uploadPhoto.isPending} onClick={() => save.mutate(draft)}>{save.isPending ? 'Guardando…' : 'Guardar configuración'}</Button>
        {saved && <Caption1>Configuración guardada.</Caption1>}
      </Card>
      <Card className="content-card settings-card">
        <div style={{ display: 'grid', gap: '4px' }}><Subtitle1>Avisos y privacidad</Subtitle1><Caption1>La frecuencia configura el horizonte de pendientes del resumen financiero.</Caption1></div>
        <div className="security-callout"><strong>Recordatorios</strong><Caption1>Los avisos de vencimiento usan los días de anticipación configurados y se revisan diariamente.</Caption1></div>
        <div className="security-callout"><strong>Imágenes</strong><Caption1>Las fotos se guardan de forma privada y se muestran mediante enlaces temporales.</Caption1></div>
      </Card>
    </section>
  </>;
}
