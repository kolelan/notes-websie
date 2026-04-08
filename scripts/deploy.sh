#!/usr/bin/env bash
set -euo pipefail

# Usage:
# ./scripts/deploy.sh <ssh_host> <ssh_user> [ssh_port]
#
# Example:
# ./scripts/deploy.sh site-name.ru deploy 22

SSH_HOST="${1:-}"
SSH_USER="${2:-}"
SSH_PORT="${3:-22}"

if [[ -z "$SSH_HOST" || -z "$SSH_USER" ]]; then
  echo "Usage: ./scripts/deploy.sh <ssh_host> <ssh_user> [ssh_port]"
  exit 1
fi

REMOTE_BASE="/var/www/site-name.ru"
RELEASE_NAME="$(date +%Y%m%d%H%M%S)"
REMOTE_RELEASES="$REMOTE_BASE/releases"
REMOTE_RELEASE="$REMOTE_RELEASES/$RELEASE_NAME"
REMOTE_CURRENT="$REMOTE_BASE/current"
REMOTE_SHARED_ENV="$REMOTE_BASE/shared/.env"

echo "Creating release directory on server..."
ssh -p "$SSH_PORT" "$SSH_USER@$SSH_HOST" "mkdir -p '$REMOTE_RELEASE' '$REMOTE_RELEASES' '$REMOTE_BASE/shared'"

echo "Uploading project files..."
rsync -az --delete \
  --exclude ".git" \
  --exclude ".idea" \
  --exclude "backend/vendor" \
  -e "ssh -p $SSH_PORT" \
  ./ "$SSH_USER@$SSH_HOST:$REMOTE_RELEASE/"

echo "Running remote deployment steps..."
ssh -p "$SSH_PORT" "$SSH_USER@$SSH_HOST" "cd '$REMOTE_RELEASE/backend' \
  && composer install --no-dev --optimize-autoloader \
  && ln -sfn '$REMOTE_SHARED_ENV' .env \
  && grep -q '^REDIS_HOST=' .env \
  && grep -q '^REDIS_PORT=' .env \
  && php vendor/bin/phinx migrate -e production \
  && php bin/console create:superadmin \
  && ln -sfn '$REMOTE_RELEASE' '$REMOTE_CURRENT'"

echo "Deployment finished. Run health check manually:"
echo "curl -f https://site-name.ru/health"
echo "Also verify Redis connectivity from app server:"
echo "php -r 'require \"vendor/autoload.php\"; require \"src/Config/bootstrap.php\"; \$c = App\\\\Cache\\\\RedisFactory::createFromEnv(); var_dump(\$c->ping());'"
