#!/bin/sh
set -e

# Render (and similar PaaS hosts) set $PORT and require the app to bind to
# it. Local `docker compose up` doesn't set $PORT, so this falls back to 80
# to match the existing "80:80" mapping in docker-compose.yml.
PORT="${PORT:-80}"

sed -ri "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# The FastAPI (ai-api) service gets its own separate URL once this stops
# running on one machine, so the frontend can't hardcode localhost:8000
# anymore. reconciliation.html and trends.html use the placeholder
# __AI_API_BASE__ instead — swap in the real value here, once, at boot.
#
# Note: docker-compose.yml bind-mounts the repo into the container, so this
# edits the actual files on your host the first time it runs locally (the
# default value matches the old hardcoded localhost:8000, so it's harmless,
# but `git status` will show the two HTML files as modified after your first
# `docker compose up`). Run `git checkout reconciliation.html trends.html`
# afterward if you'd rather keep the placeholder in source control.
AI_API_BASE="${AI_API_BASE:-http://localhost:8000}"
if grep -ql "__AI_API_BASE__" /var/www/html/*.html 2>/dev/null; then
  find /var/www/html -maxdepth 1 -name "*.html" -exec \
    sed -i "s#__AI_API_BASE__#${AI_API_BASE}#g" {} \;
fi

exec "$@"
