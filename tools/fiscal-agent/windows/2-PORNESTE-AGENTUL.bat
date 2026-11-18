@echo off
chcp 65001 >nul
cd /d "%~dp0"
wscript "%~dp0agent-ascuns.vbs"
echo Agentul a pornit in fundal (nu are fereastra; nu trebuie lasat nimic deschis).
echo Ca sa vedeti ce face: 5-STARE-AGENT.bat
timeout /t 5 >nul
