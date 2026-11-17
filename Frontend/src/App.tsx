import { useMemo, useState } from 'react';
import { Button, FluentProvider, createDarkTheme, createLightTheme, type BrandVariants } from '@fluentui/react-components';
import { Navigate, Route, Routes } from 'react-router-dom';
import { AuthProvider, useAuth } from './auth/AuthContext';
import { AppShell } from './components/AppShell';
import { DashboardPage } from './pages/DashboardPage';
import { RecordsPage } from './pages/RecordsPage';
import { ReportsPage } from './pages/ReportsPage';
import { SettingsPage } from './pages/SettingsPage';
import { CreateAccountPage, LoginPage } from './pages/AuthPages';
import { ThemeContext } from './theme/ThemeContext';

const brandRamp: BrandVariants = {
  10: '#050b17', 20: '#101c32', 30: '#172c4d', 40: '#1b3b69', 50: '#1d4789', 60: '#1f4b99',
  70: '#315da8', 80: '#456fb5', 90: '#5981c1', 100: '#6f94cd', 110: '#85a7d8',
  120: '#9bb9e2', 130: '#b2cbea', 140: '#c9dcf2', 150: '#e1edf9', 160: '#f5f9fe',
};

export default function App() {
  const lightTheme = useMemo(() => createLightTheme(brandRamp), []);
  const darkTheme = useMemo(() => createDarkTheme(brandRamp), []);
  const [isDark, setIsDark] = useState(() => {
    const saved = globalThis.localStorage.getItem('finance-app.appearance');
    return saved === 'dark' || (saved === 'system' && globalThis.matchMedia('(prefers-color-scheme: dark)').matches);
  });
  const themeValue = useMemo(() => ({
    isDark,
    setAppearance: (appearance: 'light' | 'dark' | 'system') => {
      globalThis.localStorage.setItem('finance-app.appearance', appearance);
      setIsDark(appearance === 'dark' || (appearance === 'system' && globalThis.matchMedia('(prefers-color-scheme: dark)').matches));
    },
  }), [isDark]);
  return <FluentProvider theme={isDark ? darkTheme : lightTheme} className="fluent-root">
    <ThemeContext.Provider value={themeValue}><AuthProvider><AuthRoutes /></AuthProvider></ThemeContext.Provider>
  </FluentProvider>;
}

function AuthRoutes() {
  const { user, ready, sessionRestoreFailed, retrySessionRestore } = useAuth();
  if (!ready) return <div className="auth-loading">Cargando tu espacio de trabajo seguro…</div>;
  if (sessionRestoreFailed) {
    return <div className="auth-loading" role="alert">
      <p>No se pudo verificar tu sesión guardada. Tu inicio de sesión no se ha borrado.</p>
      <Button appearance="primary" onClick={retrySessionRestore}>Reintentar</Button>
    </div>;
  }
  return <Routes>
    <Route path="/login" element={user ? <Navigate to="/" replace /> : <LoginPage />} />
    <Route path="/create-account" element={user ? <Navigate to="/" replace /> : <CreateAccountPage />} />
    <Route element={user ? <AppShell /> : <Navigate to="/login" replace />}>
      <Route path="/" element={<DashboardPage />} />
      <Route path="/income" element={<RecordsPage kind="income" />} />
      <Route path="/expenses" element={<RecordsPage kind="expenses" />} />
      <Route path="/transfers" element={<RecordsPage kind="transfers" />} />
      <Route path="/commitments" element={<RecordsPage kind="commitments" />} />
      <Route path="/debts" element={<Navigate to="/commitments" replace />} />
      <Route path="/calendar" element={<RecordsPage kind="calendar" />} />
      <Route path="/transactions" element={<RecordsPage kind="transactions" />} />
      <Route path="/accounts" element={<RecordsPage kind="accounts" />} />
      <Route path="/reports" element={<ReportsPage />} />
      <Route path="/settings" element={<SettingsPage />} />
    </Route>
    <Route path="*" element={<Navigate to="/" replace />} />
  </Routes>;
}
