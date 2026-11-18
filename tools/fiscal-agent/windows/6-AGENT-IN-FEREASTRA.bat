@echo off
chcp 65001 >nul
title Agent casa de marcat - mod depanare (inchiderea ferestrei opreste agentul)
cd /d "%~dp0"
"%~dp0php\php.exe" -c "%~dp0php\php.ini" -d extension_dir="%~dp0php\ext" -d curl.cainfo="%~dp0php\cacert.pem" -d openssl.cafile="%~dp0php\cacert.pem" "%~dp0aw-fiscal-agent.php"
pause
