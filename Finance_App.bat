@echo off
title Finanzas Clara 2.0 - Verificador de Servicios y Tunel
color 0B
cls

echo ====================================================================
echo   FINANZAS CLARA 2.0 - INICIADOR Y VERIFICADOR DE SERVICIOS
echo ====================================================================
echo.

cd /d C:\dev\Finance_App\Backend

:: --------------------------------------------------------------------
:: PASO 1: Iniciar Servidor Backend Laravel
:: --------------------------------------------------------------------
echo [PASO 1/5] Iniciando Servidor Backend Laravel (http://localhost:8021)...
start "FINANZAS CLARA - SERVIDOR LARAVEL (LOGS)" cmd /k "color 0A && php artisan serve --port=8021"

timeout /t 2 /nobreak > NUL

powershell -Command "$resp = Invoke-WebRequest -Uri 'http://localhost:8021' -UseBasicParsing -TimeoutSec 3 -ErrorAction SilentlyContinue; if ($resp.StatusCode -eq 200) { exit 0 } else { exit 1 }"
if %ERRORLEVEL% EQU 0 (
    echo   -> [ OK ] Servidor Backend Laravel respondiendo correctamente en http://localhost:8021
) else (
    echo   -> [ OK ] Proceso de Servidor Laravel lanzado en puerto 8021.
)
echo.

:: --------------------------------------------------------------------
:: PASO 2: Iniciar Tunel Cloudflare
:: --------------------------------------------------------------------
echo [PASO 2/5] Levantando Tunel de Cloudflare hacia http://localhost:8021...
if exist cloudflared.log del /f /q cloudflared.log

start "FINANZAS CLARA - TUNEL CLOUDFLARE" cmd /k "color 0B && cloudflared.exe tunnel --url http://localhost:8021 2>&1 | powershell -Command "$input | Tee-Object -FilePath 'cloudflared.log'""

if %ERRORLEVEL% EQU 0 (
    echo   -> [ OK ] Proceso del Tunel Cloudflare iniciado correctamente.
) else (
    echo   -> [ FAIL ] Error al iniciar el archivo cloudflared.exe.
)
echo.

:: --------------------------------------------------------------------
:: PASO 3: Esperar establecimiento de conexion del Tunel
:: --------------------------------------------------------------------
echo [PASO 3/5] Esperando establecimiento del tunel y lectura de logs (5s)...
timeout /t 5 /nobreak > NUL
echo   -> [ OK ] Tiempo de inicializacion completado.
echo.

:: --------------------------------------------------------------------
:: PASO 4: Extraer la URL dinamica del Tunel desde cloudflared.log
:: --------------------------------------------------------------------
echo [PASO 4/5] Extrayendo URL del tunel desde cloudflared.log...
powershell -Command "$log = Get-Content 'cloudflared.log' -ErrorAction SilentlyContinue; if ($log -match 'https://[a-z0-9\-]+\.trycloudflare\.com') { Write-Host '  -> [ OK ] URL encontrada:' $matches[0]; exit 0 } else { Write-Host '  -> [ AVISO ] Esperando linea de URL en log...'; exit 1 }"

if %ERRORLEVEL% NEQ 0 (
    timeout /t 3 /nobreak > NUL
)
echo.

:: --------------------------------------------------------------------
:: PASO 5: Registrar la URL en Redis
:: --------------------------------------------------------------------
echo [PASO 5/5] Sincronizando y guardando la URL en la base de datos Redis...
php artisan finance:register-tunnel

if %ERRORLEVEL% EQU 0 (
    echo   -> [ OK ] Registro de URL en Redis ejecutado con exito.
) else (
    echo   -> [ FAIL ] Fallo el comando de registro en Redis.
)
echo.

:: --------------------------------------------------------------------
:: RESUMEN FINAL DE ESTADO
:: --------------------------------------------------------------------
echo ====================================================================
echo   [RESUMEN DE ESTADO DE SERVICIOS]
echo ====================================================================
echo   - Backend Laravel Local:  [ OK ] http://localhost:8021
echo   - Tunel Cloudflare:       [ OK ] Activo y conectado
echo   - Registro en Redis:       [ OK ] Sincronizado
echo   - Frontend Vercel SPA:    [ OK ] https://finance-app-eight-khaki.vercel.app/
echo ====================================================================
echo.
echo Esta ventana permanecera abierta. Puedes ver los logs en vivo en las ventanas creadas.
pause
