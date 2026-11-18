@echo off
title Finanzas Clara 2.0 - Iniciador de Servicios y Tunel
color 0B
cls

echo ====================================================================
echo   FINANZAS CLARA 2.0 - INICIADOR Y VERIFICADOR DE SERVICIOS
echo ====================================================================
echo.

cd /d C:\dev\Finance_App\Backend

echo [PASO 1/4] Verificando proceso anterior de Cloudflare para este proyecto...
if not exist cloudflared.pid goto start_laravel
set /p CLOUD_PID=<cloudflared.pid
if not "%CLOUD_PID%"=="" taskkill /f /pid %CLOUD_PID% >NUL 2>&1
if exist cloudflared.pid del /f /q cloudflared.pid >NUL 2>&1
if exist cloudflared.log del /f /q cloudflared.log >NUL 2>&1
set CLOUD_PID=

:start_laravel
echo   -> [ OK ] Limpieza inicial completada.
echo.

echo [PASO 2/4] Iniciando Servidor Backend Laravel (http://localhost:8021)...
start "FINANZAS CLARA - SERVIDOR LARAVEL" cmd /k "color 0A && php artisan serve --port=8021"
echo   -> [ OK ] Servidor Laravel lanzado en puerto 8021.
echo.

echo [PASO 3/4] Levantando Tunel Cloudflare exclusivo para puerto 8021...
start "FINANZAS CLARA - TUNEL CLOUDFLARE" cmd /k "color 0B && cloudflared.exe tunnel --url http://localhost:8021 --pidfile cloudflared.pid --logfile cloudflared.log"
echo   -> [ OK ] Tunel Cloudflare lanzado correctamente.
echo.

echo [PASO 4/4] Esperando inicializacion del tunel y registrando URL en Redis (6s)...
timeout /t 6 /nobreak > NUL
echo --------------------------------------------------------------------
php artisan finance:register-tunnel
echo --------------------------------------------------------------------
echo.

echo ====================================================================
echo   [RESUMEN DE ESTADO DE SERVICIOS]
echo ====================================================================
echo   - Backend Laravel Local:  http://localhost:8021
echo   - App Frontend (Vercel):  https://finance-app-eight-khaki.vercel.app/
echo   - Registro en Redis:       Procesado arriba
echo ====================================================================
echo.
echo Esta ventana permanecera abierta.
pause
