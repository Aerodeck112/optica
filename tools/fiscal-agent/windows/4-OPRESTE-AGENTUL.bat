@echo off
chcp 65001 >nul
schtasks /delete /tn "AW Agent Casa de Marcat" /f
schtasks /delete /tn "AW Agent Casa de Marcat (pornire)" /f
wmic process where "name='php.exe' and commandline like '%%aw-fiscal-agent.php%%'" call terminate >nul 2>&1
echo Agentul a fost oprit si nu mai porneste automat. Pentru repornire: 3-PORNIRE-AUTOMATA.bat
pause
