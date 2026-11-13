@echo off
chcp 65001 >nul
schtasks /delete /tn "AW Agent Casa de Marcat" /f
pause
