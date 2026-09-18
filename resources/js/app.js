import { renderEnergyChart, renderSocChart } from './charts.js';

const DIRECTIVE_STYLES = {
    solar_reduction: ['var(--color-solar)', 'Solar reduction'],
    minimum_battery_reserve: ['var(--color-battery)', 'Minimum reserve'],
    no_charge_window: ['var(--color-demand)', 'No charging'],
    no_discharge_window: ['var(--color-tariff)', 'No discharging'],
    max_grid_window: ['var(--color-grid)', 'Grid cap'],
    no_op: ['var(--color-ink-3)', 'No operation'],
};

const BATTERY_FIELDS = [
    ['capacity_kwh', 'Capacity'],
    ['initial_energy_kwh', 'Initial energy'],
    ['minimum_energy_kwh', 'Minimum energy'],
    ['max_charge_kwh_per_hour', 'Max charge / h'],
    ['max_discharge_kwh_per_hour', 'Max discharge / h'],
];

function boot(root) {
    const samples = JSON.parse(root.dataset.samples || '[]');
    const provider = JSON.parse(root.dataset.provider || '{}');
    const endpoint = root.dataset.endpoint;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    const state = {
        sampleId: samples[0]?.id ?? null,
        scenario: structuredClone(samples[0]?.input ?? emptyScenario()),
        notes: [...(samples[0]?.input.operator_notes ?? [''])],
    };

    const ui = {
        sampleList: document.getElementById('sample-list'),
        sampleCount: document.getElementById('sample-count'),
        noteList: document.getElementById('note-list'),
        addNote: document.getElementById('add-note'),
        batteryFields: document.getElementById('battery-fields'),
        run: document.getElementById('run'),
        runLabel: document.getElementById('run-label'),
        empty: document.getElementById('empty-state'),
        output: document.getElementById('output'),
        kpis: document.getElementById('kpis'),
        directives: document.getElementById('directives'),
        source: document.getElementById('interpretation-source'),
        energyChart: document.getElementById('energy-chart'),
        socChart: document.getElementById('soc-chart'),
        socNote: document.getElementById('soc-note'),
        replay: document.getElementById('replay'),
        replayPill: document.getElementById('replay-pill'),
        rawJson: document.getElementById('raw-json'),
        copyJson: document.getElementById('copy-json'),
    };

    checkHealth();
    paintProvider(provider);
    paintSamples();
    paintNotes();
    paintBattery();

    ui.addNote.addEventListener('click', () => {
        if (state.notes.length >= 3) return;
        state.notes.push('');
        paintNotes();
    });

    ui.run.addEventListener('click', run);

    ui.copyJson.addEventListener('click', async () => {
        await navigator.clipboard.writeText(ui.rawJson.textContent ?? '');
        ui.copyJson.textContent = 'Copied';
        setTimeout(() => (ui.copyJson.textContent = 'Copy JSON'), 1400);
    });

    function paintSamples() {
        ui.sampleCount.textContent = `${samples.length} public cases`;
        ui.sampleList.replaceChildren();

        for (const sample of samples) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'sample-chip';
            button.dataset.active = String(sample.id === state.sampleId);
            button.innerHTML = `
                <span class="block text-[13px] font-medium">${escapeHtml(sample.label)}</span>
                <span class="mt-0.5 block text-[11px] text-[var(--color-ink-3)]">${sample.id} · ${sample.input.operator_notes.length} note(s)</span>
            `;

            button.addEventListener('click', () => {
                state.sampleId = sample.id;
                state.scenario = structuredClone(sample.input);
                state.notes = [...sample.input.operator_notes];
                paintSamples();
                paintNotes();
                paintBattery();
            });

            ui.sampleList.appendChild(button);
        }
    }

    function paintNotes() {
        ui.noteList.replaceChildren();

        state.notes.forEach((note, index) => {
            const wrapper = document.createElement('div');
            wrapper.className = 'relative';

            const area = document.createElement('textarea');
            area.className = 'field resize-none pr-8';
            area.rows = 4;
            area.value = note;
            area.placeholder = `Operator note ${index + 1}`;
            area.addEventListener('input', () => (state.notes[index] = area.value));

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'absolute right-2 top-2 text-[var(--color-ink-3)] transition hover:text-[var(--color-danger)]';
            remove.innerHTML = '&times;';
            remove.disabled = state.notes.length <= 1;
            remove.addEventListener('click', () => {
                state.notes.splice(index, 1);
                paintNotes();
            });

            wrapper.append(area, remove);
            ui.noteList.appendChild(wrapper);
        });

        ui.addNote.disabled = state.notes.length >= 3;
    }

    function paintBattery() {
        ui.batteryFields.replaceChildren();

        for (const [key, label] of BATTERY_FIELDS) {
            const wrapper = document.createElement('label');
            wrapper.className = 'block space-y-1';
            wrapper.innerHTML = `<span class="text-[11px] text-[var(--color-ink-3)]">${label}</span>`;

            const input = document.createElement('input');
            input.type = 'number';
            input.className = 'field';
            input.step = 'any';
            input.min = '0';
            input.value = state.scenario.battery[key];
            input.addEventListener('input', () => {
                state.scenario.battery[key] = Number(input.value);
            });

            wrapper.appendChild(input);
            ui.batteryFields.appendChild(wrapper);
        }
    }

    function paintProvider(provider) {
        const pill = document.getElementById('provider-pill');

        if (!provider.configured) {
            pill.style.borderColor = 'var(--color-tariff)';
            document.getElementById('provider-text').textContent = `${provider.driver} · not configured`;
        }
    }

    async function checkHealth() {
        const dot = document.getElementById('health-dot');
        const label = document.getElementById('health-text');

        try {
            const response = await fetch('/health', { headers: { Accept: 'application/json' } });
            const body = await response.json();

            if (body.status === 'ok') {
                dot.classList.add('dot-live');
                label.textContent = '/health ok';

                return;
            }

            throw new Error('unhealthy');
        } catch {
            dot.style.background = 'var(--color-danger)';
            label.textContent = '/health unreachable';
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

            paintResults(body.response, body.diagnostics, payload);
        } catch (error) {
            ui.empty.classList.remove('hidden');
            ui.empty.innerHTML = `<p class="text-sm text-[var(--color-danger)]">${escapeHtml(error.message)}</p>`;
            ui.output.classList.add('hidden');
            ui.output.classList.remove('flex');
        } finally {
            setBusy(false);
        }
    }

    function setBusy(busy) {
        ui.run.disabled = busy;
        ui.runLabel.textContent = busy ? 'Running pipeline…' : 'Interpret & optimise';
    }

    function paintResults(response, diagnostics, payload) {
        ui.empty.classList.add('hidden');
        ui.output.classList.remove('hidden');
        ui.output.classList.add('flex');

        const sample = samples.find((item) => item.id === state.sampleId);
        const reference = sample && sample.input.operator_notes.join(' ') === payload.operator_notes.join(' ')
            ? sample
            : null;

        paintKpis(response, diagnostics, reference);
        paintDirectives(response.directive_interpretation, reference);
        paintSource(diagnostics.interpretation);

        renderEnergyChart(ui.energyChart, {
            plan: response.hourly_plan,
            hours: payload.hours,
            constraints: diagnostics.constraints,
        });

        renderSocChart(ui.socChart, {
            plan: response.hourly_plan,
            constraints: diagnostics.constraints,
            battery: payload.battery,
        });

        ui.socNote.textContent = `ends at ${format(response.hourly_plan.at(-1).battery_energy_after_kwh)} kWh · started at ${format(payload.battery.initial_energy_kwh)} kWh`;

        paintReplay(diagnostics.replay);

        ui.rawJson.textContent = JSON.stringify(response, null, 2);
    }

    function paintKpis(response, diagnostics, reference) {
        const cards = [
            ['Total cost', `${format(response.total_cost_bdt)}`, 'BDT', 'var(--color-grid)'],
            ['Grid import', `${format(response.total_grid_kwh)}`, 'kWh', 'var(--color-demand)'],
            ['Peak hour', `${format(response.peak_grid_kwh)}`, 'kWh', 'var(--color-tariff)'],
            ['Pipeline', `${format(diagnostics.total_latency_ms)}`, 'ms', 'var(--color-solar)'],
        ];

        ui.kpis.replaceChildren();

        cards.forEach(([label, value, unit, color], index) => {
            const card = document.createElement('div');
            card.className = 'panel kpi fade-up';
            card.style.animationDelay = `${index * 40}ms`;

            let footer = '';

            if (label === 'Total cost' && reference?.expected_cost != null) {
                const ratio = Math.min(1, reference.expected_cost / Math.max(response.total_cost_bdt, 0.01));
                const optimal = Math.abs(ratio - 1) < 1e-6;
                footer = `<p class="mt-1.5 text-[11px] ${optimal ? 'text-[var(--color-solar)]' : 'text-[var(--color-tariff)]'}">
                    ${optimal ? 'matches organizer optimal' : `${(ratio * 100).toFixed(1)}% of optimal`}
                </p>`;
            }

            card.innerHTML = `
                <p class="text-[11px] uppercase tracking-wider text-[var(--color-ink-3)]">${label}</p>
                <p class="kpi-value mt-1" style="color:${color}">${value}<span class="ml-1 text-xs font-normal text-[var(--color-ink-3)]">${unit}</span></p>
                ${footer}
            `;

            ui.kpis.appendChild(card);
        });
    }

    function paintDirectives(entries, reference) {
        ui.directives.replaceChildren();

        entries.forEach((entry, index) => {
            const [color, label] = DIRECTIVE_STYLES[entry.directive_type] ?? ['var(--color-ink-3)', entry.directive_type];
            const expected = reference?.expected?.[index] ?? null;
            const matches = expected ? sameDirective(expected, entry) : null;

            const card = document.createElement('div');
            card.className = 'rounded-xl border border-[var(--color-line)] bg-[var(--color-surface-2)] p-3.5 fade-up';
            card.style.animationDelay = `${index * 50}ms`;

            const hours = entry.structured_adjustment?.hours ?? [];
            const numeric = numericOf(entry.structured_adjustment);

            card.innerHTML = `
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <p class="max-w-[70ch] text-[13px] leading-relaxed text-[var(--color-ink-2)]">
                        <span class="mr-1.5 font-mono text-[11px] text-[var(--color-ink-3)]">[${entry.note_index}]</span>
                        ${escapeHtml(state.notes[entry.note_index] ?? '')}
                    </p>
                    <div class="flex items-center gap-1.5">
                        ${matches === null ? '' : `<span class="tag" style="color:${matches ? 'var(--color-solar)' : 'var(--color-danger)'};border-color:currentColor">${matches ? 'match' : 'differs'}</span>`}
                        <span class="tag" style="color:${color};border-color:currentColor;background:color-mix(in oklab, ${color} 12%, transparent)">${label}</span>
                    </div>
                </div>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <span class="text-[11px] uppercase tracking-wider text-[var(--color-ink-3)]">applies</span>
                    <span class="hour-chip" style="color:${entry.applies ? 'var(--color-solar)' : 'var(--color-ink-3)'}">${entry.applies}</span>
                    ${hours.length ? `<span class="ml-2 text-[11px] uppercase tracking-wider text-[var(--color-ink-3)]">hours</span>` : ''}
                    ${hours.map((hour) => `<span class="hour-chip">${hour}</span>`).join('')}
                    ${numeric ? `<span class="ml-2 text-[11px] uppercase tracking-wider text-[var(--color-ink-3)]">${numeric.key}</span><span class="hour-chip">${numeric.value}</span>` : ''}
                </div>
                <p class="mt-2.5 text-[12px] leading-relaxed text-[var(--color-ink-3)]">${escapeHtml(entry.explanation)}</p>
            `;

            ui.directives.appendChild(card);
        });
    }

    function paintSource(interpretation) {
        const labels = {
            model: 'model interpretation',
            model_repaired: 'model + repair pass',
            deterministic_fallback: 'deterministic fallback',
        };

        const healthy = interpretation.source !== 'deterministic_fallback';

        ui.source.style.borderColor = healthy ? 'var(--color-line)' : 'var(--color-tariff)';
        ui.source.textContent = `${labels[interpretation.source] ?? interpretation.source} · ${interpretation.driver} · ${format(interpretation.latency_ms)} ms${interpretation.cached ? ' · cached' : ''}`;
    }

    function paintReplay(replay) {
        ui.replayPill.style.borderColor = replay.valid ? 'var(--color-solar)' : 'var(--color-danger)';
        ui.replayPill.style.color = replay.valid ? 'var(--color-solar)' : 'var(--color-danger)';
        ui.replayPill.textContent = replay.valid ? 'all checks passed' : `${replay.violations.length} violation(s)`;

        const checks = [
            '24 unique hours, 0 through 23',
            'hourly energy balance',
            'solar within effective availability',
            'battery transitions, bounds and rate limits',
            'directive windows, reserve and grid caps',
            'end-of-day battery neutrality',
            'reported totals match the plan',
        ];

        ui.replay.replaceChildren();

        const list = document.createElement('ul');
        list.className = 'space-y-2';

        for (const check of checks) {
            const item = document.createElement('li');
            item.className = 'flex items-center gap-2.5 text-[12.5px] text-[var(--color-ink-2)]';
            item.innerHTML = `
                <span class="grid h-4 w-4 place-items-center rounded-full" style="background:color-mix(in oklab, ${replay.valid ? 'var(--color-solar)' : 'var(--color-danger)'} 18%, transparent)">
                    <svg viewBox="0 0 24 24" class="h-2.5 w-2.5" fill="none" stroke="${replay.valid ? 'var(--color-solar)' : 'var(--color-danger)'}" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round">
                        ${replay.valid ? '<path d="m5 13 4 4L19 7"/>' : '<path d="M6 6l12 12M18 6 6 18"/>'}
                    </svg>
                </span>
                ${check}
            `;
            list.appendChild(item);
        }

        ui.replay.appendChild(list);

        if (!replay.valid) {
            const detail = document.createElement('pre');
            detail.className = 'mt-3 rounded-lg border border-[var(--color-line)] p-2.5 text-[11px] text-[var(--color-danger)]';
            detail.textContent = replay.violations.join('\n');
            ui.replay.appendChild(detail);
        }
    }
}

function sameDirective(expected, actual) {
    if (expected.directive_type !== actual.directive_type || expected.applies !== actual.applies) {
        return false;
    }

    const a = expected.structured_adjustment;
    const b = actual.structured_adjustment;

    if (a === null || b === null) {
        return a === b;
    }

    if (JSON.stringify(a.hours ?? []) !== JSON.stringify(b.hours ?? [])) {
        return false;
    }

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

function format(value) {
    return Number(value).toLocaleString('en-US', { maximumFractionDigits: 2 });
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
    })[char]);
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
