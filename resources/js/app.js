import { createTooltip, renderEnergyChart, renderGauge, renderSocChart, renderTariffChart, SERIES_META } from './charts.js';

const DIRECTIVE_META = {
    solar_reduction: ['var(--series-solar)', 'Solar reduction'],
    minimum_battery_reserve: ['var(--series-battery)', 'Minimum reserve'],
    no_charge_window: ['var(--series-tariff)', 'No charging'],
    no_discharge_window: ['var(--status-serious)', 'No discharging'],
    max_grid_window: ['var(--series-grid)', 'Grid cap'],
    no_op: ['var(--text-muted)', 'No operation'],
};

const BATTERY_SLIDERS = [
    { key: 'capacity_kwh', label: 'Capacity', unit: 'kWh', min: 50, max: 1000, step: 10 },
    { key: 'initial_energy_kwh', label: 'Initial energy', unit: 'kWh', min: 0, step: 5 },
    { key: 'minimum_energy_kwh', label: 'Minimum energy', unit: 'kWh', min: 0, step: 5 },
    { key: 'max_charge_kwh_per_hour', label: 'Max charge per hour', unit: 'kWh', min: 0, max: 300, step: 5 },
    { key: 'max_discharge_kwh_per_hour', label: 'Max discharge per hour', unit: 'kWh', min: 0, max: 300, step: 5 },
];

const REPLAY_CHECKS = [
    '24 unique hours, 0 through 23',
    'hourly energy balance',
    'solar within effective availability',
    'battery transitions, bounds and rate limits',
    'directive windows, reserve and grid caps',
    'end-of-day battery neutrality',
    'reported totals match the plan',
];

const SVG_NS = 'http://www.w3.org/2000/svg';

const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

