import { createContext, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { api } from '../api';
import { ApiRequestError } from '../api/client';
import type { User } from '../types';

interface AuthValue {
  user: User | null;
  ready: boolean;
  sessionRestoreFailed: boolean;
  login: (email: string, password: string) => Promise<void>;
  logout: () => void;
  retrySessionRestore: () => void;
  createAccount: (name: string, email: string, password: string) => Promise<void>;
}

const AuthContext = createContext<AuthValue | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [ready, setReady] = useState(false);
  const [sessionRestoreFailed, setSessionRestoreFailed] = useState(false);
  const [restoreAttempt, setRestoreAttempt] = useState(0);

  useEffect(() => {
    let active = true;
    api.getCurrentUser().then((signedInUser) => {
      if (active) {
        setUser(signedInUser);
        setSessionRestoreFailed(false);
      }
    }).catch((error: unknown) => {
      if (!active) return;
      setUser(null);
      setSessionRestoreFailed(!(error instanceof ApiRequestError && error.status === 401));
    }).finally(() => {
      if (active) setReady(true);
    });
    return () => { active = false; };
  }, [restoreAttempt]);

  const value = useMemo<AuthValue>(() => ({
    user,
    ready,
    sessionRestoreFailed,
    login: async (email, password) => setUser(await api.login({ email, password })),
    logout: () => { void api.logout().catch(() => undefined).finally(() => setUser(null)); },
    retrySessionRestore: () => {
      setSessionRestoreFailed(false);
      setReady(false);
      setRestoreAttempt((attempt) => attempt + 1);
    },
    createAccount: async (name, email, password) => { await api.createAccount({ name, email, password }); },
  }), [user, ready, sessionRestoreFailed]);
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthValue {
  const value = useContext(AuthContext);
  if (!value) throw new Error('useAuth must be used within AuthProvider');
  return value;
}
