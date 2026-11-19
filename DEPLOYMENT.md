# GUÍA DE DESPLIEGUE EN PRODUCCIÓN — FINANCE APP 2.0

Esta guía describe los pasos para desplegar **Finance App 2.0** en un servidor de producción.

---

## 1. REQUISITOS DEL SERVIDOR

- **PHP**: 8.2 o superior (extensiones necesarias: `pdo_mysql`, `bcmath`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `curl`).
- **Base de Datos**: MySQL 8.0 o MariaDB 10.5+.
- **Node.js**: v18.0.0+ o v20+.
- **Servidor Web**: Nginx o Apache con soporte HTTPS / certificado SSL (Let's Encrypt).
- **Gestor de Procesos**: Supervisor (para Queue Workers y Laravel Scheduler).

---

## 2. VARIABLES DE ENTORNO EN PRODUCCIÓN

Configurar en `Backend/.env`:

```env
APP_NAME="Finanzas Clara"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://tudominio.com
APP_TIMEZONE=America/Santo_Domingo

APP_LOCALE=es_DO
APP_FALLBACK_LOCALE=es
APP_FAKER_LOCALE=es_DO

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=finance_app_prod
DB_USERNAME=finance_user
DB_PASSWORD="PasswordSeguro123!"

SESSION_DRIVER=database
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true

QUEUE_CONNECTION=database
CACHE_STORE=database

MAIL_MAILER=smtp
MAIL_HOST=smtp.mailgun.org
MAIL_PORT=587
MAIL_USERNAME=postmaster@tudominio.com
MAIL_PASSWORD="PasswordSMTP"
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="no-reply@tudominio.com"
MAIL_FROM_NAME="${APP_NAME}"

CORS_ALLOWED_ORIGINS=https://tudominio.com
GEMINI_API_KEY="Tu_Clave_API_De_Google_Gemini"
GEMINI_MODEL=gemini-3.8-flash
```

---

## 3. PASOS DE INSTALACIÓN Y DESPLIEGUE

### Backend (Laravel)
1. Clonar repositorio y navegar a `Backend/`:
   ```bash
   cd Backend
   composer install --no-dev --optimize-autoloader
   ```
2. Generar clave de cifrado de la aplicación:
   ```bash
   php artisan key:generate --force
   ```
3. Ejecutar migraciones de la base de datos de forma segura:
   ```bash
   php artisan migrate --force
   ```
4. Optimizar cachés de Laravel:
   ```bash
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```

### Frontend (React / Vite)
1. Navegar a `Frontend/`:
   ```bash
   cd Frontend
   npm install
   npm run build
   ```
2. Los archivos compilados en `dist/` se sirven a través del servidor web Nginx o Apache.

### Vercel y sincronización del túnel
- La configuración raíz construye `api/tunnel.js` como función de Vercel y la aplicación de `Frontend/` como sitio estático.
- Conectar la base Redis al proyecto Vercel para que `REDIS_URL` esté disponible en Production. La función usa el cliente Redis por TCP/TLS; no requiere variables REST ni variables con prefijo `VITE_`.
- La función consulta Redis sin caché; el frontend verifica cambios cada 5 segundos mientras está abierto y vuelve a sincronizar al recuperar el foco de la pestaña. La URL nueva se activa después de superar la verificación de salud del backend.

---

## 4. SUPERVISIÓN DE TRABAJOS EN SEGUNDO PLANO (SUPERVISOR)

Configurar en `/etc/supervisor/conf.d/finance-worker.conf`:

```ini
[program:finance-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/finance_app/Backend/artisan queue:work database --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/finance_app/Backend/storage/logs/worker.log
```

Configurar Crontab para el Scheduler de Laravel:
```bash
* * * * * cd /var/www/finance_app/Backend && php artisan schedule:run >> /dev/null 2>&1
```

---

## 5. ESTRATEGIA DE RESPALDOS AUTOMÁTICOS (BACKUPS)

Configurar backup diario de MySQL:
```bash
mysqldump -u finance_user -p'PasswordSeguro123!' finance_app_prod | gzip > /var/backups/finance_app_$(date +\%Y\%m\%d).sql.gz
```
