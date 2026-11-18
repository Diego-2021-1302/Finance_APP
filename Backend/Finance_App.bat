@echo off
title Finanzas Clara 2.0 - Lector y Registrador de Tunel
color 0B
cls

echo ====================================================================
echo   FINANZAS CLARA 2.0 - INICIANDO SERVICIOS Y REGISTRO EN REDIS
echo ====================================================================
echo.

cd /d C:\dev\Finance_App\Backend

:: 1. Iniciar Servidor Laravel en una consola visible
echo [PASO 1/4] Abriendo consola del Servidor Backend Laravel (http://localhost:8021)...
start "FINANZAS CLARA - SERVIDOR LARAVEL (LOGS)" cmd /k "color 0A && php artisan serve --port=8021"

:: 2. Iniciar Tunel Cloudflare en una consola visible
echo [PASO 2/4] Abriendo consola del Tunel Cloudflare...
start "FINANZAS CLARA - TUNEL CLOUDFLARE" cmd /k "color 0B && cloudflared.exe tunnel --url http://localhost:8021 2>&1 | powershell -Command "$input | Tee-Object -FilePath 'cloudflared.log'""

echo [PASO 3/4] Esperando a que el tunel se establezca y genere la URL en vivo...
timeout /t 6 /nobreak > NUL

echo [PASO 4/4] Ejecutando registro dinamico en Redis...
echo --------------------------------------------------------------------
php artisan finance:register-tunnel
echo --------------------------------------------------------------------

echo.
echo ====================================================================
echo   [RESULTADO FINAL] SERVICIOS ACTIVOS Y TUNEL REGISTRADO
echo ====================================================================
echo   - App Frontend Vercel: https://finance-app-eight-khaki.vercel.app/
echo   - Consola Laravel:     Abierta en ventana verde (Logs en vivo)
echo   - Consola Cloudflare:  Abierta en ventana azul (Trafico en vivo)
echo ====================================================================
echo.
echo Esta ventana permanecera abierta para referencia. Puedes minimizarla.
pause
