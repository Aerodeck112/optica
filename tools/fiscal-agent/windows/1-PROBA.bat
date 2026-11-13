@echo off
chcp 65001 >nul
cd /d "%~dp0"
echo === Proba agentului casei de marcat ===
"%~dp0php\php.exe" -v >nul 2>&1
if errorlevel 1 (
  echo.
  echo PHP nu porneste pe acest calculator. Instalati "Microsoft Visual C++ Redistributable x64":
  echo   https://aka.ms/vs/17/release/vc_redist.x64.exe
  echo apoi rulati din nou acest fisier.
  pause
  exit /b 1
)
"%~dp0php\php.exe" -c "%~dp0php\php.ini" -d extension_dir="%~dp0php\ext" -d curl.cainfo="%~dp0php\cacert.pem" -d openssl.cafile="%~dp0php\cacert.pem" "%~dp0aw-fiscal-agent.php" --once
echo.
echo Daca nu apare nicio eroare, in panou (Financiar - Case de marcat) casa apare "vazuta acum".
pause
