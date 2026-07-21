#!/usr/bin/env bash
#
# Deploy manual — rode no Terminal do cPanel (dentro da pasta do projeto):
#   bash deploy.sh
#
# Se o comando `php` não for encontrado, informe o caminho:
#   PHP=/usr/local/bin/ea-php83 bash deploy.sh
#
set -euo pipefail

PHP="${PHP:-php}"

echo "==> Atualizando código (git pull)"
git pull origin main || echo "  (pulei o git pull — talvez o cPanel já tenha atualizado)"

echo "==> Instalando dependências PHP"
$PHP composer.phar install --no-dev --optimize-autoloader --no-interaction || \
  composer install --no-dev --optimize-autoloader --no-interaction || true

echo "==> Rodando migrations"
$PHP artisan migrate --force

echo "==> Limpando/reconstruindo caches"
$PHP artisan optimize:clear

echo "==> Storage link"
$PHP artisan storage:link || true

echo "==> Deploy concluído com sucesso."
