import { useState, useEffect } from 'react';
import { Button } from '@fluentui/react-components';

interface FullPageLoaderProps {
  message?: string;
  error?: string | null;
  onRetry?: () => void;
}

export function FullPageLoader({ message = 'Iniciando tu espacio de trabajo seguro…', error, onRetry }: FullPageLoaderProps) {
  const [progress, setProgress] = useState(15);

  useEffect(() => {
    const timer = setInterval(() => {
      setProgress((prev) => {
        if (prev >= 90) return prev;
        return prev + Math.floor(Math.random() * 15) + 5;
      });
    }, 200);

    return () => clearInterval(timer);
  }, []);

  return (
    <div className="fullpage-loader-overlay">
      <div className="fullpage-loader-card">
        <div className="loader-logo-container">
          <div className="loader-spinner-ring" />
          <img src="/logo.png" alt="Ghost Finance Logo" className="loader-logo-img" />
        </div>

        <div className="loader-brand-title">GHOST FINANCE</div>
        <div className="loader-status-msg">{error || message}</div>

        {!error ? (
          <div className="loader-progress-track">
            <div className="loader-progress-bar" style={{ width: `${progress}%` }} />
          </div>
        ) : (
          onRetry && (
            <Button appearance="primary" onClick={onRetry} style={{ marginTop: '16px' }}>
              Reintentar conexión
            </Button>
          )
        )}
      </div>
    </div>
  );
}
