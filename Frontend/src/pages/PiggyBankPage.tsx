import { useState, useRef, type FormEvent } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  Button, Card, Caption1, Dialog, DialogSurface, DialogBody, DialogTitle,
  DialogContent, DialogActions, Field, Input, MessageBar, MessageBarBody,
} from '@fluentui/react-components';
import { SparkleRegular } from '@fluentui/react-icons';
import { api } from '../api';
import { LoadingState, ErrorState } from '../components/DataState';
import type { PiggyBankCalendarDay } from '../types';

export function PiggyBankPage() {
  const currentMonthStr = new Date().toISOString().slice(0, 7);
  const [selectedMonth, setSelectedMonth] = useState(currentMonthStr);
  const [activeAction, setActiveAction] = useState<'idle' | 'saving' | 'withdrawing'>('idle');
  const [showCalendarModal, setShowCalendarModal] = useState(false);
  const [saveNote, setSaveNote] = useState('');
  const [saveAmount, setSaveAmount] = useState('');
  const [withdrawAmount, setWithdrawAmount] = useState('');
  const [withdrawNote, setWithdrawNote] = useState('');
  const [error, setError] = useState('');
  const [activeDayDetails, setActiveDayDetails] = useState<PiggyBankCalendarDay | null>(null);

  const longPressTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const isLongPress = useRef(false);

  const queryClient = useQueryClient();

  const piggyQuery = useQuery({
    queryKey: ['piggy-bank', selectedMonth],
    queryFn: () => api.getPiggyBankData(selectedMonth),
  });
  const settingsQuery = useQuery({ queryKey: ['settings'], queryFn: api.getSettings });

  const depositMutation = useMutation({
    mutationFn: (input: { amount: string; type: 'deposit' | 'withdrawal'; note?: string }) =>
      api.depositPiggyBank(input),
    onSuccess: async () => {
      setActiveAction('idle');
      setSaveAmount('');
      setSaveNote('');
      setWithdrawAmount('');
      setWithdrawNote('');
      await queryClient.invalidateQueries();
    },
    onError: (cause) => setError(cause.message),
  });

  const resetMutation = useMutation({
    mutationFn: () => api.resetPiggyBank(),
    onSuccess: async () => {
      setActiveAction('idle');
      await queryClient.invalidateQueries();
    },
    onError: (cause) => setError(cause.message),
  });

  // Long press handling for piggy bank logo
  const handleTouchStart = () => {
    isLongPress.current = false;
    longPressTimer.current = setTimeout(() => {
      isLongPress.current = true;
      setActiveAction('withdrawing');
    }, 600);
  };

  const handleTouchEnd = () => {
    if (longPressTimer.current) {
      clearTimeout(longPressTimer.current);
    }
  };

  const handleLogoClick = () => {
    if (isLongPress.current) {
      isLongPress.current = false;
      return;
    }
    setError('');
    setActiveAction('saving');
  };

  function handleSaveSubmit(e: FormEvent) {
    e.preventDefault();
    if (!saveAmount || parseFloat(saveAmount) <= 0) return;
    setError('');
    depositMutation.mutate({
      amount: saveAmount,
      type: 'deposit',
      note: saveNote.trim() || undefined,
    });
  }

  function handleWithdrawSubmit(e: FormEvent) {
    e.preventDefault();
    if (!withdrawAmount || parseFloat(withdrawAmount) <= 0) return;
    setError('');
    depositMutation.mutate({
      amount: withdrawAmount,
      type: 'withdrawal',
      note: withdrawNote.trim() || undefined,
    });
  }

  if (piggyQuery.isPending) return <LoadingState label="Cargando tu alcancía..." />;
  if (piggyQuery.isError) return <ErrorState onRetry={() => void piggyQuery.refetch()} />;

  const piggyData = piggyQuery.data;

  // Calendar rendering helper
  const [yearNum, monthNum] = selectedMonth.split('-').map(Number);
  const daysInMonth = new Date(yearNum, monthNum, 0).getDate();
  const firstDayOfWeek = new Date(yearNum, monthNum - 1, 1).getDay();

  const calendarMap = new Map<string, PiggyBankCalendarDay>();
  piggyData.calendar.forEach((c: PiggyBankCalendarDay) => calendarMap.set(c.date, c));

  return (
    <div className="piggy-bank-container">
      {error && (
        <MessageBar intent="error" style={{ marginBottom: '16px', borderRadius: '12px', width: '100%', maxWidth: '380px' }}>
          <MessageBarBody>{error}</MessageBarBody>
        </MessageBar>
      )}

      {/* Main Piggy Bank Card */}
      <Card className="piggy-bank-main-card">
        {/* Top Balance Display */}
        <div className="piggy-balance-display">
          <span className="piggy-currency-symbol">RD$</span>
          <span className="piggy-balance-amount">
            {parseFloat(piggyData.total_balance).toLocaleString('es-DO', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
          </span>
        </div>

        {activeAction === 'idle' ? (
          <>
            <p className="piggy-instruction-text">
              Toca para ahorrar y mantén presionado para retirar ó vaciar.
            </p>

            {/* Central Logo Container */}
            <div
              className="piggy-logo-wrapper"
              onClick={handleLogoClick}
              onMouseDown={handleTouchStart}
              onMouseUp={handleTouchEnd}
              onTouchStart={handleTouchStart}
              onTouchEnd={handleTouchEnd}
              onContextMenu={(e) => {
                e.preventDefault();
                setActiveAction('withdrawing');
              }}
              title="Toca para ahorrar, mantén presionado para retirar"
            >
              <div className="piggy-logo-glow-ring" />
              <img src={settingsQuery.data?.piggyBankPhotoUrl ?? '/logo.png'} alt="Alcancía Ghost Finance" className="piggy-logo-image" />
            </div>

            <div className="piggy-sub-caption">
              <SparkleRegular style={{ color: '#f59e0b', verticalAlign: 'middle', marginRight: '6px' }} />
              ¡Ahorra conmigo!
            </div>

            {/* Bottom Primary Button */}
            <Button
              className="piggy-action-btn"
              appearance="primary"
              onClick={() => setShowCalendarModal(true)}
            >
              MOSTRAR MIS AHORROS
            </Button>
          </>
        ) : activeAction === 'saving' ? (
          /* INLINE SAVING FORM */
          <div className="piggy-inline-form-box">
            <div className="piggy-inline-form-header">
              <h3>¡Atención!</h3>
              <p>¿Cuánto vas a ahorrar?</p>
            </div>

            <form onSubmit={handleSaveSubmit} className="piggy-form" autoComplete="off">
              <div className="piggy-input-group">
                <label>Cantidad (DOP)</label>
                <input
                  type="number"
                  min="0.01"
                  step="0.01"
                  placeholder="0.00"
                  value={saveAmount}
                  onChange={(e) => setSaveAmount(e.target.value)}
                  required
                  autoFocus
                  autoComplete="off"
                  autoCorrect="off"
                  autoCapitalize="off"
                  spellCheck="false"
                  inputMode="decimal"
                  name="inline_amt_1"
                />
              </div>

              <div className="piggy-input-group">
                <label>Nota (opcional)</label>
                <input
                  type="text"
                  maxLength={40}
                  placeholder="ej. Meta del mes"
                  value={saveNote}
                  onChange={(e) => setSaveNote(e.target.value.slice(0, 40))}
                  autoComplete="off"
                  autoCorrect="off"
                  autoCapitalize="off"
                  spellCheck="false"
                  name="inline_note_1"
                />
                <div className="char-counter">{saveNote.length}/40</div>
              </div>

              <div className="piggy-inline-actions">
                <button type="button" className="piggy-btn-cancel" onClick={() => setActiveAction('idle')}>
                  Cancelar
                </button>
                <button type="submit" className="piggy-btn-confirm" disabled={depositMutation.isPending || !saveAmount || parseFloat(saveAmount) <= 0}>
                  {depositMutation.isPending ? 'Guardando...' : 'AHORRAR'}
                </button>
              </div>
            </form>
          </div>
        ) : (
          /* INLINE WITHDRAW FORM */
          <div className="piggy-inline-form-box">
            <div className="piggy-inline-form-header">
              <h3>Retirar de la Alcancía</h3>
              <p>Disponible: <strong>{piggyData.formatted_balance}</strong></p>
            </div>

            <form onSubmit={handleWithdrawSubmit} className="piggy-form" autoComplete="off">
              <div className="piggy-input-group">
                <label>Monto a retirar (DOP)</label>
                <input
                  type="number"
                  min="0.01"
                  step="0.01"
                  max={piggyData.total_balance}
                  placeholder="0.00"
                  value={withdrawAmount}
                  onChange={(e) => setWithdrawAmount(e.target.value)}
                  required
                  autoFocus
                  autoComplete="off"
                  autoCorrect="off"
                  autoCapitalize="off"
                  spellCheck="false"
                  inputMode="decimal"
                  name="inline_withdraw_2"
                />
              </div>

              <div className="piggy-input-group">
                <label>Motivo o nota (opcional)</label>
                <input
                  type="text"
                  maxLength={40}
                  placeholder="Motivo (opcional)"
                  value={withdrawNote}
                  onChange={(e) => setWithdrawNote(e.target.value.slice(0, 40))}
                  autoComplete="off"
                  autoCorrect="off"
                  autoCapitalize="off"
                  spellCheck="false"
                  name="inline_note_2"
                />
              </div>

              <div className="piggy-inline-actions" style={{ flexDirection: 'column', gap: '8px' }}>
                <div style={{ display: 'flex', gap: '8px', width: '100%' }}>
                  <button type="button" className="piggy-btn-cancel" style={{ flex: 1 }} onClick={() => setActiveAction('idle')}>
                    Cancelar
                  </button>
                  <button type="submit" className="piggy-btn-confirm" style={{ flex: 1 }} disabled={depositMutation.isPending || !withdrawAmount || parseFloat(withdrawAmount) <= 0}>
                    Retirar
                  </button>
                </div>
                <button
                  type="button"
                  className="piggy-btn-danger"
                  disabled={resetMutation.isPending || parseFloat(piggyData.total_balance) <= 0}
                  onClick={() => {
                    if (confirm('¿Deseas vaciar completamente tu alcancía?')) {
                      resetMutation.mutate();
                    }
                  }}
                >
                  Vaciar Alcancía por Completo
                </button>
              </div>
            </form>
          </div>
        )}
      </Card>

      {/* MODAL 3: Mostrar Mis Ahorros (Calendario + Resumen) */}
      {showCalendarModal && (
        <Dialog open={showCalendarModal} onOpenChange={(_, data) => setShowCalendarModal(data.open)}>
          <DialogSurface className="piggy-modal-surface calendar-modal-surface">
            <DialogBody>
              <DialogTitle className="piggy-modal-title">Mis Ahorros y Calendario</DialogTitle>
              <DialogContent className="piggy-modal-content">
                {/* Month Picker */}
                <div className="calendar-header-controls">
                  <Field label="Mes de consulta">
                    <Input
                      type="month"
                      value={selectedMonth}
                      onChange={(e) => setSelectedMonth(e.target.value)}
                    />
                  </Field>
                </div>

                {/* Monthly Summary Cards */}
                <div className="piggy-monthly-summary-grid">
                  <div className="summary-stat-box">
                    <Caption1>Ahorrado este mes</Caption1>
                    <strong className="stat-saved">{piggyData.monthly_summary.formatted_saved}</strong>
                  </div>
                  <div className="summary-stat-box">
                    <Caption1>Depósitos</Caption1>
                    <strong>{piggyData.monthly_summary.deposit_count} veces</strong>
                  </div>
                  <div className="summary-stat-box">
                    <Caption1>Retirado</Caption1>
                    <strong className="stat-withdrawn">{piggyData.monthly_summary.formatted_withdrawn}</strong>
                  </div>
                </div>

                {/* Grid Calendar */}
                <div className="piggy-calendar-grid">
                  {['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'].map((dayName) => (
                    <div key={dayName} className="calendar-day-header">
                      {dayName}
                    </div>
                  ))}

                  {/* Empty offset cells before day 1 */}
                  {Array.from({ length: firstDayOfWeek }).map((_, idx) => (
                    <div key={`offset-${idx}`} className="calendar-day-cell empty" />
                  ))}

                  {/* Days of month */}
                  {Array.from({ length: daysInMonth }).map((_, idx) => {
                    const dayNum = idx + 1;
                    const dateStr = `${selectedMonth}-${String(dayNum).padStart(2, '0')}`;
                    const dayData = calendarMap.get(dateStr);
                    const hasData = Boolean(dayData);

                    return (
                      <div
                        key={dateStr}
                        className={`calendar-day-cell ${hasData ? 'has-savings' : ''}`}
                        onClick={() => hasData && setActiveDayDetails(dayData ?? null)}
                      >
                        <span className="day-number">{dayNum}</span>
                        {hasData && (
                          <div className="day-savings-badge">
                            RD$ {parseFloat(dayData!.total_amount).toFixed(0)}
                          </div>
                        )}
                      </div>
                    );
                  })}
                </div>

                {/* Active Day Detail Box */}
                {activeDayDetails && (
                  <div className="active-day-detail-box">
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '8px' }}>
                      <strong>Ahorros del {activeDayDetails.date}</strong>
                      <Button appearance="subtle" size="small" onClick={() => setActiveDayDetails(null)}>Cerrar</Button>
                    </div>
                    <div style={{ display: 'grid', gap: '6px' }}>
                      {activeDayDetails.items.map((item) => (
                        <div key={item.id} className="day-detail-row">
                          <span>{item.time} {item.note ? `· ${item.note}` : ''}</span>
                          <strong style={{ color: item.type === 'deposit' ? 'var(--primary)' : '#ff4d4f' }}>
                            {item.type === 'deposit' ? '+' : '-'}{item.formatted_amount}
                          </strong>
                        </div>
                      ))}
                    </div>
                  </div>
                )}
              </DialogContent>
              <DialogActions>
                <Button appearance="primary" onClick={() => setShowCalendarModal(false)}>
                  Entendido
                </Button>
              </DialogActions>
            </DialogBody>
          </DialogSurface>
        </Dialog>
      )}
    </div>
  );
}