function boot(root) {
    const samples = JSON.parse(root.dataset.samples || '[]');
    const provider = JSON.parse(root.dataset.provider || '{}');
    const endpoint = root.dataset.endpoint;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const tooltip = createTooltip();

    const state = {
        sampleId: samples[0]?.id ?? null,
        scenario: structuredClone(samples[0]?.input ?? emptyScenario()),
        notes: [...(samples[0]?.input.operator_notes ?? [''])],
        last: null,
    };

    const ui = {};

    for (const id of [
        'sample-list', 'sample-count', 'note-list', 'add-note', 'battery-fields', 'run', 'run-label',
        'empty-state', 'output', 'hero-value', 'hero-delta', 'directives', 'interpretation-source',
        'legend', 'energy-chart', 'tariff-chart', 'soc-chart', 'soc-note', 'replay', 'replay-badge',
        'metrics', 'mix-bar', 'mix-rows', 'mix-total',
        'raw-json', 'copy-json', 'plan-table', 'view-chart', 'view-table',
    ]) {
        ui[id] = document.getElementById(id);
    }

    checkHealth();
    paintProvider(provider);
    paintSamples();
    paintNotes();
    paintBattery();
    paintLegend();
    setupViewTabs();
    run();

    ui['add-note'].addEventListener('click', () => {
        if (state.notes.length >= 3) return;
        state.notes.push('');
        paintNotes();
        ui['note-list'].lastElementChild?.querySelector('textarea')?.focus();
    });

    ui.run.addEventListener('click', run);

    ui['copy-json'].addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(ui['raw-json'].textContent ?? '');
            ui['copy-json'].textContent = 'Copied';
        } catch {
            ui['copy-json'].textContent = 'Copy failed';
        }

        setTimeout(() => (ui['copy-json'].textContent = 'Copy JSON'), 1500);
    });

    function setupViewTabs() {
        for (const tab of document.querySelectorAll('[data-view]')) {
            tab.addEventListener('click', () => {
                for (const other of document.querySelectorAll('[data-view]')) {
                    other.setAttribute('aria-selected', String(other === tab));
                }

                const chart = tab.dataset.view === 'chart';
                ui['view-chart'].classList.toggle('hidden', !chart);
                ui['view-table'].classList.toggle('hidden', chart);
            });
        }
    }

    function paintSamples() {
        ui['sample-count'].textContent = `${samples.length} public cases`;
        ui['sample-list'].replaceChildren();

        samples.forEach((sample, index) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'scenario-row';
            button.setAttribute('aria-pressed', String(sample.id === state.sampleId));

            const title = document.createElement('span');
            title.className = 'block text-[12.5px] font-medium';
            title.textContent = sample.label;

            const meta = document.createElement('span');
            meta.className = 'mt-0.5 block text-[11px]';
            meta.style.color = 'var(--text-muted)';
            meta.textContent = `${sample.id} · ${sample.input.operator_notes.length} note(s)`;

            button.append(title, meta);

            if (!reducedMotion()) {
                button.classList.add('rise');
                button.style.animationDelay = `${index * 24}ms`;
            }

            button.addEventListener('click', () => {
                state.sampleId = sample.id;
                state.scenario = structuredClone(sample.input);
                state.notes = [...sample.input.operator_notes];
                paintSamples();
                paintNotes();
                paintBattery();
            });

            ui['sample-list'].appendChild(button);
        });
    }

    function paintNotes() {
        ui['note-list'].replaceChildren();

        state.notes.forEach((note, index) => {
            const wrapper = document.createElement('div');
            wrapper.className = 'relative';

            const area = document.createElement('textarea');
            area.className = 'field resize-none pr-7 text-[12.5px] leading-relaxed';
            area.rows = 4;
            area.value = note;
            area.setAttribute('aria-label', `Operator note ${index + 1}`);
            area.placeholder = `Operator note ${index + 1}`;
            area.addEventListener('input', () => (state.notes[index] = area.value));

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'absolute right-1.5 top-1.5 grid h-5 w-5 place-items-center rounded-md text-[15px] leading-none';
            remove.style.color = 'var(--text-muted)';
            remove.setAttribute('aria-label', `Remove note ${index + 1}`);
            remove.textContent = '×';
            remove.disabled = state.notes.length <= 1;
            remove.addEventListener('click', () => {
                state.notes.splice(index, 1);
                paintNotes();
            });

            wrapper.append(area, remove);
            ui['note-list'].appendChild(wrapper);
        });

        ui['add-note'].disabled = state.notes.length >= 3;
    }

    function batteryBounds(slider) {
        const battery = state.scenario.battery;

        if (slider.key === 'initial_energy_kwh') {
            return { min: battery.minimum_energy_kwh, max: battery.capacity_kwh };
        }

        if (slider.key === 'minimum_energy_kwh') {
            return { min: 0, max: battery.capacity_kwh };
        }

        return { min: slider.min, max: slider.max };
    }

    function reconcileBattery() {
        const battery = state.scenario.battery;

        battery.minimum_energy_kwh = Math.min(battery.minimum_energy_kwh, battery.capacity_kwh);
        battery.initial_energy_kwh = Math.min(
            battery.capacity_kwh,
            Math.max(battery.initial_energy_kwh, battery.minimum_energy_kwh),
        );
    }

    function paintBattery() {
        reconcileBattery();
        ui['battery-fields'].replaceChildren();

        for (const slider of BATTERY_SLIDERS) {
            const bounds = batteryBounds(slider);
            const value = state.scenario.battery[slider.key];

            const row = document.createElement('div');
            row.className = 'slider-row';

            const head = document.createElement('div');
            head.className = 'slider-head';

            const label = document.createElement('label');
            label.className = 'slider-label';
            label.setAttribute('for', `battery-${slider.key}`);
            label.textContent = slider.label;

            const readout = document.createElement('span');
            readout.className = 'slider-value';
            readout.textContent = formatNumber(value);

            const unit = document.createElement('span');
            unit.textContent = slider.unit;
            readout.appendChild(unit);

            head.append(label, readout);

            const input = document.createElement('input');
            input.type = 'range';
            input.className = 'slider';
            input.id = `battery-${slider.key}`;
            input.min = String(bounds.min);
            input.max = String(bounds.max);
            input.step = String(slider.step);
            input.value = String(value);
            input.style.setProperty('--fill', fillPercent(value, bounds));

            input.addEventListener('input', () => {
                const next = Number(input.value);
                state.scenario.battery[slider.key] = next;

                readout.replaceChildren(document.createTextNode(formatNumber(next)), unit);
                input.style.setProperty('--fill', fillPercent(next, bounds));

                if (slider.key === 'capacity_kwh' || slider.key === 'minimum_energy_kwh') {
                    clearTimeout(state.batteryRepaint);
                    state.batteryRepaint = setTimeout(paintBattery, 220);
                }
            });

            row.append(head, input);
            ui['battery-fields'].appendChild(row);
        }
    }

    function paintLegend() {
        ui.legend.replaceChildren();

        const entries = [
            [SERIES_META.solar.color, SERIES_META.solar.label, 'swatch'],
            [SERIES_META.battery.color, SERIES_META.battery.label, 'swatch'],
            [SERIES_META.grid.color, SERIES_META.grid.label, 'swatch'],
            ['var(--text-muted)', 'Demand', 'swatch-line'],
        ];

        for (const [color, text, shape] of entries) {
            const item = document.createElement('span');
            item.className = 'flex items-center gap-1.5';

            const key = document.createElement('span');
            key.className = shape;
            key.style.background = color;

            const caption = document.createElement('span');
            caption.textContent = text;

            item.append(key, caption);
            ui.legend.appendChild(item);
        }
    }

    function paintProvider(provider) {
        if (!provider.configured) {
            document.getElementById('provider-chip').style.borderColor = 'var(--status-warning)';
            document.getElementById('provider-text').textContent = `${provider.driver} · not configured`;
        }
    }

    async function checkHealth() {
        const dot = document.getElementById('health-dot');
        const text = document.getElementById('health-text');

        try {
            const response = await fetch('/health', { headers: { Accept: 'application/json' } });
            const body = await response.json();

            if (body.status !== 'ok') throw new Error('unhealthy');

            dot.style.background = 'var(--status-good)';
            dot.dataset.live = 'true';
            text.textContent = '/health ok';
        } catch {
            dot.style.background = 'var(--status-critical)';
            text.textContent = '/health unreachable';
        }
    }

    async function run() {
        const payload = {
            ...state.scenario,
            operator_notes: state.notes.map((note) => note.trim()).filter(Boolean),
        };

        if (payload.operator_notes.length === 0) {
            payload.operator_notes = ['No operational changes today.'];
        }

        setBusy(true);

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body: JSON.stringify(payload),
            });

            const body = await response.json();

            if (!response.ok) {
                throw new Error(body.message ?? 'The request was rejected.');
            }

            state.last = { response: body.response, diagnostics: body.diagnostics, payload };
            paintResults(body.response, body.diagnostics, payload);
        } catch (error) {
            showError(error.message);
        } finally {
            setBusy(false);
        }
    }

    function setBusy(busy) {
        ui.run.disabled = busy;
        ui.run.dataset.busy = String(busy);
        ui['run-label'].textContent = busy ? 'Running pipeline…' : 'Interpret & optimise';
        ui.output.classList.toggle('stale', busy);
    }

    function showError(message) {
        ui['empty-state'].classList.remove('hidden');
        ui['empty-state'].classList.add('grid');
        ui.output.classList.add('hidden');
        ui.output.classList.remove('flex');
        ui['empty-state'].replaceChildren();

        const box = document.createElement('div');
        box.className = 'max-w-md space-y-2';

        const title = document.createElement('p');
        title.className = 'text-sm font-semibold';
        title.style.color = 'var(--status-critical)';
        title.textContent = 'The request was rejected';

        const detail = document.createElement('p');
        detail.className = 'text-[12.5px] leading-relaxed';
        detail.style.color = 'var(--text-secondary)';
        detail.textContent = message;

        box.append(title, detail);
        ui['empty-state'].appendChild(box);
    }

    function paintResults(response, diagnostics, payload) {
        ui['empty-state'].classList.add('hidden');
        ui['empty-state'].classList.remove('grid');
        ui.output.classList.remove('hidden');
        ui.output.classList.add('flex');

        const sample = samples.find((item) => item.id === state.sampleId);
        const reference = sample && sample.input.operator_notes.join(' ') === payload.operator_notes.join(' ')
            ? sample
            : null;

        paintHero(response, reference);
        paintMetrics(response, diagnostics, payload);
        paintMix(response);
        paintDirectives(response.directive_interpretation, reference);
        paintSource(diagnostics.interpretation);
        drawCharts(response, diagnostics, payload);
        paintTable(response, payload);
        paintReplay(diagnostics.replay);

        ui['soc-note'].textContent = `ends at ${formatNumber(response.hourly_plan.at(-1).battery_energy_after_kwh)} kWh · started at ${formatNumber(payload.battery.initial_energy_kwh)} kWh`;
        ui['raw-json'].textContent = JSON.stringify(response, null, 2);
    }

    function paintHero(response, reference) {
        countUp(ui['hero-value'], response.total_cost_bdt);

        ui['hero-delta'].replaceChildren();

        if (reference?.expected_cost == null) return;

        const ratio = Math.min(1, reference.expected_cost / Math.max(response.total_cost_bdt, 0.01));
        const optimal = Math.abs(ratio - 1) < 1e-6;

        const badge = document.createElement('span');
        badge.className = 'badge';
        badge.style.color = optimal ? 'var(--status-good)' : 'var(--status-serious)';
        badge.textContent = optimal
            ? '✓ matches organizer optimal'
            : `${(ratio * 100).toFixed(1)}% of organizer optimal`;

        ui['hero-delta'].appendChild(badge);
    }

    function paintMetrics(response, diagnostics, payload) {
        const baseline = payload.hours.reduce((sum, h) => sum + h.demand_kwh * h.tariff_bdt_per_kwh, 0);
        const saved = baseline > 0 ? 1 - response.total_cost_bdt / baseline : 0;

        const availableSolar = diagnostics.constraints.effective_solar_kwh.reduce((sum, v) => sum + v, 0);
        const usedSolar = response.hourly_plan.reduce((sum, p) => sum + p.solar_used_kwh, 0);
        const captured = availableSolar > 0 ? usedSolar / availableSolar : 1;

        const budget = 5000;
        const latency = diagnostics.total_latency_ms;

        const metrics = [
            {
                label: 'Cost avoided',
                value: (saved * 100).toFixed(1),
                unit: '%',
                ratio: saved,
                color: 'var(--status-good)',
                note: `vs ${formatCompact(baseline)} BDT at full grid supply`,
            },
            {
                label: 'Solar captured',
                value: (captured * 100).toFixed(1),
                unit: '%',
                ratio: captured,
                color: 'var(--series-solar)',
                note: `${formatCompact(usedSolar)} of ${formatCompact(availableSolar)} kWh available`,
            },
            {
                label: 'Pipeline',
                value: latency >= 1000 ? (latency / 1000).toFixed(1) : Math.round(latency).toString(),
                unit: latency >= 1000 ? 's' : 'ms',
                ratio: Math.max(0, 1 - latency / budget),
                color: latencyColor(latency / budget),
                note: latency <= budget ? 'headroom in the 5 s budget' : 'over the 5 s budget',
            },
        ];

        ui.metrics.replaceChildren();

        metrics.forEach((metric, index) => {
            const cell = document.createElement('div');
            cell.className = 'metric flex flex-col justify-between';

            if (!reducedMotion()) {
                cell.classList.add('rise');
                cell.style.animationDelay = `${90 + index * 70}ms`;
            }

            const caption = document.createElement('p');
            caption.className = 'eyebrow';
            caption.textContent = metric.label;

            const dial = document.createElement('div');
            dial.className = 'gauge mt-3';
            dial.setAttribute('role', 'img');
            dial.setAttribute('aria-label', `${metric.label}: ${metric.value} ${metric.unit}`);

            const note = document.createElement('p');
            note.className = 'mt-3 text-center text-[11.5px] leading-snug';
            note.style.color = 'var(--text-muted)';
            note.textContent = metric.note;

            cell.append(caption, dial, note);
            ui.metrics.appendChild(cell);

            renderGauge(dial, {
                ratio: metric.ratio,
                color: metric.color,
                valueText: metric.unit === '%' ? `${metric.value}%` : `${metric.value} ${metric.unit}`,
            });
        });
    }

    function paintMix(response) {
        const solar = response.hourly_plan.reduce((sum, p) => sum + p.solar_used_kwh, 0);
        const discharge = response.hourly_plan.reduce(
            (sum, p) => sum + (p.battery_action === 'discharge' ? p.battery_kwh : 0),
            0,
        );
        const grid = response.total_grid_kwh;
        const total = solar + discharge + grid;

        ui['mix-total'].textContent = `${formatNumber(total)} kWh supplied`;

        const parts = [
            ['Solar used', solar, SERIES_META.solar.color],
            ['Battery discharge', discharge, SERIES_META.battery.color],
            ['Grid import', grid, SERIES_META.grid.color],
        ];

        ui['mix-bar'].replaceChildren();
        ui['mix-rows'].replaceChildren();

        for (const [name, value, color] of parts) {
            const segment = document.createElement('span');
            segment.style.background = color;
            segment.style.flexGrow = String(Math.max(value, 0));
            segment.style.flexBasis = '0';
            ui['mix-bar'].appendChild(segment);

            const row = document.createElement('div');
            row.className = 'mix-row';

            const key = document.createElement('span');
            key.className = 'swatch-line';
            key.style.background = color;

            const text = document.createElement('span');
            text.className = 'flex-1';
            text.style.color = 'var(--text-secondary)';
            text.textContent = name;

            const share = document.createElement('span');
            share.className = 'tabular-nums';
            share.style.color = 'var(--text-muted)';
            share.textContent = total > 0 ? `${((value / total) * 100).toFixed(1)}%` : '—';

            const amount = document.createElement('span');
            amount.className = 'w-24 text-right font-semibold tabular-nums';
            amount.textContent = `${formatNumber(value)} kWh`;

            row.append(key, text, share, amount);
            ui['mix-rows'].appendChild(row);
        }
    }

    function paintDirectives(entries, reference) {
        ui.directives.replaceChildren();

        entries.forEach((entry, index) => {
            const [color, typeLabel] = DIRECTIVE_META[entry.directive_type] ?? ['var(--text-muted)', entry.directive_type];
            const expected = reference?.expected?.[index] ?? null;
            const matches = expected ? sameDirective(expected, entry) : null;

            const card = document.createElement('div');
            card.className = 'entry';

            if (!reducedMotion()) {
                card.classList.add('rise');
                card.style.animationDelay = `${index * 70}ms`;
            }

            const top = document.createElement('div');
            top.className = 'flex flex-wrap items-start justify-between gap-2';

            const noteText = document.createElement('p');
            noteText.className = 'max-w-[72ch] text-[12.5px] leading-relaxed';
            noteText.style.color = 'var(--text-secondary)';

            const idx = document.createElement('span');
            idx.className = 'mr-1.5 font-mono text-[11px]';
            idx.style.color = 'var(--text-muted)';
            idx.textContent = `[${entry.note_index}]`;

            noteText.append(idx, document.createTextNode(state.notes[entry.note_index] ?? ''));

            const badges = document.createElement('div');
            badges.className = 'flex flex-none items-center gap-1.5';

            if (matches !== null) {
                const verdict = document.createElement('span');
                verdict.className = 'badge';
                verdict.style.color = matches ? 'var(--status-good)' : 'var(--status-critical)';
                verdict.textContent = matches ? '✓ match' : '✗ differs';
                badges.appendChild(verdict);
            }

            const typeBadge = document.createElement('span');
            typeBadge.className = 'badge';
            typeBadge.style.color = color;
            typeBadge.style.background = `color-mix(in oklab, ${color} 12%, transparent)`;
            typeBadge.textContent = typeLabel;
            badges.appendChild(typeBadge);

            top.append(noteText, badges);

            const facts = document.createElement('div');
            facts.className = 'mt-2.5 flex flex-wrap items-center gap-1.5';

            facts.appendChild(fact('applies', String(entry.applies)));

            const hours = entry.structured_adjustment?.hours ?? [];

            if (hours.length) {
                facts.appendChild(caption('hours'));

                for (const hour of hours) {
                    const chip = document.createElement('span');
                    chip.className = 'hour-chip';
                    chip.textContent = String(hour);
                    facts.appendChild(chip);
                }
            }

            const numeric = numericOf(entry.structured_adjustment);

            if (numeric) {
                facts.appendChild(caption(numeric.key));
                const chip = document.createElement('span');
                chip.className = 'hour-chip';
                chip.textContent = String(numeric.value);
                facts.appendChild(chip);
            }

            const explanation = document.createElement('p');
            explanation.className = 'mt-2 text-[12px] leading-relaxed';
            explanation.style.color = 'var(--text-muted)';
            explanation.textContent = entry.explanation;

            card.append(top, facts, explanation);
            ui.directives.appendChild(card);
        });
    }

    function caption(text) {
        const node = document.createElement('span');
        node.className = 'text-[10.5px] uppercase tracking-wider';
        node.style.color = 'var(--text-muted)';
        node.textContent = text;

        return node;
    }

    function fact(key, value) {
        const wrap = document.createElement('span');
        wrap.className = 'flex items-center gap-1.5';
        const chip = document.createElement('span');
        chip.className = 'hour-chip';
        chip.textContent = value;
        wrap.append(caption(key), chip);

        return wrap;
    }

    function paintSource(interpretation) {
        const labels = {
            model: 'model interpretation',
            model_repaired: 'model + repair pass',
            deterministic_fallback: 'deterministic fallback',
        };

        const healthy = interpretation.source !== 'deterministic_fallback';
        const chip = ui['interpretation-source'];

        chip.replaceChildren();
        chip.style.borderColor = healthy ? 'var(--border)' : 'var(--status-warning)';

        const dot = document.createElement('span');
        dot.className = 'swatch';
        dot.style.background = healthy ? 'var(--status-good)' : 'var(--status-warning)';

        const text = document.createElement('span');
        text.textContent = `${labels[interpretation.source] ?? interpretation.source} · ${interpretation.driver}${interpretation.cached ? ' · cached' : ` · ${formatNumber(interpretation.latency_ms)} ms`}`;

        chip.append(dot, text);
    }

    function drawCharts(response, diagnostics, payload) {
        renderEnergyChart(ui['energy-chart'], {
            plan: response.hourly_plan,
            hours: payload.hours,
            tooltip,
        });

        renderTariffChart(ui['tariff-chart'], { hours: payload.hours, tooltip });

        renderSocChart(ui['soc-chart'], {
            plan: response.hourly_plan,
            constraints: diagnostics.constraints,
            battery: payload.battery,
            tooltip,
        });
    }

    function paintTable(response, payload) {
        ui['plan-table'].replaceChildren();

        response.hourly_plan.forEach((entry) => {
            const hour = payload.hours[entry.hour];
            const row = document.createElement('tr');

            const battery = entry.battery_action === 'idle'
                ? '—'
                : `${entry.battery_action === 'charge' ? '+' : '−'}${formatNumber(entry.battery_kwh)}`;

            const cells = [
                String(entry.hour).padStart(2, '0'),
                formatNumber(hour.demand_kwh),
                formatNumber(entry.solar_used_kwh),
                battery,
                formatNumber(entry.grid_kwh),
                formatNumber(entry.battery_energy_after_kwh),
                formatNumber(hour.tariff_bdt_per_kwh),
                formatNumber(entry.grid_kwh * hour.tariff_bdt_per_kwh),
            ];

            cells.forEach((value, index) => {
                const cell = document.createElement(index === 0 ? 'th' : 'td');

                if (index === 0) cell.setAttribute('scope', 'row');

                cell.textContent = value;
                row.appendChild(cell);
            });

            ui['plan-table'].appendChild(row);
        });
    }

    function paintReplay(replay) {
        const badge = ui['replay-badge'];
        badge.style.color = replay.valid ? 'var(--status-good)' : 'var(--status-critical)';
        badge.textContent = replay.valid
            ? '✓ all checks passed'
            : `✗ ${replay.violations.length} violation(s)`;

        ui.replay.replaceChildren();

        const list = document.createElement('ul');
        const tone = replay.valid ? 'var(--status-good)' : 'var(--status-critical)';

        REPLAY_CHECKS.forEach((check, index) => {
            const item = document.createElement('li');
            item.className = 'check-row';

            if (!reducedMotion()) {
                item.classList.add('rise');
                item.style.animationDelay = `${index * 45}ms`;
            }

            const mark = document.createElementNS(SVG_NS, 'svg');
            mark.setAttribute('viewBox', '0 0 24 24');
            mark.setAttribute('fill', 'none');
            mark.setAttribute('stroke', tone);
            mark.setAttribute('stroke-width', '3');
            mark.setAttribute('stroke-linecap', 'round');
            mark.setAttribute('stroke-linejoin', 'round');
            mark.setAttribute('class', 'check-mark');

            const path = document.createElementNS(SVG_NS, 'path');
            path.setAttribute('d', replay.valid ? 'm4 13 5 5L20 6' : 'M6 6l12 12M18 6 6 18');
            mark.appendChild(path);

            const text = document.createElement('span');
            text.textContent = check;

            item.append(mark, text);
            list.appendChild(item);
        });

        ui.replay.appendChild(list);

        if (replay.valid) return;

        const detail = document.createElement('pre');
        detail.className = 'mt-3 overflow-auto rounded-lg border p-2.5 text-[11px]';
        detail.style.borderColor = 'var(--border)';
        detail.style.color = 'var(--status-critical)';
        detail.textContent = replay.violations.join('\n');
        ui.replay.appendChild(detail);
    }

    function countUp(node, target, suffix = null) {
        const value = Number(target) || 0;

        const write = (n) => {
            node.replaceChildren(document.createTextNode(formatNumber(n)));
            if (suffix) node.appendChild(suffix);
        };

        if (reducedMotion()) {
            write(value);

            return;
        }

        const duration = 760;
        const start = performance.now();

        const tick = (now) => {
            const t = Math.min(1, (now - start) / duration);
            write(value * (1 - (1 - t) ** 3));

            if (t < 1) requestAnimationFrame(tick);
        };

        requestAnimationFrame(tick);
    }
}

