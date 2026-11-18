@echo off
title Finanzas Clara 2.0 - Iniciador de Servicios, Frontend Dev y Tunel
color 0B
cls

echo ====================================================================
echo   FINANZAS CLARA 2.0 - INICIADOR Y VERIFICADOR DE SERVICIOS
echo ====================================================================
echo.

cd /d C:\dev\Finance_App\Backend

:: 1. Limpiar UNICAMENTE el proceso de cloudflared de ESTE proyecto
echo [PASO 1/5] Verificando proceso anterior de Cloudflare para este proyecto...
set CLOUD_PID=
if not exist cloudflared.pid goto start_backend
set /p CLOUD_PID=<cloudflared.pid
if not "%CLOUD_PID%"=="" taskkill /f /pid %CLOUD_PID% >NUL 2>&1
if exist cloudflared.pid del /f /q cloudflared.pid >NUL 2>&1
if exist cloudflared.log del /f /q cloudflared.log >NUL 2>&1
set CLOUD_PID=

:start_backend
echo   -> [ OK ] Limpieza inicial completada.
echo.

:: 2. Iniciar Servidor Backend Laravel
echo [PASO 2/5] Iniciando Servidor Backend Laravel (http://localhost:8021)...
start "FINANZAS CLARA - SERVIDOR LARAVEL" cmd /k "color 0A && php artisan serve --port=8021"
echo   -> [ OK ] Servidor Laravel lanzado en puerto 8021.
echo.

:: 3. Iniciar Frontend Dev Server (Vite)
echo [PASO 3/5] Iniciando Frontend Dev Server (http://10.0.0.117:5180)...
start "FINANZAS CLARA - FRONTEND DEV (VITE)" cmd /k "color 0E && cd /d C:\dev\Finance_App\Frontend && npm run dev"
echo   -> [ OK ] Frontend Dev Server lanzado en http://10.0.0.117:5180
echo.

:: 4. Iniciar Tunel Cloudflare con PID file exclusivo
echo [PASO 4/5] Levantando Tunel Cloudflare exclusivo para puerto 8021...
start "FINANZAS CLARA - TUNEL CLOUDFLARE" cmd /k "color 0B && cloudflared.exe tunnel --url http://localhost:8021 --pidfile cloudflared.pid --logfile cloudflared.log"
echo   -> [ OK ] Tunel Cloudflare lanzado correctamente (PID registrado en cloudflared.pid).
echo.

:: 5. Esperar inicializacion del tunel y registrar URL en Redis
echo [PASO 5/5] Esperando inicializacion del tunel y registrando URL en Redis (6s)...
timeout /t 6 /nobreak > NUL
echo --------------------------------------------------------------------
php artisan finance:register-tunnel
echo --------------------------------------------------------------------
echo.

:: Resumen final
echo ====================================================================
echo   [RESUMEN DE ESTADO DE SERVICIOS]
echo ====================================================================
echo   - Local Dev Frontend (IP):  http://10.0.0.117:5180
echo   - Local Dev Frontend:       http://localhost:5180
echo   - Backend Laravel Local:    http://localhost:8021
echo   - App Frontend (Vercel):    https://finance-app-eight-khaki.vercel.app/
echo   - Registro en Redis:         Procesado arriba
echo ====================================================================
echo.
echo Esta ventana permanecera abierta. Presiona cualquier tecla para salir.
pause > NUL
