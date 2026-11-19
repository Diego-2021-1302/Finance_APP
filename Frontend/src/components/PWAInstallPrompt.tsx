import { useState, useEffect } from 'react';
import { Button, Dialog, DialogSurface, DialogBody, DialogTitle, DialogContent, DialogActions } from '@fluentui/react-components';
import { ArrowDownloadRegular, DismissRegular, ShareRegular, AddSquareRegular } from '@fluentui/react-icons';

interface BeforeInstallPromptEvent extends Event {
  prompt: () => Promise<void>;
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
}

export function PWAInstallPrompt() {
  const [deferredPrompt, setDeferredPrompt] = useState<BeforeInstallPromptEvent | null>(null);
  const [showIOSModal, setShowIOSModal] = useState(false);
  const [bannerVisible, setBannerVisible] = useState(false);
  const [isIOS, setIsIOS] = useState(false);

  useEffect(() => {
    // Check if already running in standalone mode (PWA installed)
    const isStandalone = window.matchMedia('(display-mode: standalone)').matches ||
      (window.navigator as unknown as { standalone?: boolean }).standalone === true;

    if (isStandalone) {
      return; // Already installed, do not show prompts
    }

    // Check if user dismissed recently (last 3 days)
    const lastDismissed = localStorage.getItem('ghost_pwa_dismissed');
    if (lastDismissed && Date.now() - parseInt(lastDismissed, 10) < 3 * 24 * 60 * 60 * 1000) {
      return;
    }

    // Detect iOS
    const userAgent = window.navigator.userAgent.toLowerCase();
    const isIosDevice = /iphone|ipad|ipod/.test(userAgent);
    setIsIOS(isIosDevice);

    if (isIosDevice) {
      setBannerVisible(true);
    }

    // Handle Android / Chrome beforeinstallprompt
    const handleBeforeInstallPrompt = (e: Event) => {
      e.preventDefault();
      setDeferredPrompt(e as BeforeInstallPromptEvent);
      setBannerVisible(true);
    };

    window.addEventListener('beforeinstallprompt', handleBeforeInstallPrompt);

    return () => {
      window.removeEventListener('beforeinstallprompt', handleBeforeInstallPrompt);
    };
  }, []);

  const handleInstallClick = async () => {
    if (isIOS) {
      setShowIOSModal(true);
      return;
    }

    if (!deferredPrompt) return;

    await deferredPrompt.prompt();
    const choiceResult = await deferredPrompt.userChoice;
    if (choiceResult.outcome === 'accepted') {
      console.log('El usuario aceptó la instalación de la PWA');
    }
    setDeferredPrompt(null);
    setBannerVisible(false);
  };

  const dismissBanner = () => {
    setBannerVisible(false);
    localStorage.setItem('ghost_pwa_dismissed', Date.now().toString());
  };

  if (!bannerVisible) return null;

  return (
    <>
      <div className="pwa-install-banner" role="alert">
        <div className="pwa-banner-content">
          <img src="/logo.png" alt="Ghost Finance" className="pwa-banner-icon" />
          <div className="pwa-banner-text">
            <strong>Instala GHOST FINANCE</strong>
            <span>Acceso rápido desde tu pantalla de inicio en Android/iPhone</span>
          </div>
        </div>
        <div className="pwa-banner-actions">
          <Button
            appearance="primary"
            size="small"
            icon={<ArrowDownloadRegular />}
            onClick={handleInstallClick}
          >
            Instalar
          </Button>
          <Button
            appearance="subtle"
            size="small"
            icon={<DismissRegular />}
            aria-label="Cerrar banner"
            onClick={dismissBanner}
          />
        </div>
      </div>

      {showIOSModal && (
        <Dialog open={showIOSModal} onOpenChange={(_, data) => setShowIOSModal(data.open)}>
          <DialogSurface className="ios-install-modal">
            <DialogBody>
              <DialogTitle>Instalar en iPhone / iPad</DialogTitle>
              <DialogContent className="ios-install-steps">
                <p>Sigue estos sencillos pasos para instalar <strong>GHOST FINANCE</strong> como app nativa:</p>
                <ol>
                  <li>
                    Toca el botón <strong>Compartir</strong> <ShareRegular style={{ color: '#00d2ff', verticalAlign: 'middle' }} /> en la barra inferior de Safari.
                  </li>
                  <li>
                    Desplázate hacia abajo y selecciona <strong>Agregar al inicio</strong> <AddSquareRegular style={{ color: '#00d2ff', verticalAlign: 'middle' }} />.
                  </li>
                  <li>
                    Toca <strong>Agregar</strong> en la esquina superior derecha. ¡Listo!
                  </li>
                </ol>
              </DialogContent>
              <DialogActions>
                <Button appearance="primary" onClick={() => setShowIOSModal(false)}>
                  Entendido
                </Button>
              </DialogActions>
            </DialogBody>
          </DialogSurface>
        </Dialog>
      )}
    </>
  );
}
