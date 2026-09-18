# GridWise — LLM-Assisted Operator Directive Interpretation

Smart Campus Energy Optimization Challenge · BUP CSE Fest 2026 Hackathon · Online Preliminary

A single HTTP service that reads free-text campus operator notes with a language model, converts them into
machine-checkable directives behind deterministic guardrails, and returns a provably cost-optimal, fully valid
24-hour energy schedule.

```
operator notes ──▶ LLM (structured JSON) ──▶ deterministic guardrails ──▶ linear-program optimizer ──▶ replay verifier ──▶ response
```

---

## Contents

1. [Quickstart](#quickstart)
2. [Endpoints](#endpoints)
3. [Architecture](#architecture)
4. [The LLM's role](#the-llms-role)
5. [Guardrails](#guardrails)
6. [Optimizer](#optimizer)
7. [Replay verifier](#replay-verifier)
8. [Configuration](#configuration)
9. [Testing](#testing)
10. [Docker](#docker)
11. [Running behind a proxy or tunnel](#running-behind-a-proxy-or-tunnel)
12. [Operations console](#operations-console)
13. [Dependencies and credits](#dependencies-and-credits)
14. [Known limitations](#known-limitations)
15. [Secret handling](#secret-handling)

---

## Quickstart

From a clean machine. Requires **PHP 8.3+**, **Composer 2**, **Node 20+** and **npm**.

```bash
git clone <repository-url> gridwise
cd gridwise

composer install
cp .env.example .env
php artisan key:generate

# Set your model provider credentials (see Configuration)
#   GRIDWISE_LLM_DRIVER=gemini
#   GEMINI_API_KEY=<your key>

npm install
npm run build

php artisan serve --host=0.0.0.0 --port=8000
```

The service is now on `http://127.0.0.1:8000`.

### Verify `/health`

```bash
curl -s http://127.0.0.1:8000/health
```

```json
{"status":"ok"}
```

### Verify `/optimize-energy`

```bash
curl -s -X POST http://127.0.0.1:8000/optimize-energy \
  -H 'Content-Type: application/json' \
  -d '{
    "scenario_id": "GRID-101",
    "operator_notes": [
      "Facilities will wash the rooftop solar panels from noon until 2 PM. During cleaning, usable solar should be treated as roughly 25% of the forecast.",
      "The sports office moved next month'"'"'s registration deadline."
    ],
    "hours": [
      {"hour":0,"demand_kwh":90,"solar_kwh":0,"tariff_bdt_per_kwh":6},
      {"hour":1,"demand_kwh":85,"solar_kwh":0,"tariff_bdt_per_kwh":6},
      {"hour":2,"demand_kwh":80,"solar_kwh":0,"tariff_bdt_per_kwh":5},
      {"hour":3,"demand_kwh":80,"solar_kwh":0,"tariff_bdt_per_kwh":5},
      {"hour":4,"demand_kwh":85,"solar_kwh":0,"tariff_bdt_per_kwh":5},
      {"hour":5,"demand_kwh":95,"solar_kwh":0,"tariff_bdt_per_kwh":6},
      {"hour":6,"demand_kwh":110,"solar_kwh":5,"tariff_bdt_per_kwh":8},
      {"hour":7,"demand_kwh":130,"solar_kwh":20,"tariff_bdt_per_kwh":10},
      {"hour":8,"demand_kwh":150,"solar_kwh":50,"tariff_bdt_per_kwh":12},
      {"hour":9,"demand_kwh":165,"solar_kwh":90,"tariff_bdt_per_kwh":14},
      {"hour":10,"demand_kwh":175,"solar_kwh":130,"tariff_bdt_per_kwh":16},
      {"hour":11,"demand_kwh":180,"solar_kwh":160,"tariff_bdt_per_kwh":16},
      {"hour":12,"demand_kwh":185,"solar_kwh":180,"tariff_bdt_per_kwh":15},
      {"hour":13,"demand_kwh":180,"solar_kwh":170,"tariff_bdt_per_kwh":14},
      {"hour":14,"demand_kwh":170,"solar_kwh":140,"tariff_bdt_per_kwh":13},
      {"hour":15,"demand_kwh":165,"solar_kwh":90,"tariff_bdt_per_kwh":14},
      {"hour":16,"demand_kwh":170,"solar_kwh":45,"tariff_bdt_per_kwh":18},
      {"hour":17,"demand_kwh":185,"solar_kwh":10,"tariff_bdt_per_kwh":22},
      {"hour":18,"demand_kwh":205,"solar_kwh":0,"tariff_bdt_per_kwh":28},
      {"hour":19,"demand_kwh":215,"solar_kwh":0,"tariff_bdt_per_kwh":30},
      {"hour":20,"demand_kwh":205,"solar_kwh":0,"tariff_bdt_per_kwh":26},
      {"hour":21,"demand_kwh":175,"solar_kwh":0,"tariff_bdt_per_kwh":18},
      {"hour":22,"demand_kwh":135,"solar_kwh":0,"tariff_bdt_per_kwh":10},
      {"hour":23,"demand_kwh":105,"solar_kwh":0,"tariff_bdt_per_kwh":7}
    ],
    "battery": {
      "capacity_kwh": 220,
      "initial_energy_kwh": 110,
      "minimum_energy_kwh": 40,
      "max_charge_kwh_per_hour": 50,
      "max_discharge_kwh_per_hour": 50
    }
  }'
```

Abridged response:

```json
{
  "scenario_id": "GRID-101",
  "directive_interpretation": [
    {
      "note_index": 0,
      "applies": true,
      "directive_type": "solar_reduction",
      "structured_adjustment": { "hours": [12, 13], "factor": 0.25 },
      "explanation": "Usable solar is limited to 25% of the forecast during the panel-cleaning window."
    },
    {
      "note_index": 1,
      "applies": false,
      "directive_type": "no_op",
      "structured_adjustment": null,
      "explanation": "This note does not affect the 24-hour energy schedule."
    }
  ],
  "hourly_plan": [
    { "hour": 0, "grid_kwh": 90, "solar_used_kwh": 0, "battery_action": "idle", "battery_kwh": 0, "battery_energy_after_kwh": 110 }
  ],
  "total_grid_kwh": 2692.5,
  "total_cost_bdt": 38365,
  "peak_grid_kwh": 175,
  "plan_summary": "2 operator notes interpreted, 1 applied as solar_reduction. ..."
}
```

### Run the public sample pack

```bash
php artisan gridwise:samples
```

```
 case      interpretation  schedule  cost      quality  latency
 SAMPLE-01 2/2 exact       valid     38365.00  1.000    1243 ms
 ...
 SAMPLE-10 3/3 exact       valid     41620.00  1.000    1102 ms

 Cases run ................................. 10
 Failures .................................. 0
 Interpretation match ................. 10 / 10
 Optimization quality ............. 10.00 / 10
```

The harness replays every returned schedule against the **organizer ground-truth directives**, not against our own
interpretation, so a correct extraction that is not actually applied still fails.

Against a deployed URL:

```bash
php artisan gridwise:samples --url=https://your-deployment.example.com
```

---

## Endpoints

| Method | Path               | Purpose                                                            |
| ------ | ------------------ | ------------------------------------------------------------------ |
| `GET`  | `/health`          | Readiness. Returns `{"status":"ok"}` with HTTP 200.                 |
| `POST` | `/optimize-energy` | Interpretation plus the 24-hour schedule.                           |
| `GET`  | `/`                | Operations console (browser UI; not part of the judged contract).   |

Status codes:

| Code  | Meaning                                                                         |
| ----- | ------------------------------------------------------------------------------- |
| `200` | Successful health or optimisation response.                                     |
| `400` | Malformed JSON, non-object body, or a request that misses the scenario schema.   |
| `422` | Well-formed request describing an impossible battery (e.g. initial > capacity).  |
| `404` | Unknown endpoint, as a clean JSON object.                                       |
| `500` | Controlled internal error. No stack traces, no secrets, no provider detail.      |

---

## Architecture

```
POST /optimize-energy
      │
      ▼
EnsureJsonBodyIsValid ──────────── rejects non-JSON bodies with 400
      │
      ▼
OptimizeEnergyRequest ──────────── schema validation → 400, semantic validation → 422
      │
      ▼
GridWiseService
      │
      ├─▶ DirectiveInterpreter
      │       ├─ InterpretationPrompt   system + user prompt, JSON schema
      │       ├─ LlmManager → LlmDriver  gemini | openai | groq | anthropic | ollama
      │       ├─ DirectiveGuard          deterministic validation of model output
      │       ├─ repair pass             re-asks only for notes the guard rejected
      │       └─ FallbackInterpreter     controlled safe failure if the provider is down
      │
      ├─▶ ConstraintModel               directives → per-hour numeric constraints
      │
      ├─▶ EnergyOptimizer               linear program → exact minimum-cost schedule
      │       └─ Simplex                two-phase primal simplex, Bland anti-cycling
      │
      └─▶ ScheduleReplayer              independent hour-by-hour judge-equivalent replay
      │
      ▼
   response JSON
```

Source layout:

```
app/GridWise/
├── Dto/                 Scenario, Battery, Directive, DirectiveType, ConstraintModel, HourPlan, Schedule
├── Llm/                 LlmManager, Contracts/LlmDriver, Drivers/{Gemini,OpenAi,Groq,Anthropic,Ollama,Mock}
├── Interpretation/      InterpretationPrompt, DirectiveGuard, FallbackInterpreter, DirectiveInterpreter
├── Optimizer/           LinearProgram, Simplex, EnergyOptimizer
├── Validation/          ScheduleReplayer, ReplayReport
└── GridWiseService.php  orchestration
```

---

## The LLM's role

The language model is the component that reads natural language. It is on the critical path: its structured output is
what becomes the optimizer's constraints. Nothing else in the system reads the note text to decide a directive.

The model is asked for a flat, easy-to-produce object and the service assembles the exact
`structured_adjustment` shape itself:

```json
{
  "interpretations": [
    {
      "note_index": 0,
      "directive_type": "solar_reduction",
      "windows": [{ "start_hour": 12, "end_hour": 14 }],
      "value": 0.25,
      "explanation": "panel cleaning"
    }
  ]
}
```

Two deliberate choices make paraphrase handling robust:

- **Clock times, not hour arrays.** The model reports `start_hour` / `end_hour` exactly as the note words them
  ("noon until 2 PM" → `12` and `14`). The service applies the start-inclusive / end-exclusive whole-hour rule, so the
  classic off-by-one never depends on model arithmetic. `windows` is a list, so a note naming two separate periods is
  handled without losing either.
- **Scenario facts in the prompt.** Battery capacity, initial energy, base minimum and the hourly demand/solar/tariff
  table are supplied, so notes like *"keep at least 50% of the battery capacity"* resolve to kWh correctly.

Structured output is enforced per provider: Gemini `responseSchema`, OpenAI `json_schema` strict mode, Anthropic forced
tool use, Ollama `format`, Groq JSON object mode.

---

## Guardrails

`DirectiveGuard` treats every model response as untrusted data. It runs before any directive reaches the optimizer.

| Check                   | Behaviour                                                                                  |
| ----------------------- | ------------------------------------------------------------------------------------------ |
| Allowed types           | `directive_type` must be one of the six supported values; anything else is rejected.        |
| Note mapping            | Each note index must exist and appear exactly once. Duplicates and unknown indexes rejected.|
| Hours                   | Windows expand to unique integers 0–23 in ascending order. Midnight-crossing windows wrap.  |
| Solar factor            | Clamped to `[0, 1]`. A value like `25` is read as 25% and rewritten to `0.25`.               |
| Battery reserve         | Must be finite, non-negative and at most capacity. A share such as `0.5` is resolved against capacity only when the note itself speaks in proportions. |
| Grid cap                | Must be finite and non-negative.                                                            |
| `applies` semantics     | Built from the type, never from the model: `no_op` → `applies:false` + `null` adjustment; every other type → `applies:true` with the exact required shape. |
| No invention            | Demand, solar, tariff and battery parameters are never taken from the model.                |

Rejected notes are **re-asked** in a second, narrower call containing only those notes. If the guard still refuses them,
a deterministic fallback interpreter answers, and if that finds nothing the note becomes an explicit `no_op`. The
service never crashes on bad model output and never invents an unsupported directive.

---

## Optimizer

The schedule is a linear program solved exactly, not a heuristic.

Writing grid purchase out of the problem using the hourly energy balance

```
grid[h] = demand[h] + charge[h] - discharge[h] - solar_used[h]
```

leaves three decision variables per hour and makes the balance equation true by construction. Minimising

```
total_cost_bdt = Σ tariff[h] · grid[h]
```

subject to:

| Constraint                      | Form                                                                     |
| ------------------------------- | ------------------------------------------------------------------------ |
| Non-negative import             | `charge[h] − discharge[h] − solar_used[h] ≥ −demand[h]`                   |
| `max_grid_window`               | `charge[h] − discharge[h] − solar_used[h] ≤ cap[h] − demand[h]`           |
| Effective solar                 | `solar_used[h] ≤ solar[h] · factor[h]`                                    |
| Hourly rate limits              | `charge[h] ≤ max_charge`, `discharge[h] ≤ max_discharge`                  |
| `no_charge_window`              | `charge[h]` removed from the program                                      |
| `no_discharge_window`           | `discharge[h]` removed from the program                                   |
| State of charge and reserve     | `minimum[h] ≤ initial + Σ_{k≤h}(charge−discharge) ≤ capacity`             |
| End-of-day neutrality           | `Σ(charge − discharge) = 0`                                               |

`Simplex` is a dense two-phase primal simplex using Dantzig pricing, switching to Bland's rule on stalling to guarantee
termination on degenerate vertices. Typical solve time is **1–3 ms**.

Because a vertex solution may charge and discharge in the same hour at zero cost difference, the two are netted before
`battery_action` is chosen. Netting preserves the state of charge, the energy balance and both rate limits exactly.

If a caller ever supplies contradictory hard directives, the optimizer drops them in a documented order
(`max_grid_window` → `minimum_battery_reserve` → `no_discharge_window` → `no_charge_window` → `solar_reduction`) and
reports what it relaxed, rather than returning a 500. Organizer scoring scenarios are guaranteed feasible, so this path
is a safety net only.

**Verified:** the optimizer reaches the organizer optimal cost on all ten public sample cases to the cent.

---

## Replay verifier

`ScheduleReplayer` is an independent re-implementation of the judge's replay pass that shares no code with the
optimizer. Every response is checked before it is returned:

- exactly 24 unique hours, 0 through 23
- all reported values finite and non-negative
- `battery_action` valid, `battery_kwh = 0` when idle
- battery transition, capacity, active minimum and hourly rate limits
- `solar_used_kwh` within effective solar after `solar_reduction`
- `no_charge_window`, `no_discharge_window`, `max_grid_window` obeyed
- the energy-balance equation every hour
- final battery energy equals initial battery energy
- `total_grid_kwh`, `total_cost_bdt` and `peak_grid_kwh` recalculated from `hourly_plan`

Tolerance is 0.01 kWh / 0.01 BDT, matching the Problem Statement. A failure is logged as an error rather than silently
shipped.

---

## Configuration

All configuration is environment variables. Nothing secret is committed.

| Variable                     | Default             | Meaning                                                  |
| ---------------------------- | ------------------- | -------------------------------------------------------- |
| `GRIDWISE_LLM_DRIVER`        | `gemini`            | `gemini`, `openai`, `groq`, `anthropic`, `ollama`, `mock` |
| `GRIDWISE_LLM_TIMEOUT`       | `12`                | Per-call budget in seconds                                |
| `GRIDWISE_LLM_CONNECT_TIMEOUT` | `4`               | Connection timeout in seconds                             |
| `GRIDWISE_LLM_RETRIES`       | `2`                 | Retries per provider call                                 |
| `GRIDWISE_LLM_CACHE`         | `true`              | Cache interpretations keyed by notes + battery + model    |
| `GRIDWISE_LLM_CACHE_TTL`     | `3600`              | Cache lifetime in seconds                                 |
| `GRIDWISE_FALLBACK`          | `true`              | Enable the deterministic safe-failure interpreter         |
| `TRUSTED_PROXIES`            | `*`                 | Proxies whose `X-Forwarded-*` headers are trusted         |
| `APP_FORCE_HTTPS`            | `false`             | Force https URL generation behind a terminating proxy     |
| `GEMINI_API_KEY`             | —                   | Google AI Studio key                                      |
| `GEMINI_MODEL`               | `gemini-2.5-flash`  | Gemini model id                                           |
| `GEMINI_THINKING_BUDGET`     | `0`                 | Thinking tokens; `0` keeps latency low                    |
| `OPENAI_API_KEY` / `OPENAI_MODEL` | — / `gpt-4.1-mini` | OpenAI credentials                                   |
| `GROQ_API_KEY` / `GROQ_MODEL` | — / `llama-3.3-70b-versatile` | Groq credentials                          |
| `ANTHROPIC_API_KEY` / `ANTHROPIC_MODEL` | — / `claude-haiku-4-5-20251001` | Anthropic credentials         |
| `OLLAMA_BASE_URL` / `OLLAMA_MODEL` | `http://127.0.0.1:11434` / `llama3.1:8b` | Local model            |

**Model / provider used for submission:** Google Gemini, model `gemini-2.5-flash`, called over the Generative Language
API with a response schema and `temperature = 0`.

`GRIDWISE_LLM_DRIVER=mock` disables the model entirely and exercises the deterministic fallback path. It exists for
offline development and CI only — it does **not** satisfy the challenge's LLM requirement and must not be used for
judging.

---

## Testing

```bash
php artisan test          # 55 tests
./vendor/bin/pint --test  # code style
php artisan gridwise:samples
```

Coverage:

| Suite                     | What it locks down                                                                 |
| ------------------------- | ----------------------------------------------------------------------------------- |
| `SimplexTest`             | Bounded minimisation, equalities, infeasibility, unboundedness, objective offset.    |
| `DirectiveGuardTest`      | End-exclusive expansion, multi-window merge, midnight wrap, percentage repair, reserve resolution, rejection of unsupported types and missing numbers, `no_op` semantics. |
| `PublicSampleCasesTest`   | All ten public cases: organizer optimal cost, ground-truth replay validity, one ordered interpretation entry per note with a valid `structured_adjustment`. |
| `ApiContractTest`         | Health, full response schema, totals consistency, 400 / 422 / 404 handling, provider-failure resilience, no internals in error bodies. |

The test suite runs with `GRIDWISE_LLM_DRIVER=mock` so it needs no network and no API key.

---

## Docker

```bash
docker build -t gridwise-llm:1.0.0 .

docker run --rm -p 8080:8080 \
  -e GRIDWISE_LLM_DRIVER=gemini \
  -e GEMINI_API_KEY=<your key> \
  gridwise-llm:1.0.0

curl -s http://127.0.0.1:8080/health
```

The image serves with FrankenPHP on `0.0.0.0:8080`, runs `APP_DEBUG=false`, generates a runtime `APP_KEY` if none is
supplied, caches config/routes/views at start-up and ships with a `HEALTHCHECK` against `/health`. **No credentials are
baked into the image** — they are supplied with `-e` at run time.

`docker-compose.yml` is provided for the same thing with an `.env` file.

---

## Running behind a proxy or tunnel

When the service sits behind Cloudflare Tunnel, ngrok, nginx or a load balancer, TLS is terminated at the proxy and the
origin is reached over plain HTTP. Laravel would otherwise generate `http://` URLs on an `https://` page, and the
browser blocks them as mixed content.

The application trusts the standard forwarded headers, so `X-Forwarded-Proto: https` is enough for generated URLs and
Vite asset tags to come out as `https://`:

| Variable           | Default | Meaning                                                                        |
| ------------------ | ------- | ------------------------------------------------------------------------------ |
| `TRUSTED_PROXIES`  | `*`     | Proxies whose `X-Forwarded-*` headers are honoured. `*` is correct when the origin is only reachable through the tunnel; pin it to your proxy CIDRs if the origin port is exposed publicly. |
| `APP_FORCE_HTTPS`  | `false` | Forces `https://` URL generation even if the proxy strips the headers.          |
| `APP_URL`          | —       | Set to the public origin. An `https://` value also forces the https scheme.     |

For a Cloudflare Tunnel in front of this service:

```bash
APP_URL=https://your-domain.example
TRUSTED_PROXIES=*
```

```bash
cloudflared tunnel --url http://127.0.0.1:8000
```

The judged endpoints `/health` and `/optimize-energy` generate no URLs and work through any proxy unchanged. The
operations console posts to a root-relative path, so it inherits the page's scheme regardless of configuration.

---

## Operations console

`GET /` serves a browser console for demonstrating and debugging the pipeline. It is not part of the judged API
contract and posts to its own internal route.

It shows the ten public sample cases, an editable note and battery panel, live `/health` state, the resulting
interpretation with a per-note match indicator against the public reference, the 24-hour plan as a chart or a table,
the battery state-of-charge trace against its active reserve floor, the replay verification result, and the raw API
response.

**Chart design.** The plan is drawn as a stacked column chart - solar, battery discharge and grid import - against a
stepped demand reference line; anything above that line is energy being stored. Tariff is a **separate chart on its own
scale** sharing the same hour axis, never a second y-axis on the same plot, because two arbitrary scales on one frame
invent a correlation that is not in the data. Every hour carries a hover and keyboard tooltip, the series palette is
validated for colour-vision deficiency and contrast in both themes, a legend is always present, and the **table view is
the WCAG-clean twin** so no value is reachable only by hovering.

**Theme.** A navy control-room dark theme is the default, with a light theme alongside it. Each has its own set of
series steps chosen for that surface, not an inverted copy, and both were re-validated against the surface they
actually render on. The toggle persists to `localStorage`.

**Default state.** The console runs the first public sample automatically on load, so it opens on a finished plan
rather than an empty placeholder.

**Motion.** Entrance staggers, column growth, line draw-on and value count-ups are all suppressed under
`prefers-reduced-motion: reduce`.

---

## Dependencies and credits

| Component                       | Use                                                      |
| ------------------------------- | -------------------------------------------------------- |
| Laravel 13 (PHP)                | HTTP routing, validation, container, configuration, cache |
| Google Gemini API               | Operator-note interpretation                              |
| Tailwind CSS 4 + Vite           | Operations console styling and bundling                   |
| FrankenPHP                      | Production application server in the Docker image          |
| PHPUnit 12, Laravel Pint        | Test suite and code style                                 |

The linear-program formulation, the simplex solver, the guardrail layer, the replay verifier and the console charts are
written for this submission; no optimisation library or LP solver package is used. Charts are hand-rolled SVG with no
charting dependency.

AI coding assistance was used during development, as permitted by the rulebook.

---

## Known limitations

- Overlapping directives of the same type are combined by taking the most restrictive value (smallest solar factor,
  highest reserve, lowest grid cap, union of blocked hours). The Problem Statement does not define an alternative.
- A window whose end hour is not greater than its start hour is treated as crossing midnight. A note meaning a
  zero-length window would therefore be read as a 24-hour window.
- The interpretation cache is keyed on notes plus battery parameters. Two scenarios with identical notes and identical
  battery settings but different hourly data reuse the same interpretation; this is correct for every supported
  directive type, since none of them depends on the demand, solar or tariff series.
- `plan_summary` is generated deterministically from the finished plan rather than by the model, which keeps latency
  down. The model's contribution is the directive interpretation, as the rules require.
- Single-node, in-memory cache. Horizontal scaling would need a shared cache store.

---

## Secret handling

- No API keys, tokens or `.env` files are committed. `.env` is git-ignored and `.env.example` ships with empty values.
- No secret, raw prompt or stack trace appears in any API response. Error bodies are fixed strings with an error code.
- Provider failures are logged with the driver name and exception class only — never the key, the URL or the payload.
- The Docker image contains no credentials; they are injected at run time.
- Only the synthetic challenge data supplied by the harness is used.
