import type { ReactNode } from 'react';
import { useLocation } from 'react-router-dom';
import {
  Button, Dialog, DialogBody, DialogContent, DialogSurface, DialogTitle, Divider,
} from '@fluentui/react-components';
import {
  HomeRegular, HomeFilled,
  CalendarRegular, CalendarFilled,
  MoneyRegular,
  NavigationRegular,
  DismissRegular,
} from '@fluentui/react-icons';

interface MobileNavItem {
  to: string;
  label: string;
  icon: ReactNode;
}

interface MobileBottomNavProps {
  isMoreOpen: boolean;
  onToggleMobileMenu: () => void;
  onCloseMobileMenu: () => void;
  onNavigate: (to: string) => void;
  onLogout: () => void;
  moreItems: MobileNavItem[];
  userName: string;
  userEmail?: string;
}

export function MobileBottomNav({
  isMoreOpen,
  onToggleMobileMenu,
  onCloseMobileMenu,
  onNavigate,
  onLogout,
  moreItems,
  userName,
  userEmail,
}: MobileBottomNavProps) {
  const location = useLocation();

  const navTabs = [
    {
      to: '/',
      label: 'Inicio',
      icon: location.pathname === '/' ? <HomeFilled /> : <HomeRegular />,
    },
    {
      to: '/income',
      label: 'Ingresos',
      icon: <MoneyRegular />,
    },
    {
      to: '/expenses',
      label: 'Gastos',
      icon: <MoneyRegular />,
    },
    {
      to: '/accounts',
      label: 'Cuentas',
      icon: <MoneyRegular />,
    },
    {
      to: '/payments',
      label: 'Pagos',
      icon: location.pathname === '/payments' ? <CalendarFilled /> : <CalendarRegular />,
    },
  ];

  const isActive = (to: string) => to === '/'
    ? location.pathname === '/'
    : location.pathname === to || location.pathname.startsWith(`${to}/`);

  return (
    <>
      <nav className="mobile-bottom-nav" aria-label="Navegación principal móvil">
        {navTabs.map((tab) => {
          const active = isActive(tab.to);
          return (
            <button
              key={tab.to}
              type="button"
              className={`mobile-nav-btn ${active ? 'active' : ''}`}
              onClick={() => onNavigate(tab.to)}
              aria-current={active ? 'page' : undefined}
            >
              <div className="mobile-nav-icon">{tab.icon}</div>
              <span className="mobile-nav-label">{tab.label}</span>
            </button>
          );
        })}

        <button
          type="button"
          className={`mobile-nav-btn ${moreItems.some((item) => isActive(item.to)) ? 'active' : ''}`}
          onClick={onToggleMobileMenu}
          aria-label="Más opciones"
          aria-expanded={isMoreOpen}
          aria-haspopup="dialog"
        >
          <div className="mobile-nav-icon">
            <NavigationRegular />
          </div>
          <span className="mobile-nav-label">Más</span>
        </button>
      </nav>

      <Dialog
        open={isMoreOpen}
        onOpenChange={(_, data) => {
          if (!data.open) onCloseMobileMenu();
        }}
      >
        <DialogSurface className="mobile-more-surface" id="mobile-more-dialog">
          <DialogBody>
            <DialogTitle
              action={
                <Button
                  appearance="subtle"
                  icon={<DismissRegular />}
                  aria-label="Cerrar menú"
                  onClick={onCloseMobileMenu}
                />
              }
            >
              Más opciones
            </DialogTitle>
            <DialogContent className="mobile-more-content">
              <div className="mobile-more-grid">
                {moreItems.map((item) => {
                  const active = isActive(item.to);
                  return (
                    <button
                      key={item.to}
                      type="button"
                      className={`mobile-more-link ${active ? 'active' : ''}`}
                      onClick={() => onNavigate(item.to)}
                      aria-current={active ? 'page' : undefined}
                    >
                      <span className="mobile-more-icon">{item.icon}</span>
                      <span>{item.label}</span>
                    </button>
                  );
                })}
              </div>
              <Divider />
              <div className="mobile-more-account">
                <div className="mobile-more-user">
                  <strong>{userName}</strong>
                  {userEmail && <span>{userEmail}</span>}
                </div>
                <Button appearance="subtle" onClick={onLogout}>Cerrar sesión</Button>
              </div>
            </DialogContent>
          </DialogBody>
        </DialogSurface>
      </Dialog>
    </>
  );
}
