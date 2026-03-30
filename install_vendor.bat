@echo off
echo Executando composer install...
php composer.phar install --no-interaction --prefer-dist --optimize-autoloader
if %ERRORLEVEL% neq 0 (
    echo Erro ao instalar dependencias.
    exit /b %ERRORLEVEL%
)
echo Instalacao concluida.
