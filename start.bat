@echo off
chcp 65001 >nul
cd /d "%~dp0"
if not exist database\clinic.sqlite (
    echo База данных не найдена. Сначала выполните: php install.php --demo
    pause
    exit /b
)
start "" http://localhost:8000
php -S localhost:8000 -t public
