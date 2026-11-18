import { zodResolver } from '@hookform/resolvers/zod';
import { Button, Card, Field, Input, MessageBar, MessageBarBody } from '@fluentui/react-components';
import { useState, type ReactNode } from 'react';
import { useForm } from 'react-hook-form';
import { Link, useNavigate } from 'react-router-dom';
import { z } from 'zod';
import { useAuth } from '../auth/AuthContext';
import { useAppTheme } from '../theme/ThemeContext';

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

  return <AuthCard>
    {error && <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar>}
    <form onSubmit={submit} className="auth-form">
      <Field label="Correo electrónico" validationMessage={form.formState.errors.email?.message}>
        <Input type="email" autoComplete="email" {...form.register('email')} placeholder="tucorreo@dominio.com" />
      </Field>
      <Field label="Contraseña" validationMessage={form.formState.errors.password?.message}>
        <Input type="password" autoComplete="current-password" {...form.register('password')} placeholder="••••••••" />
      </Field>
      <Button appearance="primary" type="submit" disabled={form.formState.isSubmitting}>
        {form.formState.isSubmitting ? 'Iniciando sesión…' : 'Iniciar sesión'}
      </Button>
    </form>
    <Link className="create-account-link" to="/create-account">¿No tienes cuenta? Crear cuenta nueva</Link>
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

  return <AuthCard>
    {error && <MessageBar intent="error"><MessageBarBody>{error}</MessageBarBody></MessageBar>}
    {complete ? <div className="auth-form" style={{ textAlign: 'center', padding: '20px 0' }}>
      <p style={{ margin: '0 0 16px 0', fontSize: '14px', color: 'var(--text)' }}>Tu cuenta está lista. Inicia sesión para continuar.</p>
      <Button appearance="primary" onClick={() => navigate('/login')} style={{ width: '100%' }}>Continuar al inicio de sesión</Button>
    </div> :
      <form onSubmit={submit} className="auth-form">
        <Field label="Nombre completo" validationMessage={form.formState.errors.name?.message}>
          <Input autoComplete="name" {...form.register('name')} placeholder="Tu nombre" />
        </Field>
        <Field label="Correo electrónico" validationMessage={form.formState.errors.email?.message}>
          <Input type="email" autoComplete="email" {...form.register('email')} placeholder="tucorreo@dominio.com" />
        </Field>
        <Field label="Contraseña (mínimo 12 caracteres)" validationMessage={form.formState.errors.password?.message}>
          <Input type="password" autoComplete="new-password" {...form.register('password')} placeholder="••••••••••••" />
        </Field>
        <Button appearance="primary" type="submit" disabled={form.formState.isSubmitting}>
          {form.formState.isSubmitting ? 'Creando cuenta…' : 'Crear cuenta'}
        </Button>
      </form>}
    <Link className="create-account-link" to="/login">¿Ya tienes cuenta? Iniciar sesión</Link>
  </AuthCard>;
}

function AuthCard({ children }: { children: ReactNode }) {
  const { isDark } = useAppTheme();
  return <main className={isDark ? 'auth-screen dark' : 'auth-screen'}>
    <Card className="auth-card" style={{ padding: '44px 38px', textAlign: 'center' }}>
      <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: '14px', marginBottom: '22px' }}>
        <img
          src="/logo.png"
          alt="Ghost Finance"
          style={{
            width: '84px',
            height: '84px',
            borderRadius: '50%',
            objectFit: 'cover',
            boxShadow: '0 0 35px rgba(0, 210, 255, 0.85)',
            border: '2px solid rgba(0, 210, 255, 0.6)',
          }}
        />
        <span
          style={{
            fontSize: '22px',
            fontWeight: 850,
            letterSpacing: '-0.03em',
            background: 'linear-gradient(135deg, #00d2ff 0%, #38bdf8 100%)',
            WebkitBackgroundClip: 'text',
            WebkitTextFillColor: 'transparent',
            textTransform: 'uppercase',
          }}
        >
          GHOST FINANCE
        </span>
      </div>
      {children}
    </Card>
  </main>;
}
