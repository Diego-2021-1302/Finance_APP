@echo off
title Finanzas Clara 2.0 - Lector y Registrador de Tunel
color 0B
cls

echo ====================================================================
echo   FINANZAS CLARA 2.0 - INICIANDO SERVICIOS VISIBLES Y REGISTRO REDIS
echo ====================================================================
echo.

cd /d C:\dev\Finance_App\Backend

:: 1. Iniciar Servidor Laravel en una ventana de consola visible
echo [1/3] Abriendo consola del Servidor Laravel (http://localhost:8021)...
start "FINANZAS CLARA - SERVIDOR LARAVEL (LOGS)" cmd /k "color 0A && php artisan serve --port=8021"

:: 2. Iniciar Tunel Cloudflare en una ventana de consola visible
echo [2/3] Abriendo consola del Tunel Cloudflare...
start "FINANZAS CLARA - TUNEL CLOUDFLARE" cmd /k "color 0B && cloudflared.exe tunnel --url http://localhost:8021 2>&1 | powershell -Command "$input | Tee-Object -FilePath 'cloudflared.log'""

echo [3/3] Esperando establecimiento del tunel y leyendo URL activa...
timeout /t 6 /nobreak > NUL

:: 3. Ejecutar comando para leer cloudflared.log y registrar en Redis
php artisan finance:register-tunnel

echo.
echo ====================================================================
echo   [OK] TUNEL CONECTADO Y REGISTRADO EN REDIS EXITOSAMENTE!
echo.
echo   - App Frontend (Vercel):  https://finance-app-eight-khaki.vercel.app/
echo   - Backend Laravel Logs:   (Consola verde abierta)
echo   - Cloudflare Tunnel Logs: (Consola azul abierta)
echo ====================================================================
echo.
pause
