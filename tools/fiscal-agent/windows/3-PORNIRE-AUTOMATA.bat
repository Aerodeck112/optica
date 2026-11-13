@echo off
chcp 65001 >nul
cd /d "%~dp0"
schtasks /create /tn "AW Agent Casa de Marcat" /tr "\"%~dp02-PORNESTE-AGENTUL.bat\"" /sc onlogon /rl limited /f
if errorlevel 1 (
  echo Nu s-a putut crea pornirea automata. Rulati fisierul cu clic dreapta - "Run as administrator".
) else (
  echo Gata: agentul porneste singur la fiecare deschidere a sesiunii Windows.
)
pause
