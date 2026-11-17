@echo off
setlocal

set "PROJECT_DIR=%~dp0"
set "MYSQL_BIN="
set "MYSQLD_EXE="
set "MYSQLADMIN_EXE="

if not exist "%PROJECT_DIR%Backend\artisan" (
    echo ERROR: No se encontro Backend\artisan en "%PROJECT_DIR%".
    pause
    exit /b 1
)

if not exist "%PROJECT_DIR%Frontend\package.json" (
    echo ERROR: No se encontro Frontend\package.json en "%PROJECT_DIR%".
    pause
    exit /b 1
)

for /f "delims=" %%I in ('where mysqld.exe 2^>nul') do if not defined MYSQLD_EXE set "MYSQLD_EXE=%%I"
if defined MYSQLD_EXE for %%I in ("%MYSQLD_EXE%") do set "MYSQL_BIN=%%~dpI"

if not defined MYSQLD_EXE if exist "C:\laragon\bin\mysql\mysql-8.0.30-winx64\bin\mysqld.exe" (
    set "MYSQL_BIN=C:\laragon\bin\mysql\mysql-8.0.30-winx64\bin\"
    set "MYSQLD_EXE=C:\laragon\bin\mysql\mysql-8.0.30-winx64\bin\mysqld.exe"
)

if not defined MYSQLD_EXE (
    echo ERROR: No se encontro mysqld.exe.
    echo Inicia MySQL desde Laragon y vuelve a ejecutar este archivo.
    pause
    exit /b 1
)

set "MYSQLADMIN_EXE=%MYSQL_BIN%mysqladmin.exe"
for %%I in ("%MYSQL_BIN%..\my.ini") do set "MYSQL_INI=%%~fI"

if not exist "%MYSQLADMIN_EXE%" (
    echo ERROR: No se encontro mysqladmin.exe en "%MYSQL_BIN%".
    pause
    exit /b 1
)

if not exist "%MYSQL_INI%" (
    echo ERROR: No se encontro la configuracion de MySQL en "%MYSQL_INI%".
    pause
    exit /b 1
)

echo Comprobando MySQL en 127.0.0.1:3306...
"%MYSQLADMIN_EXE%" --protocol=tcp -h 127.0.0.1 -P 3306 -u root ping >nul 2>&1
if errorlevel 1 (
    echo Iniciando MySQL...
    start "Finance_App - MySQL" "%MYSQLD_EXE%" --defaults-file="%MYSQL_INI%" --console
)

for /L %%I in (1,1,30) do (
    "%MYSQLADMIN_EXE%" --protocol=tcp -h 127.0.0.1 -P 3306 -u root ping >nul 2>&1
    if not errorlevel 1 goto MYSQL_READY
    timeout /t 1 /nobreak >nul
)

echo ERROR: MySQL no respondio en 30 segundos. Revisa la ventana de MySQL.
pause
exit /b 1

:MYSQL_READY
echo MySQL esta listo.
echo Iniciando Finance_App...
echo Backend:  http://localhost:8021
echo Frontend: http://localhost:5180
echo En la red local, usa la IP de este equipo con el puerto 5180.

netstat -ano -p tcp | findstr /R /C:":8021 .*LISTENING" >nul
if errorlevel 1 (
    start "Finance_App - Backend" /D "%PROJECT_DIR%Backend" cmd /k "php artisan serve --host=0.0.0.0 --port=8021"
) else (
    echo El puerto 8021 ya esta ocupado; se conserva el servidor existente.
)

netstat -ano -p tcp | findstr /R /C:":5180 .*LISTENING" >nul
if errorlevel 1 (
    start "Finance_App - Frontend" /D "%PROJECT_DIR%Frontend" cmd /k "npm run dev"
) else (
    echo El puerto 5180 ya esta ocupado; se conserva el servidor existente.
)

endlocal
