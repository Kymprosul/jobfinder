@echo off
setlocal EnableDelayedExpansion

echo ======================================
echo  Jobfinder - Parada local
echo ======================================
echo.

call :killport 5173 "Frontend"
call :killport 8000 "Backend"

echo.
echo [INFO] Parada finalizada.
pause
endlocal
exit /b 0

:killport
set "PORT=%~1"
set "LABEL=%~2"
set "FOUND=0"

for /f "tokens=5" %%P in ('netstat -ano ^| findstr /R /C:":%PORT% .*LISTENING"') do (
  set "FOUND=1"
  echo [INFO] Cerrando %LABEL% en puerto %PORT% ^(PID %%P^)
  taskkill /PID %%P /F >nul 2>nul
)

if "!FOUND!"=="0" (
  echo [INFO] No habia ningun proceso escuchando en el puerto %PORT%.
)

exit /b 0
