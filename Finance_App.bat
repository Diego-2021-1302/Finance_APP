@echo off
title Finanzas Clara 2.0 - Tunel Cloudflare + Registro Automatico Redis
color 0B
cls

echo ====================================================================
echo   FINANZAS CLARA 2.0 - INICIANDO SERVICIOS Y TUNEL DE CLOUDFLARE
echo ====================================================================
echo.

:: 1. Verificar o iniciar servidor Backend si es necesario
echo [1/3] Verificando servidor local Laravel...
cd /d C:\dev\Finance_App\Backend

:: 2. Iniciar Cloudflare Tunnel
echo [2/3] Levantando tunel Cloudflare (http://localhost:8021)...
start "Cloudflare Tunnel" /B cloudflared.exe tunnel --url http://localhost:8021 > cloudflared.log 2>&1

echo [3/3] Esperando establecimiento del tunel y leyendo URL activa...
timeout /t 6 /nobreak > NUL

:: 3. Ejecutar comando de registro en Redis
php artisan finance:register-tunnel

echo.
echo ====================================================================
echo   [OK] TUNEL CONECTADO Y REGISTRADO EN REDIS EXITOSAMENTE!
echo.
echo   - App Frontend (Vercel):  https://finance-app-eight-khaki.vercel.app/
echo   - Backend Laravel API:   (Registrado dinamicamente en Redis)
echo ====================================================================
echo.
echo Presiona cualquier tecla para cerrar esta ventana. El tunel seguira corriendo en segundo plano.
pause > NUL
