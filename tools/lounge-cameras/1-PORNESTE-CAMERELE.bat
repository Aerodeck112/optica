@echo off
chcp 65001 >nul
cd /d "%~dp0"
if not exist go2rtc.exe (
  echo Lipseste go2rtc.exe in acest folder. Vezi CITESTE-MA.txt, pasul 1.
  pause
  exit /b 1
)
wscript "%~dp0porneste-ascuns.vbs"
echo Camerele pornesc in fundal. Verificare: 3-VERIFICA-CAMERELE.bat
timeout /t 5 >nul
