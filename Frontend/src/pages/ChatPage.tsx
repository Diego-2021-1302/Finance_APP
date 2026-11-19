import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, Card, Caption1, Subtitle1 } from '@fluentui/react-components';
import { ChatRegular, SendRegular, DeleteRegular, CheckmarkCircleFilled } from '@fluentui/react-icons';
import { useState, useRef, useEffect, type FormEvent } from 'react';
import { api } from '../api';
import type { ChatMessageItem } from '../types';

export function ChatPage() {
  const [inputText, setInputText] = useState('');
  const queryClient = useQueryClient();
  const chatContainerRef = useRef<HTMLDivElement>(null);

  const messagesQuery = useQuery({
    queryKey: ['chat-messages'],
    queryFn: api.listChatMessages,
  });

  const invalidateAll = async () => {
    await queryClient.invalidateQueries();
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

  return (
    <div style={{ display: 'grid', gap: '20px', maxWidth: '1000px', margin: '0 auto', width: '100%' }}>
      <div className="page-intro">
        <p>Conversa con tu asistente financiero inteligente, solicita diagnósticos o registra transacciones automáticamente.</p>
      </div>

      <Card className="content-card chatbot-card" style={{ minHeight: '580px' }}>
        <div className="chatbot-header">
          <div className="chatbot-header-title">
            <span className="chatbot-avatar-icon"><ChatRegular /></span>
            <div>
              <Subtitle1>Asistente Financiero IA — Asesor Personal</Subtitle1>
              <Caption1>Asistencia en lenguaje natural para finanzas en Pesos Dominicanos (DOP)</Caption1>
            </div>
          </div>
          {messages.length > 0 && (
            <Button appearance="subtle" icon={<DeleteRegular />} disabled={clearMutation.isPending} onClick={() => clearMutation.mutate()}>
              Limpiar chat
            </Button>
          )}
        </div>

        <div className="chatbot-messages" ref={chatContainerRef} style={{ height: '440px', maxHeight: '500px' }} aria-label="Historial de chat con el agente">
          {messages.length === 0 ? (
            <div className="empty-inline" style={{ padding: '60px 20px', textAlign: 'center' }}>
              <p style={{ margin: '0 0 8px 0', fontWeight: 650, fontSize: '15px' }}>¡Hola! Soy tu asistente financiero personal.</p>
              <p style={{ margin: 0, color: 'var(--muted)', fontSize: '13px', maxWidth: '480px', marginInline: 'auto' }}>
                Puedo darte diagnósticos de tu salud financiera, analizar tus hábitos o ayudarte a registrar ingresos, compras de supermercado, combustible y deudas.
              </p>
            </div>
          ) : (
            messages.map((msg: ChatMessageItem, index: number) => (
              <div key={msg.id} className={`chat-bubble ${msg.role}`}>
                <span className="chat-bubble-sender">{msg.role === 'user' ? 'Tú' : 'Asistente IA'}</span>
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
                {msg.actions_taken && msg.actions_taken.length > 0 && (
                  <div className="chat-actions-container">
                    {msg.actions_taken.map((action, idx) => (
                      <div className="chat-action-badge" key={idx}>
                        <CheckmarkCircleFilled /> <span>{action.title}: {action.detail}</span>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            ))
          )}

          {sendMutation.isPending && (
            <div className="chat-bubble assistant">
              <span className="chat-bubble-sender">Asistente IA</span>
              <div className="chat-bubble-text">Analizando tu consulta…</div>
            </div>
          )}
        </div>

        <form onSubmit={handleSubmit} className="chat-input-row">
          <input
            type="text"
            placeholder="Escribe aquí... ej. '¿Cómo están mis finanzas?' o 'Cobré 25,000 de salario en Ahorros'"
            value={inputText}
            onChange={(e) => setInputText(e.target.value)}
            disabled={sendMutation.isPending}
          />
          <Button appearance="primary" type="submit" icon={<SendRegular />} disabled={!inputText.trim() || sendMutation.isPending}>
            Enviar
          </Button>
        </form>
      </Card>
    </div>
  );
}
