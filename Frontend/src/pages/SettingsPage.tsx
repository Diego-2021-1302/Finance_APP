import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Card, Caption1, Field, Input, MessageBar, MessageBarBody, Subtitle1 } from '@fluentui/react-components';
import { useEffect, useState } from 'react';
import { api } from '../api';
import { ErrorState, LoadingState } from '../components/DataState';
import { useAppTheme } from '../theme/ThemeContext';
import type { Preferences } from '../types';

export function SettingsPage() {
  const queryClient = useQueryClient();
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
      await queryClient.invalidateQueries({ queryKey: ['settings'] });
    },
  });

  if (query.isError) return <ErrorState onRetry={() => void query.refetch()} />;
  if (query.isPending || !draft) return <LoadingState />;
  const update = <K extends keyof Preferences>(key: K, value: Preferences[K]) => {
    setSaved(false);
    setDraft((current) => current ? { ...current, [key]: value } : current);
  };
  return <>
    <div className="page-intro"><p>Set the calendar, reminder, and appearance preferences used across your workspace.</p></div>
    {save.isError && <MessageBar intent="error"><MessageBarBody>{save.error.message}</MessageBarBody></MessageBar>}
    <section className="settings-grid">
      <Card className="content-card settings-card">
        <div style={{ display: 'grid', gap: '4px' }}><Subtitle1>Regional preferences</Subtitle1><Caption1>Reminder dates use your selected time zone. DOP is the account base currency.</Caption1></div>
        <Field label="Time zone"><select value={draft.timezone} onChange={(event) => update('timezone', event.target.value)}><option value="America/Santo_Domingo">America/Santo_Domingo</option><option value="America/New_York">America/New_York</option></select></Field>
        <Field label="Week starts"><select value={draft.weekStarts} onChange={(event) => update('weekStarts', event.target.value)}><option value="Monday">Monday</option><option value="Sunday">Sunday</option></select></Field>
        <Field label="Reminder days before due date"><Input type="number" min={1} max={14} value={String(draft.reminders)} onChange={(event) => update('reminders', Number(event.target.value))} /></Field>
        <Button appearance="primary" disabled={save.isPending} onClick={() => save.mutate(draft)}>{save.isPending ? 'Saving…' : 'Save preferences'}</Button>
        {saved && <Caption1>Preferences saved.</Caption1>}
      </Card>
      <Card className="content-card settings-card">
        <div style={{ display: 'grid', gap: '4px' }}><Subtitle1>Reminders and privacy</Subtitle1><Caption1>Due-date reminder emails use the number of advance days configured here.</Caption1></div>
        <div className="security-callout"><strong>Reminder schedule</strong><Caption1>The backend checks daily at 08:00 Santo Domingo time. Email delivery also requires a working mail service configuration.</Caption1></div>
        <div className="security-callout"><strong>Account security</strong><Caption1>Your session stays active on this browser until you explicitly sign out. Never store full card numbers in this application.</Caption1></div>
      </Card>
    </section>
  </>;
}
