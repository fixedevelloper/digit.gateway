#!/bin/sh
set -e

if [ ! -f .env ]; then
  cp .env.example .env
  php artisan key:generate --force
fi

php artisan migrate --force

exec "$@"
