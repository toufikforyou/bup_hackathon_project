#!/usr/bin/env bash
# =============================================================================
# Initial Let's Encrypt certificate issuance for hackathon.buddy.bd.
# Run this ONCE on the VM as root AFTER DNS A-record points to 20.2.64.135.
# =============================================================================
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
    echo "Must run as root:  sudo $0" >&2
    exit 1
fi

DOMAIN="hackathon.buddy.bd"
EMAIL="${ADMIN_EMAIL:-admin@${DOMAIN}}"     # certbot notifications
WEBROOT="/var/www/letsencrypt"

# --- 1. Install certbot + nginx plugin if missing ---------------------------
if ! command -v certbot >/dev/null 2>&1; then
    apt-get update
    apt-get install -y certbot python3-certbot-nginx
fi

# --- 2. Webroot for http-01 challenge --------------------------------------
mkdir -p "$WEBROOT"
chown -R www-data:www-data "$WEBROOT"

# --- 3. (optional) self-signed cert for the bare IP, just in case ---------
mkdir -p /etc/ssl/private
if [ ! -f /etc/ssl/private/ip-selfsigned.crt ]; then
    openssl req -x509 -nodes -days 3650 \
        -newkey rsa:2048 \
        -keyout /etc/ssl/private/ip-selfsigned.key \
        -out    /etc/ssl/private/ip-selfsigned.crt \
        -subj "/CN=20.2.64.135"
    chmod 600 /etc/ssl/private/ip-selfsigned.key
fi

# --- 4. Issue cert ----------------------------------------------------------
certbot certonly \
    --webroot \
    --webroot-path="$WEBROOT" \
    -d "$DOMAIN" \
    --non-interactive \
    --agree-tos \
    -m "$EMAIL" \
    --key-type rsa \
    --rsa-key-size 2048

# --- 5. Enable + reload nginx ----------------------------------------------
ln -sf /etc/nginx/sites-available/hackathon.buddy.bd \
       /etc/nginx/sites-enabled/hackathon.buddy.bd

# Remove default site if present (conflicts with our catch-all :80).
rm -f /etc/nginx/sites-enabled/default

nginx -t
systemctl reload nginx

# --- 6. Install renewal cron (certbot ships a systemd timer; double-belt) --
cat >/etc/cron.d/certbot-renew <<'CRON'
0 3 * * * root /usr/bin/certbot renew --quiet --deploy-hook "systemctl reload nginx"
CRON
chmod 644 /etc/cron.d/certbot-renew

echo ""
echo "Done. Test:    curl -I https://${DOMAIN}/health"
echo "Renewal:      certbot renew --dry-run"
