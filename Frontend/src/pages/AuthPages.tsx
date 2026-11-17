import { zodResolver } from '@hookform/resolvers/zod';
import { Button, Card, Field, Input, MessageBar, MessageBarBody, Subtitle1 } from '@fluentui/react-components';
import { useState, type ReactNode } from 'react';
import { useForm } from 'react-hook-form';
import { Link, useNavigate } from 'react-router-dom';
import { z } from 'zod';
import { useAuth } from '../auth/AuthContext';

const loginSchema = z.object({
  email: z.string().email('Ingresa un correo electrónico válido'),
  password: z.string().min(8, 'La contraseña debe tener al menos 8 caracteres'),
});

const createSchema = z.object({
  name: z.string().min(2, 'El nombre debe tener al menos 2 caracteres'),
  email: z.string().email('Ingresa un correo electrónico válido'),
  password: z.string().min(12, 'La contraseña debe tener al menos 12 caracteres'),
});

export function LoginPage() {
  const { login } = useAuth();
  const navigate = useNavigate();
  const [error, setError] = useState('');
  const form = useForm<{ email: string; password: string }>({
    resolver: zodResolver(loginSchema),
    defaultValues: { email: '', password: '' },
  });

  const submit = form.handleSubmit(async (values) => {
    try {
      await login(values.email, values.password);
      navigate('/');
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : 'No pudimos iniciar tu sesión. Verifica tu correo y contraseña.');
    }
  });

  return <AuthCard title="Bienvenido de nuevo" subtitle="Inicia sesión en tu espacio privado de finanzas">
    {error && <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar>}
    <form onSubmit={submit} className="auth-form">
      <Field label="Correo electrónico" validationMessage={form.formState.errors.email?.message}>
        <Input type="email" autoComplete="email" {...form.register('email')} />
      </Field>
      <Field label="Contraseña" validationMessage={form.formState.errors.password?.message}>
        <Input type="password" autoComplete="current-password" {...form.register('password')} />
      </Field>
      <Button appearance="primary" type="submit" disabled={form.formState.isSubmitting}>
        Iniciar sesión
      </Button>
    </form>
    <Link className="create-account-link" to="/create-account">Crear cuenta nueva</Link>
  </AuthCard>;
}

export function CreateAccountPage() {
  const { createAccount } = useAuth();
  const navigate = useNavigate();
  const [error, setError] = useState('');
  const [complete, setComplete] = useState(false);
  const form = useForm<{ name: string; email: string; password: string }>({
    resolver: zodResolver(createSchema),
    defaultValues: { name: '', email: '', password: '' },
  });

  const submit = form.handleSubmit(async (values) => {
    try {
      await createAccount(values.name, values.email, values.password);
      setComplete(true);
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : 'Es posible que ya exista una cuenta con este correo. Revisa la dirección e intenta de nuevo.');
    }
  });

  return <AuthCard title="Crea tu cuenta" subtitle="Tu información financiera se mantiene privada y segura">
    {error && <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar>}
    {complete ? <div className="auth-form">
      <p>Tu cuenta está lista. Inicia sesión para continuar.</p>
      <Button appearance="primary" onClick={() => navigate('/login')}>Continuar al inicio de sesión</Button>
    </div> :
      <form onSubmit={submit} className="auth-form">
        <Field label="Nombre completo" validationMessage={form.formState.errors.name?.message}>
          <Input autoComplete="name" {...form.register('name')} />
        </Field>
        <Field label="Correo electrónico" validationMessage={form.formState.errors.email?.message}>
          <Input type="email" autoComplete="email" {...form.register('email')} />
        </Field>
        <Field label="Contraseña (mínimo 12 caracteres)" validationMessage={form.formState.errors.password?.message}>
          <Input type="password" autoComplete="new-password" {...form.register('password')} />
        </Field>
        <Button appearance="primary" type="submit" disabled={form.formState.isSubmitting}>
          Crear cuenta
        </Button>
      </form>}
    <Link className="create-account-link" to="/login">Volver a inicio de sesión</Link>
  </AuthCard>;
}

import { useAppTheme } from '../theme/ThemeContext';

function AuthCard({ title, subtitle, children }: { title: string; subtitle: string; children: ReactNode }) {
  const { isDark } = useAppTheme();
  return <main className={isDark ? 'auth-screen dark' : 'auth-screen'}>
    <Card className="auth-card">
      <div className="brand auth-brand">
        <span className="brand-mark">FC</span>
        <span>Finanzas Clara</span>
      </div>
      <Subtitle1>{title}</Subtitle1>
      <p>{subtitle}</p>
      {children}
    </Card>
  </main>;
}
