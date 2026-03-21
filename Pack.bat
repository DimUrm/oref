@echo off
setlocal enabledelayedexpansion

set VERSION_FILE=version.txt

if not exist %VERSION_FILE% (
    echo 1.0 > %VERSION_FILE%
)

:: Чтение текущей версии
set /p CURRENT_VERSION=<%VERSION_FILE%

:: Разделение версии на мажорную и минорную (1.1 -> 1 и 1)
for /f "tokens=1,2 delims=." %%a in ("%CURRENT_VERSION%") do (
    set MAJOR=%%a
    set MINOR=%%b
)

:: Инкремент минорной версии
set /a MINOR+=1

set NEW_VERSION=%MAJOR%.%MINOR%
echo Packing version: %NEW_VERSION%

:: Команда упаковки (добавляем версию в имя файла для удобства)
tar -czvf oref_alert_v%NEW_VERSION%.tgz cms img languages modules templates scripts

if %ERRORLEVEL% EQU 0 (
    echo %NEW_VERSION% > %VERSION_FILE%
    echo Successfully packed: oref_alert_v%NEW_VERSION%.tgz
) else (
    echo Error during packing!
)

pause
