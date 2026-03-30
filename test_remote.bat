@echo off
set DB_HOST=69.6.213.165
echo Testing connection to remote database at %DB_HOST%...
php artisan db:show
pause
