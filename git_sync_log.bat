@echo off
echo [%DATE% %TIME%] Iniciando sincronizacao... > git_sync.log
echo Staging files... >> git_sync.log
git add -A >> git_sync.log 2>&1
if %ERRORLEVEL% neq 0 (
    echo Error during git add >> git_sync.log
    exit /b %ERRORLEVEL%
)
echo Committing changes... >> git_sync.log
git commit -m "Sincronizacao de alteracoes" >> git_sync.log 2>&1
if %ERRORLEVEL% neq 0 (
    echo Error during git commit >> git_sync.log
    exit /b %ERRORLEVEL%
)
echo Pushing changes... >> git_sync.log
git push origin main >> git_sync.log 2>&1
if %ERRORLEVEL% neq 0 (
    echo Error during git push >> git_sync.log
    exit /b %ERRORLEVEL%
)
echo Sincronizacao concluida com sucesso. >> git_sync.log
