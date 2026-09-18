#!/usr/bin/env bash
# =============================================================================
# Renew Let's Encrypt certs and reload nginx. Suitable for daily cron.
#   0 3 * * *  /root/deploy/certbot-renew.sh >> /var/log/certbot-renew.log 2>&1
# =============================================================================
set -euo pipefail

certbot renew --quiet --deploy-hook "systemctl reload nginx"
echo "[$(date -Iseconds)] renewal attempt finished"
