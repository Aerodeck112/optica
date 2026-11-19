@echo off
chcp 65001 >nul
cd /d "%~dp0"
schtasks /create /tn "AW Camere Lounge" /tr "wscript.exe \"%~dp0porneste-ascuns.vbs\"" /sc minute /mo 5 /f
schtasks /create /tn "AW Camere Lounge (pornire)" /tr "wscript.exe \"%~dp0porneste-ascuns.vbs\"" /sc onlogon /f
if errorlevel 1 (
  echo Nu s-a putut crea pornirea automata. Rulati cu clic dreapta - "Run as administrator".
) else (
  echo Gata: camerele pornesc la deschiderea Windows si sunt repornite automat daca se opresc.
  wscript "%~dp0porneste-ascuns.vbs"
)
pause
