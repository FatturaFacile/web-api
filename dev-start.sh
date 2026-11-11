#!/bin/bash

# Exit immediately if a command exits with a non-zero status
set -e

echo "----------------------------------------------------"
echo "1/4. Starting supporting Docker containers..."
echo "----------------------------------------------------"
docker compose -f docker-compose.local.yml up -d --wait

echo "----------------------------------------------------"
echo "2/4. Launching services in separate terminals..."
echo "----------------------------------------------------"

# Execution commands (with Xdebug configured for PhpStorm)
CMD_PHP="XDEBUG_MODE=debug PHP_IDE_CONFIG='serverName=localhost' php artisan serve --host=127.0.0.1 --port=8001"
CMD_CADDY="caddy run --config Caddyfile.local --adapter caddyfile"
CMD_QUEUE="php artisan queue:work"

# If Windows Terminal (wt.exe) is available, launch tabs in a single window
if command -v wt.exe &> /dev/null; then
    echo "Windows Terminal detected. Opening tabbed processes..."
    wt.exe -d "." --title "PHP Artisan Serve (Port 8001)" bash -c "$CMD_PHP; exec bash" \; \
           new-tab -d "." --title "Caddy TLS Proxy" bash -c "$CMD_CADDY; exec bash" \; \
           new-tab -d "." --title "Laravel Queue Worker" bash -c "$CMD_QUEUE; exec bash"
else
    echo "Windows Terminal not found. Opening standalone windows..."
    start "PHP Artisan Serve" bash -c "$CMD_PHP; exec bash"
    start "Caddy TLS Proxy" bash -c "$CMD_CADDY; exec bash"
    start "Laravel Queue Worker" bash -c "$CMD_QUEUE; exec bash"
fi

echo "----------------------------------------------------"
echo "All processes started successfully!"
echo "Ensure 'Start Listening for PHP Debug Connections' (green phone icon) is active in PhpStorm."
echo "----------------------------------------------------"