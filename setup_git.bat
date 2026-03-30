@echo off
rem Removemos any index lock if it exists
if exist .git\index.lock del /f /q .git\index.lock

echo TENTANDO CONFIGURAR ORIGIN...
git remote set-url origin https://github.com/KelvinMattos/hub360pro-app.prisma.com.br.git 2>nul || git remote add origin https://github.com/KelvinMattos/hub360pro-app.prisma.com.br.git

echo VERIFICANDO:
git remote -v
pause
