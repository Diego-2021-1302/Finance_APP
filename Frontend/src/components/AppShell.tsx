import { Link, Outlet, useLocation, useNavigate } from 'react-router-dom';
import {
  Avatar, Button, Divider, TabList, Tab, Toolbar, ToolbarButton, Tooltip, Body1,
} from '@fluentui/react-components';
import {
  HomeRegular, MoneyRegular, CalendarRegular, ArrowSwapRegular,
  DataBarVerticalRegular, SettingsRegular, SearchRegular, AlertRegular,
  WalletRegular, NavigationRegular, DismissRegular, ChatRegular,
} from '@fluentui/react-icons';
import { useState, useEffect } from 'react';
import { useAuth } from '../auth/AuthContext';
import { useAppTheme } from '../theme/ThemeContext';

const navItems = [
  { to: '/', label: 'Tablero', icon: <HomeRegular /> },
  { to: '/chat', label: 'Asistente IA', icon: <ChatRegular /> },
  { to: '/accounts', label: 'Cuentas', icon: <WalletRegular /> },
  { to: '/income', label: 'Ingresos', icon: <MoneyRegular /> },
  { to: '/expenses', label: 'Gastos Directos', icon: <MoneyRegular /> },
  { to: '/transfers', label: 'Transferencias', icon: <ArrowSwapRegular /> },
  { to: '/commitments', label: 'Compromisos', icon: <CalendarRegular /> },
  { to: '/calendar', label: 'Calendario', icon: <CalendarRegular /> },
  { to: '/transactions', label: 'Transacciones', icon: <ArrowSwapRegular /> },
  { to: '/reports', label: 'Reportes', icon: <DataBarVerticalRegular /> },
  { to: '/settings', label: 'Configuración', icon: <SettingsRegular /> },
];

export function AppShell() {
  const { user, logout } = useAuth();
  const { isDark } = useAppTheme();
  const location = useLocation();
  const navigate = useNavigate();
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false);

  useEffect(() => {
    if (mobileMenuOpen) {
      document.body.style.overflow = 'hidden';
    } else {
      document.body.style.overflow = '';
    }
    return () => {
      document.body.style.overflow = '';
    };
  }, [mobileMenuOpen]);

  const pageTitle = navItems.find((item) => item.to === location.pathname)?.label ?? 'Espacio de trabajo financiero';

  const handleNavClick = (to: string) => {
    navigate(to);
    setMobileMenuOpen(false);
  };

  return <div className={isDark ? 'app-frame dark' : 'app-frame'}>
    <header className="topbar">
      <div className="brand-group">
        <ToolbarButton
          className="mobile-menu-toggle"
          aria-label="Abrir menú"
          icon={mobileMenuOpen ? <DismissRegular /> : <NavigationRegular />}
          onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
        />
        <ToolbarButton
          className="desktop-only"
          aria-label="Alternar menú lateral"
          title={sidebarCollapsed ? 'Expandir menú' : 'Contraer menú'}
          icon={<NavigationRegular />}
          onClick={() => setSidebarCollapsed(!sidebarCollapsed)}
          style={{ marginRight: '6px' }}
        />
        <Link to="/" className="brand">
          <img src="/logo.png" alt="Ghost Finance" style={{ width: '38px', height: '38px', borderRadius: '50%', objectFit: 'cover', boxShadow: '0 0 14px rgba(0, 210, 255, 0.6)', border: '1px solid rgba(0, 210, 255, 0.4)' }} />
          <span>GHOST FINANCE</span>
        </Link>
      </div>
      <Toolbar aria-label="Barra de herramientas de la aplicación">
        <Tooltip content="Buscar transacciones" relationship="label">
          <ToolbarButton aria-label="Buscar transacciones" icon={<SearchRegular />} onClick={() => navigate('/transactions')} />
        </Tooltip>
        <Tooltip content="Próximas fechas" relationship="label">
          <ToolbarButton aria-label="Ver próximas fechas" icon={<AlertRegular />} onClick={() => navigate('/calendar')} />
        </Tooltip>
        <Tooltip content="Preferencias de apariencia" relationship="label">
          <ToolbarButton aria-label="Preferencias de apariencia" icon={<SettingsRegular />} onClick={() => navigate('/settings')} />
        </Tooltip>
        <Divider vertical className="desktop-only" />
        <Avatar name={user?.name ?? 'Usuario'} size={32} color="brand" className="desktop-only" />
        <Button appearance="subtle" onClick={logout} className="desktop-only">Cerrar sesión</Button>
      </Toolbar>
    </header>

    {mobileMenuOpen && (
      <div className="mobile-overlay" onClick={() => setMobileMenuOpen(false)} />
    )}

    <div className="workspace">
      <aside className={`sidebar ${sidebarCollapsed ? 'collapsed' : ''} ${mobileMenuOpen ? 'mobile-open' : ''}`}>
        <Body1 className="nav-caption">Espacio de trabajo</Body1>
        <TabList vertical selectedValue={location.pathname} aria-label="Navegación principal">
          {navItems.map((item) => (
            <Tab key={item.to} value={item.to} icon={item.icon} title={sidebarCollapsed ? item.label : undefined} onClick={() => handleNavClick(item.to)}>
              {item.label}
            </Tab>
          ))}
        </TabList>
        <Divider />
        <CardNote />
        <div className="mobile-user-row">
          <Avatar name={user?.name ?? 'Usuario'} size={32} color="brand" />
          <div className="mobile-user-info">
            <strong>{user?.name ?? 'Usuario'}</strong>
            <span>{user?.email}</span>
          </div>
          <Button appearance="subtle" size="small" onClick={logout}>Salir</Button>
        </div>
        <div className="sidebar-footer">GHOST FINANCE · DOP</div>
      </aside>

      <main className="page-main">
        <div className="page-heading">
          <div>
            <div className="eyebrow">FINANZAS PERSONALES</div>
            <h1>{pageTitle}</h1>
          </div>
          <div className="period-pill">
            {new Intl.DateTimeFormat('es-DO', { month: 'long', year: 'numeric' }).format(new Date())}
          </div>
        </div>
        <Outlet />
      </main>
    </div>
  </div>;
}

function CardNote() {
  return <div className="sidebar-note">
    <strong>Resumen mensual</strong>
    <span>Mantén tu flujo de caja claro y bajo control.</span>
  </div>;
}
