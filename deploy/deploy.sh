#!/usr/bin/env bash
# =============================================================================
# One-command deployment.
#   bash deploy/deploy.sh            # build + restart
#   bash deploy/deploy.sh --no-cache # full rebuild
# =============================================================================
set -euo pipefail

cd "$(dirname "$0")/.."
REPO_ROOT="$(pwd)"

echo "==> Repo root: $REPO_ROOT"

# --- 0. Pre-flight ----------------------------------------------------------
for bin in docker git; do
    if ! command -v "$bin" >/dev/null 2>&1; then
        echo "Missing dependency: $bin" >&2
        exit 1
    fi
done

if ! docker compose version >/dev/null 2>&1; then
    echo "docker compose plugin not found. Install with:"
    echo "  sudo apt-get install docker-compose-plugin" >&2
    exit 1
fi

if [ ! -f .env.production ]; then
    echo ".env.production missing. Copy deploy/env.production.example and fill secrets." >&2
    exit 1
fi

# --- 1. Build ---------------------------------------------------------------
BUILD_FLAGS=""
if [ "${1:-}" = "--no-cache" ]; then BUILD_FLAGS="--no-cache"; fi

echo "==> docker compose build $BUILD_FLAGS"
docker compose build $BUILD_FLAGS

# --- 2. Up (idempotent) ----------------------------------------------------
echo "==> docker compose up -d"
docker compose up -d

# --- 3. Wait for /health ---------------------------------------------------
echo "==> Waiting for /health ..."
for i in $(seq 1 30); do
    if curl -fsS --max-time 3 http://127.0.0.1:8080/health >/dev/null; then
        echo "    ok (attempt $i)"
        break
    fi
    sleep 2
    if [ "$i" = "30" ]; then
        echo "Health check failed after 60s. Recent logs:" >&2
        docker compose logs --tail=80 gridoptima >&2
        exit 1
    fi
done

# --- 4. Smoke test the JSON contract --------------------------------------
echo "==> Smoke test: POST /optimize-energy with a minimal payload"
RESP=$(curl -fsS --max-time 15 \
    -H "Accept: application/json" -H "Content-Type: application/json" \
    -X POST http://127.0.0.1:8080/optimize-energy \
    -d '{
      "scenario_id": "smoke-'"$RANDOM"'",
      "operator_notes": ["The cafeteria menu changes tomorrow."],
      "hours": [
        {"hour":0,"demand_kwh":80,"solar_kwh":0,"tariff_bdt_per_kwh":5},
        {"hour":1,"demand_kwh":80,"solar_kwh":0,"tariff_bdt_per_kwh":5},
        {"hour":2,"demand_kwh":80,"solar_kwh":0,"tariff_bdt_per_kwh":5},
        {"hour":3,"demand_kwh":80,"solar_kwh":0,"tariff_bdt_per_kwh":5},
        {"hour":4,"demand_kwh":80,"solar_kwh":0,"tariff_bdt_per_kwh":5},
        {"hour":5,"demand_kwh":80,"solar_kwh":0,"tariff_bdt_per_kwh":5},
        {"hour":6,"demand_kwh":90,"solar_kwh":5,"tariff_bdt_per_kwh":8},
        {"hour":7,"demand_kwh":110,"solar_kwh":20,"tariff_bdt_per_kwh":10},
        {"hour":8,"demand_kwh":130,"solar_kwh":50,"tariff_bdt_per_kwh":12},
        {"hour":9,"demand_kwh":145,"solar_kwh":90,"tariff_bdt_per_kwh":14},
        {"hour":10,"demand_kwh":155,"solar_kwh":130,"tariff_bdt_per_kwh":16},
        {"hour":11,"demand_kwh":160,"solar_kwh":160,"tariff_bdt_per_kwh":16},
        {"hour":12,"demand_kwh":165,"solar_kwh":180,"tariff_bdt_per_kwh":15},
        {"hour":13,"demand_kwh":160,"solar_kwh":170,"tariff_bdt_per_kwh":14},
        {"hour":14,"demand_kwh":150,"solar_kwh":140,"tariff_bdt_per_kwh":13},
        {"hour":15,"demand_kwh":145,"solar_kwh":90,"tariff_bdt_per_kwh":14},
        {"hour":16,"demand_kwh":150,"solar_kwh":45,"tariff_bdt_per_kwh":18},
        {"hour":17,"demand_kwh":165,"solar_kwh":10,"tariff_bdt_per_kwh":22},
        {"hour":18,"demand_kwh":185,"solar_kwh":0,"tariff_bdt_per_kwh":28},
        {"hour":19,"demand_kwh":195,"solar_kwh":0,"tariff_bdt_per_kwh":30},
        {"hour":20,"demand_kwh":185,"solar_kwh":0,"tariff_bdt_per_kwh":26},
        {"hour":21,"demand_kwh":155,"solar_kwh":0,"tariff_bdt_per_kwh":18},
        {"hour":22,"demand_kwh":115,"solar_kwh":0,"tariff_bdt_per_kwh":10},
        {"hour":23,"demand_kwh":85,"solar_kwh":0,"tariff_bdt_per_kwh":7}
      ],
      "battery": {
        "capacity_kwh":220,"initial_energy_kwh":110,"minimum_energy_kwh":40,
        "max_charge_kwh_per_hour":50,"max_discharge_kwh_per_hour":50
      }
    }' 2>&1) || {
        echo "Smoke test failed. Last response: $RESP" >&2
        docker compose logs --tail=80 gridoptima >&2
        exit 1
    }

# Quick contract assertion: scenario_id echoed, hourly_plan has 24 entries.
SCENARIO_ID=$(echo "$RESP" | php -r '$j=json_decode(file_get_contents("php://stdin"),true); echo $j["scenario_id"]??"";')
PLAN_LEN=$(echo "$RESP" | php -r '$j=json_decode(file_get_contents("php://stdin"),true); echo isset($j["hourly_plan"])?count($j["hourly_plan"]):-1;')

echo "    scenario_id echoed: $SCENARIO_ID"
echo "    hourly_plan length: $PLAN_LEN (expect 24)"

if [ "$PLAN_LEN" != "24" ]; then
    echo "Smoke test: hourly_plan length != 24" >&2
    exit 1
fi

echo ""
echo "✅ Deployment complete."
echo "   Local health:   curl -fsS http://127.0.0.1:8080/health"
echo "   Public health:  curl -fsS https://hackathon.buddy.bd/health"
echo "   Tail logs:      docker compose logs -f gridoptima"
