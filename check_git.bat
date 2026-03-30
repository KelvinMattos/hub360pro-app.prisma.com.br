@echo off
if exist .git\index.lock del /f /q .git\index.lock
git remote set-url origin https://github.com/KelvinMattos/hub360pro-app.prisma.com.br.git 2>nul || git remote add origin https://github.com/KelvinMattos/hub360pro-app.prisma.com.br.git
git remote -v
git status
