import React from 'react';
import ReactDOM from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { BrowserRouter } from 'react-router-dom';
import App from './App';
import './styles.css';

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: 1,
      staleTime: 0, // Immediately mark data stale so page switches & mutations re-fetch fresh data
      gcTime: 1000 * 60 * 10, // Keep in memory 10 mins
      refetchOnWindowFocus: true, // Re-fetch on tab focus
      refetchOnReconnect: true,
      refetchOnMount: 'always', // Always re-fetch when navigating to a view
    },
  },
});

// Register Service Worker for PWA (Android & iOS)
if ('serviceWorker' in navigator && import.meta.env.PROD) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js').then(
      (registration) => {
        console.log('PWA ServiceWorker registrado con éxito:', registration.scope);
      },
      (err) => {
        console.error('PWA ServiceWorker fallo al registrar:', err);
      }
    );
  });
} else if ('serviceWorker' in navigator) {
  // Allow SW in dev mode for testing PWA capabilities
  navigator.serviceWorker.register('/sw.js').catch(() => {});
}

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <QueryClientProvider client={queryClient}>
      <BrowserRouter>
        <App />
      </BrowserRouter>
    </QueryClientProvider>
  </React.StrictMode>,
);
