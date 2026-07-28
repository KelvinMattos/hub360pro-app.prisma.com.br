#!/usr/bin/env bash
#
# Deploy manual — rode no Terminal do cPanel (dentro da pasta do projeto):
#   bash deploy.sh
#
# Se o comando `php` não for encontrado, informe o caminho:
#   PHP=/usr/local/bin/ea-php83 bash deploy.sh
#
# IMPORTANTE: este script FALHA ALTO de propósito. Não reintroduza "|| true"
# ou "|| echo" nas etapas de atualização de código. Já aconteceu de o git pull
# falhar (chave SSH pedindo passphrase), o script seguir em frente e imprimir
# "Deploy concluído" — rodando migrations em cima de código velho por semanas.
#
set -euo pipefail

PHP="${PHP:-php}"
BRANCH="${BRANCH:-main}"

echo "==> Commit atual: $(git rev-parse --short HEAD)"

echo "==> Buscando código novo (origin/$BRANCH)"
# Use remote HTTPS (repo público) para o fetch ser anônimo e não esbarrar em
# chave SSH com passphrase:  git remote set-url origin https://github.com/<owner>/<repo>.git
git fetch --prune origin "$BRANCH"

echo "==> Aplicando (fast-forward)"
# --ff-only: se houver divergência local, ABORTA em vez de mascarar o problema.
git merge --ff-only FETCH_HEAD

echo "==> Agora em: $(git rev-parse --short HEAD)"

echo "==> Instalando dependências PHP"
if [ -f composer.phar ]; then
  $PHP composer.phar install --no-dev --optimize-autoloader --no-interaction
else
  composer install --no-dev --optimize-autoloader --no-interaction
fi

echo "==> Rodando migrations"
$PHP artisan migrate --force

echo "==> Limpando/reconstruindo caches"
$PHP artisan optimize:clear

echo "==> Storage link"
$PHP artisan storage:link || echo "  (storage link já existe — ok)"

echo "==> Deploy concluído com sucesso em $(git rev-parse --short HEAD)."
