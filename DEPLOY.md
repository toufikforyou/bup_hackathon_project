# GridOptima — VM Deployment Runbook

Production deployment of the Laravel app to a fresh Ubuntu 22.04/24.04 VM
(`20.2.64.135`) using **FrankenPHP** in Docker, with **nginx** on the host
terminating TLS (Let's Encrypt) for `hackathon.buddy.bd`.

```
internet :80/:443  ──►  host nginx  ──►  127.0.0.1:8080  ──►  FrankenPHP container
                            │
                            └──►  /etc/letsencrypt/live/hackathon.buddy.bd/*.pem
```

---

## 0. Prerequisites (run once on the VM as root)

```bash
apt-get update
apt-get install -y git curl ca-certificates ufw
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg \
  | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
chmod a+r /etc/apt/keyrings/docker.gpg
echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
   https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo $VERSION_CODENAME) stable" \
  > /etc/apt/sources.list.d/docker.list
apt-get update
apt-get install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin nginx
```

Verify:

```bash
docker --version
docker compose version
nginx -v
```

---

## 1. Clone the repo onto the VM

```bash
ssh toufikforyou@20.2.64.135
sudo mkdir -p /opt/gridoptima && sudo chown $USER /opt/gridoptima
git clone <your-repo-url> /opt/gridoptima
cd /opt/gridoptima
```

---

## 2. Create the production env file

```bash
cp deploy/env.production.example .env.production
chmod 600 .env.production

# Generate APP_KEY
openssl rand -base64 32 | tr -d '=' | (echo -n 'APP_KEY=base64:'; cat) >> .env.production
# Or use the PHP one-liner and paste in:
#   php -r "echo 'base64:'.base64_encode(random_bytes(32));"

# Add your Gemini key
nano .env.production
#   GEMINI_API_KEY=AIzaSy...
```

---

## 3. Configure the firewall

```bash
sudo bash deploy/firewall.sh
```

Open ports:
- 22 (SSH)
- 80 (HTTP → HTTPS redirect + ACME)
- 443 (HTTPS)

8080 is intentionally blocked from the public internet; only loopback can
reach it (enforced by `docker-compose.yml` and the ufw deny rule).

---

## 4. Install the nginx site

```bash
sudo cp deploy/nginx-hackathon.conf /etc/nginx/sites-available/hackathon.buddy.bd
# (no symlink yet — certbot will write it after the cert exists)
sudo nginx -t
```

---

## 5. Point DNS (do this BEFORE step 6)

Create an **A record**:

| Host                       | Type | Value         |
|----------------------------|------|---------------|
| `hackathon.buddy.bd`       | A    | `20.2.64.135` |

Wait for propagation:

```bash
dig +short hackathon.buddy.bd     # should return 20.2.64.135
```

---

## 6. Issue the Let's Encrypt certificate

```bash
sudo bash deploy/certbot-init.sh
```

This will:
- install `certbot` + `python3-certbot-nginx`
- create a webroot `/var/www/letsencrypt`
- issue a cert for `hackathon.buddy.bd`
- enable the nginx site, remove the default
- install a daily cron at 03:00 to renew

Test:

```bash
curl -I https://hackathon.buddy.bd/health
```

---

## 7. Build & start the app

```bash
bash deploy/deploy.sh
# or for a clean rebuild:
bash deploy/deploy.sh --no-cache
```

The script will:
1. `docker compose build` the image
2. `docker compose up -d` the container
3. Wait for `GET /health` to return 200
4. POST a 24-hour scenario to `/optimize-energy` and assert the response
   has `hourly_plan` of length 24

---

## 8. Verify externally (the judge)

```bash
# Health (must be 200 within 60s)
curl -i https://hackathon.buddy.bd/health

# Optimization endpoint
curl -i -X POST https://hackathon.buddy.bd/optimize-energy \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  --data-binary @ProblemStatement/BUP_CSE_FEST_2026_Preli_Public_Sample_Cases.json
```

---

## 9. Day-of operations

```bash
# Tail logs
docker compose logs -f gridoptima

# Tail nginx access / error
sudo tail -f /var/log/nginx/hackathon.buddy.bd.access.log
sudo tail -f /var/log/nginx/hackathon.buddy.bd.error.log

# Restart the app after a code change
bash deploy/deploy.sh

# Force certificate renewal (dry run)
sudo certbot renew --dry-run

# Check cert expiry
sudo certbot certificates
```

---

## 10. Troubleshooting

| Symptom | Fix |
|---|---|
| `curl http://127.0.0.1:8080/health` works but `https://hackathon.buddy.bd/health` doesn't | DNS not propagated yet (`dig hackathon.buddy.bd`) |
| `502 Bad Gateway` from nginx | container not running → `docker compose ps`, `docker compose logs gridoptima` |
| `docker compose` says `port is already allocated` | another process on 8080 → `sudo lsof -i :8080` |
| Let's Encrypt fails | Port 80 not reachable from internet, OR DNS A-record wrong, OR webroot `/var/www/letsencrypt` not writable |
| `APP_KEY` missing in logs | forgot to generate it in `.env.production` — re-run `openssl rand -base64 32` and add `APP_KEY=base64:…` |
| Container crashes immediately | `docker compose logs gridoptima` — usually a bad env var |

---

## 11. Security checklist (per hackathon rules)

- [x] No API keys in repo (`.env.production` is in `.gitignore`)
- [x] No secrets in logs (`LOG_CHANNEL=stderr` + structured logs)
- [x] TLS 1.2/1.3 only, HSTS enabled
- [x] Container run as non-root (`www-data`)
- [x] Container only reachable on loopback
- [x] Firewall blocks everything except 22/80/443
- [x] Cert auto-renews (cron + certbot systemd timer)
- [x] `TRUSTED_PROXIES=127.0.0.1` so Laravel trusts `X-Forwarded-For` only from host nginx
