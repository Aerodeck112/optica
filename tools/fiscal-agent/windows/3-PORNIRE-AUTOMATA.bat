@echo off
chcp 65001 >nul
cd /d "%~dp0"
schtasks /create /tn "AW Agent Casa de Marcat" /tr "wscript.exe \"%~dp0agent-ascuns.vbs\"" /sc minute /mo 5 /f
schtasks /create /tn "AW Agent Casa de Marcat (pornire)" /tr "wscript.exe \"%~dp0agent-ascuns.vbs\"" /sc onlogon /f
if errorlevel 1 (
  echo Nu s-a putut crea pornirea automata. Rulati fisierul cu clic dreapta - "Run as administrator".
) else (
  echo Gata: agentul porneste la deschiderea Windows si, daca se opreste, este repornit automat in cel mult 5 minute.
  wscript "%~dp0agent-ascuns.vbs"
)
pause
