@echo off
title Finanzas Clara 2.0 - Iniciador de Servicios y Tunel
color 0B
cls

echo ====================================================================
echo   FINANZAS CLARA 2.0 - INICIADOR Y VERIFICADOR DE SERVICIOS
echo ====================================================================
echo.

cd /d C:\dev\Finance_App\Backend

:: 1. Iniciar Servidor Backend Laravel
echo [PASO 1/4] Iniciando Servidor Backend Laravel (http://localhost:8021)...
start "FINANZAS CLARA - SERVIDOR LARAVEL" cmd /k "color 0A && php artisan serve --port=8021"
echo   -> [ OK ] Servidor Laravel lanzado en puerto 8021.
echo.

:: 2. Iniciar Tunel Cloudflare con puerto de metricas 4040 y logfile
echo [PASO 2/4] Levantando Tunel Cloudflare hacia http://localhost:8021...
if exist cloudflared.log del /f /q cloudflared.log

start "FINANZAS CLARA - TUNEL CLOUDFLARE" cmd /k "color 0B && cloudflared.exe tunnel --url http://localhost:8021 --metrics 127.0.0.1:4040 --logfile cloudflared.log"
echo   -> [ OK ] Tunel Cloudflare lanzado correctamente.
echo.

:: 3. Esperar inicializacion del tunel
echo [PASO 3/4] Esperando inicializacion del tunel (6s)...
timeout /t 6 /nobreak > NUL
echo   -> [ OK ] Tiempo de inicializacion completado.
echo.

:: 4. Registrar URL en Redis
echo [PASO 4/4] Sincronizando URL del tunel en Redis...
echo --------------------------------------------------------------------
php artisan finance:register-tunnel
echo --------------------------------------------------------------------
echo.

:: Resumen final
echo ====================================================================
echo   [RESUMEN DE ESTADO DE SERVICIOS]
echo ====================================================================
echo   - Backend Laravel Local:  http://localhost:8021
echo   - App Frontend (Vercel):  https://finance-app-eight-khaki.vercel.app/
echo   - Registro en Redis:       Procesado arriba
echo ====================================================================
echo.
echo Esta ventana permanecera abierta. Presiona cualquier tecla para salir.
pause > NUL
