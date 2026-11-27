import type { ReactElement } from 'react';
import { Link, Outlet, useLocation, useNavigate } from 'react-router-dom';
import {
  Avatar, Button, Divider, TabList, Tab, ToolbarButton, Body1, Badge,
} from '@fluentui/react-components';
import {
  HomeRegular, MoneyRegular, CalendarRegular,
  WalletRegular, NavigationRegular, PersonMoneyRegular, SettingsRegular, CalculatorRegular,
} from '@fluentui/react-icons';
import { useState, useEffect } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '../api';
import { useAuth } from '../auth/AuthContext';
import { useAppTheme } from '../theme/ThemeContext';
import { PWAInstallPrompt } from './PWAInstallPrompt';
import { MobileBottomNav } from './MobileBottomNav';

interface NavSection {
  title: string;
  items: Array<{
    to: string;
    label: string;
    icon: ReactElement;
    badge?: string;
  }>;
}

const navSections: NavSection[] = [
  { title: 'Principal', items: [
    { to: '/', label: 'Inicio', icon: <HomeRegular /> },
    { to: '/income', label: 'Ingresos', icon: <PersonMoneyRegular /> },
    { to: '/expenses', label: 'Gastos', icon: <MoneyRegular /> },
    { to: '/payments', label: 'Pagos', icon: <CalendarRegular /> },
    { to: '/accounts', label: 'Cuentas', icon: <WalletRegular /> },
  ] },
  { title: 'Herramientas', items: [
    { to: '/piggy-bank', label: 'Ahorros', icon: <MoneyRegular /> },
    { to: '/interests', label: 'Intereses', icon: <CalculatorRegular /> },
    { to: '/settings', label: 'Configuración', icon: <SettingsRegular /> },
  ] },
];

const allNavItems = navSections.flatMap((section) => section.items);

export function AppShell() {
  const { user, logout } = useAuth();
  const preferences = useQuery({ queryKey: ['settings'], queryFn: api.getSettings });
  const { isDark } = useAppTheme();
  const location = useLocation();
  const navigate = useNavigate();
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false);

  useEffect(() => {
    const previousOverflow = document.body.style.overflow;
    if (mobileMenuOpen) {
      document.body.style.overflow = 'hidden';
    }
    return () => {
      document.body.style.overflow = previousOverflow;
    };
  }, [mobileMenuOpen]);

  const activeNavItem = allNavItems.find((item) => item.to === location.pathname);
  const pageTitle = activeNavItem?.label ?? 'Espacio Financiero';

  const handleNavClick = (to: string) => {
    navigate(to);
    setMobileMenuOpen(false);
  };

  const currentDateFormatted = new Intl.DateTimeFormat('es-DO', { month: 'long', year: 'numeric' }).format(new Date());

  return (
    <div className={isDark ? 'app-frame dark' : 'app-frame'}>
      <PWAInstallPrompt />

      {/* Top Header Navigation */}
      <header className="topbar">
        <div className="brand-group">
          <ToolbarButton
            className="desktop-only"
            aria-label="Alternar menú lateral"
            title={sidebarCollapsed ? 'Expandir menú' : 'Contraer menú'}
            icon={<NavigationRegular />}
            onClick={() => setSidebarCollapsed(!sidebarCollapsed)}
            style={{ marginRight: '6px' }}
          />
          <Link to="/" className="brand">
            <img src="/logo.png" alt="Ghost Finance" className="brand-logo-img" />
            <div className="brand-text-block">
              <span className="brand-name">GHOST FINANCE</span>
            </div>
          </Link>
          <Divider vertical className="desktop-only" style={{ height: '20px', margin: '0 12px', borderColor: 'rgba(0,210,255,0.2)' }} />
          <h1 className="page-title desktop-only" style={{ margin: 0, fontSize: '14.5px', fontWeight: 700, color: 'var(--text)', letterSpacing: '-0.01em', whiteSpace: 'nowrap' }}>
            {pageTitle}
          </h1>
        </div>

        {/* Topbar Right Group */}
        <div className="topbar-right-group" style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
          <div className="period-pill desktop-only" style={{ textTransform: 'capitalize' }}>
            {currentDateFormatted}
          </div>
          <div className="user-profile-widget desktop-only">
            <Avatar name={user?.name ?? 'Usuario'} image={preferences.data?.profilePhotoUrl ? { src: preferences.data.profilePhotoUrl } : undefined} size={32} color="brand" />
            <div className="user-profile-details">
              <span className="user-profile-name">{user?.name ?? 'Usuario'}</span>
              <span className="user-profile-currency">DOP · RD$</span>
            </div>
            <Button appearance="subtle" size="small" onClick={logout}>Salir</Button>
          </div>
        </div>
      </header>

      {/* Mobile Subheader Bar */}
      <div className="mobile-subheader-bar desktop-hidden">
        <h1 className="page-title">{pageTitle}</h1>
        <div className="period-pill" style={{ textTransform: 'capitalize' }}>
          {currentDateFormatted}
        </div>
      </div>

      {/* Workspace Body */}
      <div className="workspace">
        <aside className={`sidebar ${sidebarCollapsed ? 'collapsed' : ''}`}>
          <TabList vertical selectedValue={location.pathname} aria-label="Navegación principal">
            {navSections.map((section) => (
              <div key={section.title} className="nav-section-group">
                {!sidebarCollapsed && <Body1 className="nav-caption">{section.title}</Body1>}
                {section.items.map((item) => (
                  <Tab
                    key={item.to}
                    value={item.to}
                    icon={item.icon}
                    title={sidebarCollapsed ? item.label : undefined}
                    onClick={() => handleNavClick(item.to)}
                  >
                    <div className="nav-tab-label-container">
                      <span>{item.label}</span>
                      {item.badge && !sidebarCollapsed && (
                        <Badge appearance="tint" color="brand" size="small" className="nav-tab-badge">
                          {item.badge}
                        </Badge>
                      )}
                    </div>
                  </Tab>
                ))}
              </div>
            ))}
          </TabList>

          <Divider style={{ margin: '16px 0' }} />

          {!sidebarCollapsed && <CardNote />}

          <div className="sidebar-footer">
            <span>GHOST FINANCE · V3.0</span>
          </div>
        </aside>

        <main className="page-main">
          {/* Mobile-only page context banner */}
          <div className="mobile-page-banner desktop-hidden">
            <div className="mobile-banner-title-group">
            </div>
          </div>
          <Outlet />
        </main>
      </div>

      <MobileBottomNav
        isMoreOpen={mobileMenuOpen}
        onToggleMobileMenu={() => setMobileMenuOpen((open) => !open)}
        onCloseMobileMenu={() => setMobileMenuOpen(false)}
        onNavigate={handleNavClick}
        onLogout={logout}
        moreItems={allNavItems.filter((item) => !['/', '/payments', '/accounts', '/expenses', '/income'].includes(item.to))}
        userName={user?.name ?? 'Usuario'}
        userEmail={user?.email}
      />
    </div>
  );
}

function CardNote() {
  return (
    <div className="sidebar-note">
      <strong>Resumen en vivo</strong>
      <span>Control atómico de saldo real, deudas y presupuestos.</span>
    </div>
  );
}
