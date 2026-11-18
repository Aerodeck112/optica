@echo off
chcp 65001 >nul
cd /d "%~dp0"
echo === Ultimele mesaje ale agentului (agent.log) ===
if exist agent.log (
  powershell -NoProfile -Command "Get-Content -Tail 30 -Encoding UTF8 '%~dp0agent.log'"
) else (
  echo Agentul nu a scris inca nimic. Porniti 2-PORNESTE-AGENTUL.bat.
)
echo.
tasklist /v /fi "imagename eq php.exe" | find /i "php.exe" >nul && echo Stare: agentul RULEAZA. || echo Stare: agentul NU ruleaza - porniti 2-PORNESTE-AGENTUL.bat
pause
