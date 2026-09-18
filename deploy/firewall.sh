#!/usr/bin/env bash
# =============================================================================
# Configure ufw to expose only SSH + HTTP + HTTPS. Run as root, ONCE.
# =============================================================================
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
    echo "Must run as root:  sudo $0" >&2
    exit 1
fi

apt-get install -y ufw

ufw default deny incoming
ufw default allow outgoing

ufw allow 22/tcp   comment "SSH"
ufw allow 80/tcp   comment "HTTP -> HTTPS redirect + ACME"
ufw allow 443/tcp  comment "HTTPS"

# 8080 is intentionally NOT exposed — only loopback can reach it.
ufw deny 8080/tcp

# Docker sometimes manipulates iptables directly; if it does, this ufw
# block on 8080 may not hold. The safer control is binding to 127.0.0.1
# in docker-compose.yml, which we already do.

ufw --force enable
ufw status verbose
