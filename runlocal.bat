@echo off
setlocal

set "ROOT=%~dp0"
set "BACKEND=%ROOT%backend"
set "FRONTEND=%ROOT%frontend"
set "PHP_BIN="
set "PHP_DIR="
set "PHP_EXT_DIR="

echo ======================================
echo  Jobfinder - Arranque local
echo ======================================
echo.

where php >nul 2>nul
if errorlevel 1 (
  echo [ERROR] PHP no esta disponible en PATH.
  echo Instala PHP 8.2+ o anade php.exe al PATH.
  goto :end
)

for /f "delims=" %%I in ('where php') do (
  set "PHP_BIN=%%I"
  goto :php_found
)

:php_found
if "%PHP_BIN%"=="" (
  echo [ERROR] No se pudo resolver la ruta de php.exe.
  goto :end
)

for %%I in ("%PHP_BIN%") do set "PHP_DIR=%%~dpI"
set "PHP_EXT_DIR=%PHP_DIR%ext"

where npm >nul 2>nul
if errorlevel 1 (
  echo [ERROR] npm no esta disponible en PATH.
  echo Instala Node.js o anade npm al PATH.
  goto :end
)

if not exist "%BACKEND%\.env" (
  if exist "%BACKEND%\.env.example" (
    copy /Y "%BACKEND%\.env.example" "%BACKEND%\.env" >nul
    echo [INFO] Se ha creado backend\.env a partir de .env.example
  )
)

if not exist "%FRONTEND%\.env" (
  if exist "%FRONTEND%\.env.example" (
    copy /Y "%FRONTEND%\.env.example" "%FRONTEND%\.env" >nul
    echo [INFO] Se ha creado frontend\.env a partir de .env.example
  )
)

if not exist "%FRONTEND%\node_modules" (
  echo [INFO] Instalando dependencias del frontend...
  pushd "%FRONTEND%"
  call npm install
  if errorlevel 1 (
    echo [ERROR] Fallo al instalar dependencias del frontend.
    popd
    goto :end
  )
  popd
)

if not exist "%BACKEND%\vendor\autoload.php" (
  if not exist "%BACKEND%\composer.phar" (
    echo [INFO] Descargando Composer local en backend\composer.phar...
    powershell -NoProfile -ExecutionPolicy Bypass -Command "Invoke-WebRequest -Uri 'https://getcomposer.org/composer-stable.phar' -OutFile '%BACKEND%\composer.phar'"
  )

  if exist "%BACKEND%\composer.phar" (
    echo [INFO] Instalando dependencias del backend con Composer local...
    pushd "%BACKEND%"
    "%PHP_BIN%" -d extension_dir="%PHP_EXT_DIR%" -d extension=openssl -d extension=curl -d extension=mbstring composer.phar install
    if errorlevel 1 (
      echo [WARN] Composer no pudo instalar dependencias del backend.
      echo [WARN] El backend arrancara en modo degradado.
    )
    popd
  ) else (
    echo [WARN] No se pudo descargar Composer local.
    echo [WARN] El backend arrancara en modo degradado.
  )
)

echo.
echo [INFO] Lanzando backend en http://127.0.0.1:8000
powershell -NoProfile -ExecutionPolicy Bypass -Command "Start-Process -FilePath '%PHP_BIN%' -WorkingDirectory '%BACKEND%' -ArgumentList '-d','extension_dir=%PHP_EXT_DIR%','-d','extension=openssl','-d','extension=curl','-d','extension=mbstring','-S','127.0.0.1:8000','-t','public' -WindowStyle Normal"

echo [INFO] Esperando a que el backend responda...
powershell -NoProfile -ExecutionPolicy Bypass -Command "$url='http://127.0.0.1:8000/api/status'; for ($i=0; $i -lt 20; $i++) { try { $r=Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 2; if ($r.StatusCode -ge 200) { exit 0 } } catch {}; Start-Sleep -Seconds 1 }; exit 1"
if errorlevel 1 (
  echo [ERROR] El backend no responde en http://127.0.0.1:8000/api/status
  echo Revisa la ventana "Jobfinder Backend" para ver el error.
  goto :end
)

echo [INFO] Lanzando frontend en http://127.0.0.1:5173
powershell -NoProfile -ExecutionPolicy Bypass -Command "Start-Process cmd -WorkingDirectory '%FRONTEND%' -ArgumentList '/k','npm run dev -- --host 127.0.0.1 --port 5173 --strictPort' -WindowStyle Normal"

echo [INFO] Esperando a que el frontend responda para abrir el navegador...
powershell -NoProfile -ExecutionPolicy Bypass -Command "$url='http://127.0.0.1:5173'; for ($i=0; $i -lt 30; $i++) { try { $r=Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 2; if ($r.StatusCode -ge 200) { Start-Process $url; exit 0 } } catch {}; Start-Sleep -Seconds 1 }; Write-Host 'No se pudo abrir automaticamente la web en http://127.0.0.1:5173'"

echo.
echo [INFO] Si es la primera vez, revisa backend\.env para SMTP.
echo [INFO] Si no se abre sola, entra en http://127.0.0.1:5173

:end
echo.
pause
endlocal
