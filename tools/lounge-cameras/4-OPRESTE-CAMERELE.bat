@echo off
chcp 65001 >nul
schtasks /delete /tn "AW Camere Lounge" /f
schtasks /delete /tn "AW Camere Lounge (pornire)" /f
taskkill /f /im go2rtc.exe
echo Camerele au fost oprite si nu mai pornesc automat.
pause
