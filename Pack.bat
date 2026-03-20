@echo off
setlocal enabledelayedexpansion

set VERSION_FILE=version.txt
set ARCHIVE_NAME=oref_alert

:: 1. Проверяем, существует ли файл версии. Если нет - создаем 1.0
if not exist %VERSION_FILE% (
    echo 1.0 > %VERSION_FILE%
)

:: 2. Читаем текущую версию из файла
set /p CURRENT_VERSION=<%VERSION_FILE%

:: 3. Разделяем версию на части (Major.Minor)
for /f "tokens=1,2 delims=." %%a in ("%CURRENT_VERSION%") do (
    set MAJOR=%%a
    set MINOR=%%b
)

:: 4. Убираем лишние пробелы, если они есть
set MAJOR=%MAJOR: =%
set MINOR=%MINOR: =%

:: 5. Инкрементируем минорную часть
set /a MINOR+=1

:: 6. Формируем новую строку версии
set NEW_VERSION=%MAJOR%.%MINOR%

echo Current version: %CURRENT_VERSION%
echo Target version:  %NEW_VERSION%

:: 7. Создаем архив (используем стандартный tar в Windows 10/11)
:: Файл будет называться oref_alert_v1.2.tgz для удобства, 
:: либо просто oref_alert.tgz как вы просили.
tar -czvf %ARCHIVE_NAME%_v%NEW_VERSION%.tgz cms img modules templates scripts

:: 8. Если tar отработал успешно, сохраняем новую версию в файл
if %ERRORLEVEL% EQU 0 (
    echo %NEW_VERSION% > %VERSION_FILE%
    echo Successfully packed: %ARCHIVE_NAME%_v%NEW_VERSION%.tgz
) else (
    echo Error during packing!
)

pause