function sameDirective(expected, actual) {
    if (expected.directive_type !== actual.directive_type || expected.applies !== actual.applies) {
        return false;
    }

    const a = expected.structured_adjustment;
    const b = actual.structured_adjustment;

    if (a === null || b === null) return a === b;

    if (JSON.stringify(a.hours ?? []) !== JSON.stringify(b.hours ?? [])) return false;

    for (const key of ['factor', 'minimum_energy_kwh', 'max_grid_kwh']) {
        if ((key in a) !== (key in b)) return false;
        if (key in a && Math.abs(Number(a[key]) - Number(b[key])) > 0.01) return false;
    }

    return true;
}

function numericOf(adjustment) {
    if (!adjustment) return null;

    for (const key of ['factor', 'minimum_energy_kwh', 'max_grid_kwh']) {
        if (key in adjustment) {
            return { key: key.replace(/_/g, ' '), value: adjustment[key] };
        }
    }

    return null;
}

function fillPercent(value, bounds) {
    const span = bounds.max - bounds.min;

    return `${span > 0 ? ((value - bounds.min) / span) * 100 : 0}%`;
}

function latencyColor(share) {
    if (share > 1) return 'var(--status-critical)';
    if (share > 0.6) return 'var(--status-warning)';

    return 'var(--accent)';
}

function formatCompact(value) {
    const n = Number(value);

    if (Math.abs(n) >= 1000) {
        return `${(n / 1000).toLocaleString('en-US', { maximumFractionDigits: 1 })}k`;
    }

    return formatNumber(n);
}

function formatNumber(value) {
    return Number(value).toLocaleString('en-US', { maximumFractionDigits: 2 });
}

function emptyScenario() {
    return {
        scenario_id: 'CUSTOM-01',
        operator_notes: [''],
        hours: Array.from({ length: 24 }, (_, hour) => ({
            hour,
            demand_kwh: 120,
            solar_kwh: 0,
            tariff_bdt_per_kwh: 10,
        })),
        battery: {
            capacity_kwh: 200,
            initial_energy_kwh: 100,
            minimum_energy_kwh: 40,
            max_charge_kwh_per_hour: 50,
            max_discharge_kwh_per_hour: 50,
        },
    };
}

const root = document.getElementById('console');

if (root) {
    boot(root);
}
