import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis,
} from 'recharts';
import {
  Button, Card, CardHeader, Body1, Caption1, Subtitle1,
  MessageBar, MessageBarBody,
} from '@fluentui/react-components';
import {
  ArrowUpRightRegular, WalletRegular, CheckmarkCircleRegular, CalendarRegular, SparkleRegular,
  ChatRegular, SendRegular, DeleteRegular, CheckmarkCircleFilled,
} from '@fluentui/react-icons';
import { useState, useRef, useEffect, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../api';
import { ErrorState, LoadingState } from '../components/DataState';
import type { ChatMessageItem } from '../types';

export function DashboardPage() {
  const query = useQuery({ queryKey: ['dashboard'], queryFn: api.getDashboardSummary });
  const debtHistory = useQuery({ queryKey: ['debt-history'], queryFn: api.getDebtBalanceHistory });
  const currentMonth = new Intl.DateTimeFormat('es-DO', { month: 'long' }).format(new Date());

  if (query.isPending) return <LoadingState />;
  if (query.isError) return <ErrorState onRetry={() => void query.refetch()} />;

  const summary = query.data;
  const metrics = [
    { label: 'Saldo confirmado · Actual', value: summary.balances.confirmed, detail: `En ${summary.balances.accountCount} cuentas operativas`, icon: <WalletRegular /> },
    { label: 'Ingreso esperado · Proyectado', value: summary.income.expected, detail: `Proyección de ${currentMonth}`, icon: <ArrowUpRightRegular /> },
    { label: 'Ingreso recibido · Actual', value: summary.income.received, detail: `En ${currentMonth} hasta la fecha`, icon: <CheckmarkCircleRegular /> },
    { label: 'Próximo compromiso', value: summary.commitments.dueSoon, detail: summary.commitments.dueSoonLabel, icon: <CalendarRegular /> },
  ];

  return <>
    <FinancialChatBot />
    <FinanceInsightsCard />
    <section className="metric-grid" aria-label={`Resumen financiero de ${currentMonth}`}>
      {metrics.map((metric) => <Card className="metric-card" key={metric.label}>
        <CardHeader image={<span className="metric-icon">{metric.icon}</span>} header={<Caption1>{metric.label}</Caption1>} />
        <div className="metric-value">{metric.value}</div><Caption1>{metric.detail}</Caption1>
      </Card>)}
    </section>
    <Card className="content-card chart-card">
      <div className="section-title">
        <div>
          <Subtitle1>Saldo de deuda por mes</Subtitle1>
          <Caption1>Saldo de cierre mensual, incluyendo nuevas deudas y pagos registrados</Caption1>
        </div>
        <Link to="/commitments">Gestionar compromisos →</Link>
      </div>
      {debtHistory.isPending ? <LoadingState /> : debtHistory.isError ? <ErrorState onRetry={() => void debtHistory.refetch()} /> :
        debtHistory.data.length === 0 ? <div className="empty-inline">Agrega una deuda para comenzar el historial mensual de saldos.</div> :
          <div className="chart-area"><ResponsiveContainer width="100%" height="100%"><BarChart data={debtHistory.data.map((point) => ({
            ...point,
            label: new Intl.DateTimeFormat('es-DO', { month: 'short', year: '2-digit', timeZone: 'UTC' }).format(new Date(`${point.month}-15T00:00:00Z`)),
          }))} margin={{ top: 12, right: 12, left: 0, bottom: 0 }}>
            <CartesianGrid stroke="#e5eaf1" vertical={false} />
            <XAxis dataKey="label" axisLine={false} tickLine={false} />
            <YAxis axisLine={false} tickLine={false} tickFormatter={formatDebtBalance} />
            <Tooltip formatter={(value) => [formatDebtBalance(Number(value)), 'Saldo de deuda']} />
            <Bar dataKey="balance" name="Saldo de deuda" fill="#1f4b99" radius={[5, 5, 0, 0]} />
          </BarChart></ResponsiveContainer></div>}
    </Card>
    <section className="content-grid dashboard-grid">
      <Card className="content-card">
        <div className="section-title">
          <div>
            <Subtitle1>Próximos vencimientos</Subtitle1>
            <Caption1>Lo que requiere tu atención a continuación</Caption1>
          </div>
          <Link to="/calendar">Ver calendario →</Link>
        </div>
        {summary.upcoming.length ? <div className="upcoming-list">{summary.upcoming.map((item) => <div className="upcoming-row" key={item.label}>
          <div className="date-chip">{item.dueDate}</div><div className="upcoming-name"><strong>{item.label}</strong><Caption1>{item.kind}</Caption1></div><strong>{item.amount}</strong>
        </div>)}</div> : <div className="empty-inline">No hay vencimientos próximos. Estás al día.</div>}
      </Card>
      <Card className="content-card account-card">
        <div className="section-title">
          <div>
            <Subtitle1>Resumen de cuentas</Subtitle1>
            <Caption1>Saldos reales · actualizados hace un momento</Caption1>
          </div>
        </div>
        <div className="account-balance">{summary.balances.confirmed}</div><Caption1>En tus cuentas activas</Caption1>
        <Link className="account-link" to="/transactions">Revisar movimientos <ArrowUpRightRegular /></Link>
      </Card>
    </section>
  </>;
}

function formatDebtBalance(value: number): string {
  return `RD$ ${value.toLocaleString('es-DO', { maximumFractionDigits: 0 })}`;
}

function FinancialChatBot() {
  const [inputText, setInputText] = useState('');
  const queryClient = useQueryClient();
  const chatContainerRef = useRef<HTMLDivElement>(null);

  const messagesQuery = useQuery({
    queryKey: ['chat-messages'],
    queryFn: api.listChatMessages,
  });

  const invalidateAll = async () => {
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: ['chat-messages'] }),
      queryClient.invalidateQueries({ queryKey: ['dashboard'] }),
      queryClient.invalidateQueries({ queryKey: ['accounts'] }),
      queryClient.invalidateQueries({ queryKey: ['income'] }),
      queryClient.invalidateQueries({ queryKey: ['commitments'] }),
      queryClient.invalidateQueries({ queryKey: ['debt-history'] }),
      queryClient.invalidateQueries({ queryKey: ['transactions'] }),
      queryClient.invalidateQueries({ queryKey: ['cash-flow'] }),
      queryClient.invalidateQueries({ queryKey: ['calendar'] }),
    ]);
  };

  const sendMutation = useMutation({
    mutationFn: api.sendChatMessage,
    onSuccess: invalidateAll,
  });

  const clearMutation = useMutation({
    mutationFn: api.clearChatMessages,
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['chat-messages'] }),
  });

  const messages = messagesQuery.data ?? [];

  useEffect(() => {
    if (chatContainerRef.current) {
      chatContainerRef.current.scrollTop = chatContainerRef.current.scrollHeight;
    }
  }, [messages.length, sendMutation.isPending]);

  function handleSend(textToSend?: string) {
    const text = (textToSend ?? inputText).trim();
    if (!text || sendMutation.isPending) return;
    setInputText('');
    sendMutation.mutate(text);
  }

  function handleSubmit(event: FormEvent) {
    event.preventDefault();
    handleSend();
  }

  return <Card className="content-card chatbot-card">
    <div className="chatbot-header">
      <div className="chatbot-header-title">
        <span className="chatbot-avatar-icon"><ChatRegular /></span>
        <div>
          <Subtitle1>Agente Financiero IA — Asesor Personal</Subtitle1>
          <Caption1>Conversa sobre tu presupuesto, pide análisis o registra ingresos y gastos en tiempo real.</Caption1>
        </div>
      </div>
      {messages.length > 0 && <Button appearance="subtle" icon={<DeleteRegular />} disabled={clearMutation.isPending} onClick={() => clearMutation.mutate()}>
        Limpiar chat
      </Button>}
    </div>

    <div className="chatbot-messages" ref={chatContainerRef} aria-label="Historial de chat con el agente">
      {messages.length === 0 ? <div className="empty-inline">
        <p style={{ margin: '0 0 6px 0', fontWeight: 650 }}>¡Hola! Soy tu agente financiero personal.</p>
        <p style={{ margin: 0, color: 'var(--muted)', fontSize: '12px' }}>Puedo analizar tu salud financiera, darte recomendaciones sobre tu presupuesto o registrar automáticamente tus ingresos, compras y deudas.</p>
      </div> : messages.map((msg: ChatMessageItem, index: number) => <div key={msg.id} className={`chat-bubble ${msg.role}`}>
        <span className="chat-bubble-sender">{msg.role === 'user' ? 'Tú' : 'Agente IA'}</span>
        <div className="chat-bubble-text">{msg.content}</div>
        {msg.role === 'assistant' && index === messages.length - 1 && (
          msg.pending_action?.options && msg.pending_action.options.length > 0 ? (
            <div className="chat-interactive-buttons">
              {msg.pending_action.options.map((opt) => (
                <Button
                  key={opt.value}
                  appearance={opt.value === 'Confirmar' ? 'primary' : 'outline'}
                  size="small"
                  onClick={() => handleSend(opt.value)}
                  disabled={sendMutation.isPending}
                >
                  {opt.label}
                </Button>
              ))}
            </div>
          ) : (msg.content.includes('Confirmar') && msg.content.includes('Cancelar') && (
            <div className="chat-interactive-buttons">
              <Button appearance="primary" size="small" onClick={() => handleSend('Confirmar')} disabled={sendMutation.isPending}>
                ✅ Confirmar
              </Button>
              <Button appearance="subtle" size="small" onClick={() => handleSend('Cancelar')} disabled={sendMutation.isPending}>
                ❌ Cancelar
              </Button>
            </div>
          ))
        )}
        {msg.actions_taken && msg.actions_taken.length > 0 && <div className="chat-actions-container">
          {msg.actions_taken.map((action, idx) => <div className="chat-action-badge" key={idx}>
            <CheckmarkCircleFilled /> <span>{action.title}: {action.detail}</span>
          </div>)}
        </div>}
      </div>)}

      {sendMutation.isPending && <div className="chat-bubble assistant">
        <span className="chat-bubble-sender">Agente IA</span>
        <div className="chat-bubble-text">Analizando tu consulta…</div>
      </div>}
    </div>

    <form onSubmit={handleSubmit} className="chat-input-row">
      <input
        type="text"
        placeholder="Escribe aquí... ej. '¿Cómo ves mis finanzas?' o 'Cobré 15,000 de sueldo'"
        value={inputText}
        onChange={(e) => setInputText(e.target.value)}
        disabled={sendMutation.isPending}
      />
      <Button appearance="primary" type="submit" icon={<SendRegular />} disabled={!inputText.trim() || sendMutation.isPending}>
        Enviar
      </Button>
    </form>
  </Card>;
}

