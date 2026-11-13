@echo off
chcp 65001 >nul
title Agent casa de marcat - Another World Cluj (nu inchideti aceasta fereastra)
cd /d "%~dp0"
:loop
"%~dp0php\php.exe" -c "%~dp0php\php.ini" -d extension_dir="%~dp0php\ext" -d curl.cainfo="%~dp0php\cacert.pem" -d openssl.cafile="%~dp0php\cacert.pem" "%~dp0aw-fiscal-agent.php"
echo Agentul s-a oprit. Repornire in 10 secunde...
timeout /t 10 /nobreak >nul
goto loop
