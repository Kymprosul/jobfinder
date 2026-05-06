# Jobfinder Académico

Webapp MVP para rastrear ofertas académicas/docentes desde varias fuentes, filtrar por relevancia y enviar un reporte diario por email.

## Stack

- Frontend: Vue 3 + Vite
- Backend: PHP 8.2+
- Persistencia: ficheros JSON en `backend/storage`
- Email: PHPMailer por SMTP
- HTTP client backend: Guzzle
- Parsing HTML backend: Symfony DomCrawler

## Estructura

```text
backend/
  public/
    index.php
  src/
    Controllers/
    DTO/
    Filters/
    Mail/
    Scrapers/
    Services/
    Storage/
    Utils/
  storage/
    config.json
    jobs.json
    sent_jobs.json
    logs.json
    runs.json
  cron/
    daily-run.php
  composer.json
  .env.example

frontend/
  src/
    api/
    components/
    router/
    views/
  package.json
```

## Instalación

### 1. Backend

```bash
cd backend
cp .env.example .env
composer install
php -S localhost:8000 -t public
```

Configura en `backend/.env` las credenciales SMTP.

Si quieres usar Jooble de forma estable, configura también `JOOBLE_API_KEY` (API oficial de Jooble).

### 2. Frontend

```bash
cd frontend
npm install
npm run dev
```

Por defecto Vite usará `http://localhost:8000` para `/api` vía proxy.

## Endpoints disponibles

- `GET /api/config`
- `POST /api/config`
- `GET /api/jobs`
- `GET /api/runs`
- `GET /api/logs`
- `POST /api/run`
- `GET /api/status`

## Cron / ejecución diaria

Puedes ejecutar el proceso completo manualmente con:

```bash
cd backend
php cron/daily-run.php
```

Ejemplo de cron diario a las 08:00:

```cron
0 8 * * * /usr/bin/php /ruta/al/proyecto/backend/cron/daily-run.php
```

En Windows puedes programarlo con el Programador de tareas ejecutando `php.exe` sobre ese mismo script.

## Despliegue en Hostinger

Se ha dejado un empaquetado reproducible para hosting compartido con Apache:

```powershell
./prepare-hostinger.ps1
```

Ese script:

1. Compila el frontend
2. Genera `hostinger/public_html` con fallback SPA y caché estática
3. Copia el backend a `hostinger/backend`
4. Limpia `storage` para evitar subir datos locales
5. Crea `hostinger/public_html/api/index.php` para servir el backend desde `/api`

Sube el contenido de `hostinger/` a tu cuenta de Hostinger manteniendo esta estructura:

```text
backend/
public_html/
```

Deja `public_html` como raíz web y `backend` como carpeta hermana. Después:

1. Copia `backend/.env.example` a `backend/.env`
2. Ajusta `APP_ENV=prod`, `APP_DEBUG=false` y `APP_ALLOWED_ORIGINS` con tu dominio final
3. Configura SMTP real
4. Verifica permisos de escritura sobre `backend/storage`
5. Programa el cron apuntando a `backend/cron/daily-run.php`

## Flujo de ejecución

1. Carga configuración JSON
2. Ejecuta scrapers activos
3. Normaliza ofertas
4. Filtra por relevancia y antigüedad
5. Deduplica por fingerprint
6. Guarda histórico en JSON
7. Prepara nuevas ofertas no enviadas
8. Genera reporte HTML
9. Envía email si SMTP está configurado
10. Marca ofertas enviadas y registra ejecución

## Notas sobre scraping

- `HigherEdJobs` está implementado como fuente tolerante a bloqueo. Si responde con protección anti-bot o contenido no usable, devuelve vacío y registra el incidente.
- `eChinacities` intenta búsqueda por keyword y, si no obtiene HTML utilizable, usa un fallback limitado sobre contenido público visible. Si tampoco es usable, la fuente se marca como bloqueada/no accesible.
- `Jooble` usa API oficial cuando `JOOBLE_API_KEY` está presente. Sin clave, intenta modo público best-effort (puede quedar bloqueado por Cloudflare/CAPTCHA y marcarse como no disponible).
- Ningún scraper intenta saltarse captcha ni medidas anti-bot.

## Persistencia actual y migración futura a SQLite

La persistencia está desacoplada mediante `StorageInterface` y `JsonStorage`.

Para migrar a SQLite más adelante:

1. Crear `SQLiteStorage` que implemente `StorageInterface`
2. Mapear `config`, `jobs`, `sent_jobs`, `logs` y `runs` a tablas
3. Cambiar la instancia creada en `backend/bootstrap.php`
4. Mantener intactos controladores, servicios, scrapers y frontend

La deduplicación ya usa `id` y fingerprint estables, lo que facilita índices únicos en SQLite.

## Limitaciones del MVP

- Los scrapers están preparados para cambios razonables de marcado, pero no sustituyen mantenimiento periódico frente a cambios fuertes en HTML.
- No hay autenticación de usuarios.
- No hay cola de tareas ni panel multiusuario.
- El filtrado es heurístico y configurable, pero no incorpora NLP ni clasificación avanzada.
