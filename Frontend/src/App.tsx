import { useEffect, useMemo, useState } from 'react';
import { FluentProvider, createDarkTheme, createLightTheme, type BrandVariants } from '@fluentui/react-components';
import { Navigate, Route, Routes } from 'react-router-dom';
import { AuthProvider, useAuth } from './auth/AuthContext';
import { AppShell } from './components/AppShell';
import { FullPageLoader } from './components/FullPageLoader';
import {
  SimpleExpensesPage,
} from './pages/SimpleFinancePages';
import { CreateAccountPage, LoginPage } from './pages/AuthPages';
import { ThemeContext } from './theme/ThemeContext';
import { PiggyBankPage } from './pages/PiggyBankPage';
import { IncomePage } from './pages/IncomePage';
import { DashboardPage } from './pages/DashboardPage';
import { RecordsPage } from './pages/RecordsPage';
import { SettingsPage } from './pages/SettingsPage';
import { ReportsPage } from './pages/ReportsPage';
import { InterestCutPage } from './pages/InterestCutPage';

const brandRamp: BrandVariants = {
  10: '#050b17', 20: '#101c32', 30: '#172c4d', 40: '#1b3b69', 50: '#1d4789', 60: '#1f4b99',
  70: '#315da8', 80: '#456fb5', 90: '#5981c1', 100: '#6f94cd', 110: '#85a7d8',
  120: '#9bb9e2', 130: '#b2cbea', 140: '#c9dcf2', 150: '#e1edf9', 160: '#f5f9fe',
};

export default function App() {
  const [appearance, setAppearanceState] = useState<'light' | 'dark' | 'system'>(() => {
    const saved = localStorage.getItem('ghost-finance-appearance');
    return saved === 'light' || saved === 'dark' || saved === 'system' ? saved : 'dark';
  });
  const [systemDark, setSystemDark] = useState(() => window.matchMedia('(prefers-color-scheme: dark)').matches);

  useEffect(() => {
    const media = window.matchMedia('(prefers-color-scheme: dark)');
    const listener = () => setSystemDark(media.matches);
    media.addEventListener('change', listener);
    return () => media.removeEventListener('change', listener);
  }, []);

  const isDark = appearance === 'dark' || (appearance === 'system' && systemDark);
  const theme = useMemo(() => isDark ? createDarkTheme(brandRamp) : createLightTheme(brandRamp), [isDark]);
  const setAppearance = (next: 'light' | 'dark' | 'system') => {
    setAppearanceState(next);
    localStorage.setItem('ghost-finance-appearance', next);
  };
  const themeValue = useMemo(() => ({ isDark, setAppearance }), [isDark]);

  useEffect(() => {
    document.documentElement.dataset.theme = isDark ? 'dark' : 'light';
  }, [isDark]);

  return <FluentProvider theme={theme} className="fluent-root">
    <ThemeContext.Provider value={themeValue}><AuthProvider><AuthRoutes /></AuthProvider></ThemeContext.Provider>
  </FluentProvider>;
}

function AuthRoutes() {
  const { user, ready, sessionRestoreFailed, retrySessionRestore } = useAuth();

  if (!ready) {
    return <FullPageLoader message="Cargando tu espacio de trabajo seguro…" />;
  }

  if (sessionRestoreFailed) {
    return (
      <FullPageLoader
        error="No se pudo verificar tu sesión guardada. Tu inicio de sesión no se ha borrado."
        onRetry={retrySessionRestore}
      />
    );
  }

  return <Routes>
    <Route path="/login" element={user ? <Navigate to="/" replace /> : <LoginPage />} />
    <Route path="/create-account" element={user ? <Navigate to="/" replace /> : <CreateAccountPage />} />
    <Route element={user ? <AppShell /> : <Navigate to="/login" replace />}>
      <Route path="/" element={<DashboardPage />} />
      <Route path="/chat" element={<Navigate to="/" replace />} />
      <Route path="/income" element={<IncomePage />} />
      <Route path="/expenses" element={<SimpleExpensesPage />} />
      <Route path="/transfers" element={<RecordsPage kind="transfers" />} />
      <Route path="/payments" element={<RecordsPage kind="commitments" />} />
      <Route path="/commitments" element={<Navigate to="/payments" replace />} />
      <Route path="/debts" element={<Navigate to="/payments" replace />} />
      <Route path="/calendar" element={<Navigate to="/payments?view=calendar" replace />} />
      <Route path="/transactions" element={<RecordsPage kind="transactions" />} />
      <Route path="/planning" element={<Navigate to="/" replace />} />
      <Route path="/piggy-bank" element={<PiggyBankPage />} />
      <Route path="/accounts" element={<RecordsPage kind="accounts" />} />
      <Route path="/reports" element={<ReportsPage />} />
      <Route path="/interests" element={<InterestCutPage />} />
      <Route path="/settings" element={<SettingsPage />} />
    </Route>
    <Route path="*" element={<Navigate to="/" replace />} />
  </Routes>;
}