function FinanceInsightsCard() {
  const [consent, setConsent] = useState(false);
  const insights = useMutation({ mutationFn: api.getFinanceInsights, onSettled: () => setConsent(false) });
  return <Card className="content-card insights-card">
    <div className="section-title">
      <div>
        <Subtitle1>Hábitos financieros y recomendaciones</Subtitle1>
        <Caption1>Análisis personalizado impulsado por Google Gemini</Caption1>
      </div>
      <SparkleRegular />
    </div>
    <Body1>Obtén sugerencias prácticas basadas en tus ingresos, gastos, compromisos y deudas actuales.</Body1>
    <div className="insights-disclosure">
      <strong>Antes de cada análisis</strong>
      <span>Con tu confirmación, la aplicación envía a Google Gemini tus fuentes de ingresos recientes, descripciones y montos de movimientos, saldos de deuda actuales y compromisos activos. Se excluyen tu nombre, correo electrónico, nombres de cuentas y números de tarjeta. La clave API permanece en el backend.</span>
    </div>
    <label className="insights-consent">
      <input type="checkbox" checked={consent} onChange={(event) => setConsent(event.target.checked)} />
      <span>Acepto enviar estos detalles financieros a Google Gemini para este análisis.</span>
    </label>
    {insights.isError && <MessageBar intent="error"><MessageBarBody>{insights.error.message}</MessageBarBody></MessageBar>}
    <Button appearance="primary" icon={<SparkleRegular />} disabled={!consent || insights.isPending} onClick={() => insights.mutate()}>
      {insights.isPending ? 'Analizando tus finanzas…' : 'Obtener mis recomendaciones'}
    </Button>
    {insights.data && <div className="insights-result">
      <p>{insights.data.summary}</p>
      {insights.data.good_habits.length > 0 && <div><strong>Buenos hábitos</strong><ul>{insights.data.good_habits.map((habit, index) => <li key={`good-${index}`}>{habit}</li>)}</ul></div>}
      {insights.data.habits_to_improve.length > 0 && <div><strong>Hábitos a mejorar</strong><ul>{insights.data.habits_to_improve.map((habit, index) => <li key={`improve-${index}`}>{habit}</li>)}</ul></div>}
      {insights.data.recommendations.length > 0 && <div><strong>Próximos pasos recomendados</strong><ul>{insights.data.recommendations.map((item, index) => <li key={`${item.title}-${index}`}><b>{item.title}</b> — {item.detail}</li>)}</ul></div>}
      <Caption1>Orientación educativa únicamente; no sustituye el asesoramiento financiero profesional. Generado por IA y puede ser impreciso.</Caption1>
    </div>}
  </Card>;
}
