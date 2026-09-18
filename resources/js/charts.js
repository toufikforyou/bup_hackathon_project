const NS = 'http://www.w3.org/2000/svg';

const SERIES = {
    solar: { color: 'var(--series-solar)', label: 'Solar used' },
    battery: { color: 'var(--series-battery)', label: 'Battery discharge' },
    grid: { color: 'var(--series-grid)', label: 'Grid import' },
};

const GAP = 2;
const MAX_BAR = 22;
const reduced = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

function el(name, attrs = {}) {
    const node = document.createElementNS(NS, name);

    for (const [key, value] of Object.entries(attrs)) {
        if (value !== null && value !== undefined) {
            node.setAttribute(key, String(value));
        }
    }

    return node;
}

function label(content, attrs = {}) {
    const node = el('text', {
        'font-size': 10,
        fill: 'var(--text-muted)',
        'font-variant-numeric': 'tabular-nums',
        ...attrs,
    });
    node.textContent = content;

    return node;
}

function niceCeiling(value) {
    if (!(value > 0)) return 1;

    const magnitude = 10 ** Math.floor(Math.log10(value));
    const n = value / magnitude;
    const step = n <= 1 ? 1 : n <= 2 ? 2 : n <= 2.5 ? 2.5 : n <= 5 ? 5 : 10;

    return step * magnitude;
}

function svg(width, height) {
    const node = el('svg', {
        viewBox: `0 0 ${width} ${height}`,
        width: '100%',
        height,
        role: 'img',
        preserveAspectRatio: 'xMidYMid meet',
    });
    node.style.overflow = 'visible';

    return node;
}

function roundedTopPath(x, y, w, h, r) {
    const radius = Math.min(r, w / 2, Math.max(h, 0));

    if (h <= 0.2) return '';

    return `M${x},${y + h} L${x},${y + radius} Q${x},${y} ${x + radius},${y} L${x + w - radius},${y} Q${x + w},${y} ${x + w},${y + radius} L${x + w},${y + h} Z`;
}

function gridAndAxis(root, { pad, innerW, innerH, max, ticks = 4, unit }) {
    for (let i = 0; i <= ticks; i++) {
        const value = (max / ticks) * i;
        const y = pad.top + innerH - (value / max) * innerH;

        root.appendChild(el('line', {
            x1: pad.left,
            x2: pad.left + innerW,
            y1: y,
            y2: y,
            stroke: i === 0 ? 'var(--baseline)' : 'var(--gridline)',
            'stroke-width': 1,
            'shape-rendering': 'crispEdges',
        }));

        root.appendChild(label(formatTick(value), {
            x: pad.left - 8,
            y: y + 3.5,
            'text-anchor': 'end',
        }));
    }

    if (unit) {
        root.appendChild(label(unit, {
            x: pad.left - 8,
            y: pad.top - 10,
            'text-anchor': 'end',
            'font-size': 9,
        }));
    }
}

function formatTick(value) {
    if (value >= 1000) return `${(value / 1000).toLocaleString('en-US', { maximumFractionDigits: 1 })}k`;

    return value.toLocaleString('en-US', { maximumFractionDigits: 2 });
}

function hourAxis(root, { pad, innerW, innerH, every = 3 }) {
    for (let hour = 0; hour < 24; hour += every) {
        const x = pad.left + ((hour + 0.5) * innerW) / 24;

        root.appendChild(label(String(hour).padStart(2, '0'), {
            x,
            y: pad.top + innerH + 16,
            'text-anchor': 'middle',
        }));
    }
}


export function renderEnergyChart(container, { plan, hours, tooltip }) {
    container.replaceChildren();

    const width = 900;
    const height = 260;
    const pad = { top: 22, right: 14, bottom: 26, left: 46 };
    const innerW = width - pad.left - pad.right;
    const innerH = height - pad.top - pad.bottom;

    const stacks = plan.map((p) => ({
        hour: p.hour,
        solar: p.solar_used_kwh,
        battery: p.battery_action === 'discharge' ? p.battery_kwh : 0,
        grid: p.grid_kwh,
        charge: p.battery_action === 'charge' ? p.battery_kwh : 0,
        demand: hours[p.hour].demand_kwh,
    }));

    const max = niceCeiling(Math.max(
        ...stacks.map((s) => s.solar + s.battery + s.grid),
        ...stacks.map((s) => s.demand),
    ));

    const root = svg(width, height);
    const band = innerW / 24;
    const barW = Math.min(MAX_BAR, band * 0.62);
    const x = (hour) => pad.left + (hour + 0.5) * band;
    const y = (value) => pad.top + innerH - (value / max) * innerH;

    gridAndAxis(root, { pad, innerW, innerH, max, unit: 'kWh' });

    const demandPath = stacks
        .map((s, i) => `${i === 0 ? 'M' : 'L'}${x(s.hour) - band / 2},${y(s.demand)} L${x(s.hour) + band / 2},${y(s.demand)}`)
        .join(' ');

    const demandLine = el('path', {
        d: demandPath,
        fill: 'none',
        stroke: 'var(--text-muted)',
        'stroke-width': 1.5,
        'stroke-linejoin': 'round',
        opacity: 0.85,
    });

    stacks.forEach((stack, index) => {
        const group = el('g', { tabindex: 0, role: 'listitem' });
        group.style.outline = 'none';

        let cursor = 0;

        for (const key of ['solar', 'battery', 'grid']) {
            const value = stack[key];
            if (value <= 0.0001) continue;

            const top = y(cursor + value);
            const bottom = y(cursor);
            const isTop = key === 'grid' || cursor + value >= stack.solar + stack.battery + stack.grid - 0.0001;
            const rawH = bottom - top;
            const h = Math.max(1, rawH - (cursor > 0 ? GAP : 0));

            const mark = el('path', {
                d: isTop
                    ? roundedTopPath(x(stack.hour) - barW / 2, top, barW, h, 4)
                    : roundedTopPath(x(stack.hour) - barW / 2, top, barW, h, 0),
                fill: SERIES[key].color,
            });

            if (!reduced()) {
                mark.style.transformOrigin = `${x(stack.hour)}px ${pad.top + innerH}px`;
                mark.animate(
                    [{ transform: 'scaleY(0)', opacity: 0.4 }, { transform: 'scaleY(1)', opacity: 1 }],
                    { duration: 560, delay: 120 + index * 16, easing: 'cubic-bezier(0.2, 0.8, 0.3, 1)', fill: 'both' },
                );
            }

            group.appendChild(mark);
            cursor += value;
        }

        const hit = el('rect', {
            x: x(stack.hour) - band / 2,
            y: pad.top,
            width: band,
            height: innerH,
            fill: 'transparent',
        });

        const highlight = el('rect', {
            x: x(stack.hour) - band / 2,
            y: pad.top,
            width: band,
            height: innerH,
            fill: 'var(--text-primary)',
            opacity: 0,
            rx: 4,
        });

        const rows = [
            ['Grid import', stack.grid, SERIES.grid.color],
            ['Battery discharge', stack.battery, SERIES.battery.color],
            ['Solar used', stack.solar, SERIES.solar.color],
            ['Battery charge', stack.charge, SERIES.battery.color],
            ['Demand', stack.demand, 'var(--text-muted)'],
        ].filter(([, value], i) => i === 4 || value > 0.0001);

        const show = (event) => {
            highlight.setAttribute('opacity', '0.05');
            tooltip.show(event, `Hour ${String(stack.hour).padStart(2, '0')}`, rows, 'kWh');
        };

        const hide = () => {
            highlight.setAttribute('opacity', '0');
            tooltip.hide();
        };

        hit.addEventListener('pointerenter', show);
        hit.addEventListener('pointermove', show);
        hit.addEventListener('pointerleave', hide);
        group.addEventListener('focus', () => show({ clientX: null, target: hit }));
        group.addEventListener('blur', hide);

        group.append(highlight, hit);
        root.appendChild(group);
    });

    root.appendChild(demandLine);

    if (!reduced()) {
        const length = demandLine.getTotalLength?.() ?? 0;

        if (length) {
            demandLine.style.strokeDasharray = String(length);
            demandLine.style.strokeDashoffset = String(length);
            demandLine.animate(
                [{ strokeDashoffset: length }, { strokeDashoffset: 0 }],
                { duration: 900, delay: 240, easing: 'ease-out', fill: 'both' },
            );
        }
    }

    const peak = stacks.reduce((best, s) => (s.grid > best.grid ? s : best), stacks[0]);

    root.appendChild(label(`peak ${formatTick(peak.grid)}`, {
        x: Math.min(x(peak.hour), pad.left + innerW - 34),
        y: y(peak.solar + peak.battery + peak.grid) - 7,
        'text-anchor': 'middle',
        fill: 'var(--text-secondary)',
        'font-size': 9.5,
        'font-weight': 600,
    }));

    hourAxis(root, { pad, innerW, innerH });
    container.appendChild(root);
}

export function renderTariffChart(container, { hours, tooltip }) {
    container.replaceChildren();

    const width = 900;
    const height = 120;
    const pad = { top: 18, right: 14, bottom: 24, left: 46 };
    const innerW = width - pad.left - pad.right;
    const innerH = height - pad.top - pad.bottom;

    const max = niceCeiling(Math.max(...hours.map((h) => h.tariff_bdt_per_kwh)));
    const root = svg(width, height);
    const band = innerW / 24;
    const x = (hour) => pad.left + (hour + 0.5) * band;
    const y = (value) => pad.top + innerH - (value / max) * innerH;

    gridAndAxis(root, { pad, innerW, innerH, max, ticks: 2, unit: 'BDT/kWh' });

    const points = hours.map((h) => [x(h.hour), y(h.tariff_bdt_per_kwh)]);
    const d = points.map(([px, py], i) => `${i === 0 ? 'M' : 'L'}${px},${py}`).join(' ');

    root.appendChild(el('path', {
        d: `${d} L${points.at(-1)[0]},${pad.top + innerH} L${points[0][0]},${pad.top + innerH} Z`,
        fill: 'var(--series-tariff)',
        opacity: 0.1,
    }));

    const line = el('path', {
        d,
        fill: 'none',
        stroke: 'var(--series-tariff)',
        'stroke-width': 2,
        'stroke-linejoin': 'round',
        'stroke-linecap': 'round',
    });

    root.appendChild(line);

    if (!reduced()) {
        const length = line.getTotalLength?.() ?? 0;

        if (length) {
            line.style.strokeDasharray = String(length);
            line.animate(
                [{ strokeDashoffset: length }, { strokeDashoffset: 0 }],
                { duration: 1000, delay: 160, easing: 'ease-out', fill: 'both' },
            );
        }
    }

    const peakHour = hours.reduce((best, h) => (h.tariff_bdt_per_kwh > best.tariff_bdt_per_kwh ? h : best), hours[0]);

    root.appendChild(el('circle', {
        cx: x(peakHour.hour),
        cy: y(peakHour.tariff_bdt_per_kwh),
        r: 4,
        fill: 'var(--series-tariff)',
        stroke: 'var(--surface-1)',
        'stroke-width': 2,
    }));

    root.appendChild(label(`peak ${formatTick(peakHour.tariff_bdt_per_kwh)}`, {
        x: Math.min(x(peakHour.hour), pad.left + innerW - 40),
        y: y(peakHour.tariff_bdt_per_kwh) - 9,
        'text-anchor': 'middle',
        fill: 'var(--text-secondary)',
        'font-size': 9.5,
        'font-weight': 600,
    }));

    const crosshair = el('line', {
        y1: pad.top,
        y2: pad.top + innerH,
        stroke: 'var(--baseline)',
        'stroke-width': 1,
        opacity: 0,
    });

    root.appendChild(crosshair);

    hours.forEach((h) => {
        const hit = el('rect', {
            x: x(h.hour) - band / 2,
            y: pad.top,
            width: band,
            height: innerH,
            fill: 'transparent',
        });

        const show = (event) => {
            crosshair.setAttribute('x1', x(h.hour));
            crosshair.setAttribute('x2', x(h.hour));
            crosshair.setAttribute('opacity', '1');
            tooltip.show(
                event,
                `Hour ${String(h.hour).padStart(2, '0')}`,
                [['Tariff', h.tariff_bdt_per_kwh, 'var(--series-tariff)']],
                'BDT/kWh',
            );
        };

        hit.addEventListener('pointerenter', show);
        hit.addEventListener('pointermove', show);
        hit.addEventListener('pointerleave', () => {
            crosshair.setAttribute('opacity', '0');
            tooltip.hide();
        });

        root.appendChild(hit);
    });

    hourAxis(root, { pad, innerW, innerH });
    container.appendChild(root);
}

export function renderSocChart(container, { plan, constraints, battery, tooltip }) {
    container.replaceChildren();

    const width = 900;
    const height = 236;
    const pad = { top: 22, right: 14, bottom: 26, left: 46 };
    const innerW = width - pad.left - pad.right;
    const innerH = height - pad.top - pad.bottom;

    const max = niceCeiling(battery.capacity_kwh);
    const root = svg(width, height);
    const x = (index) => pad.left + (index * innerW) / 24;
    const y = (value) => pad.top + innerH - (value / max) * innerH;

    gridAndAxis(root, { pad, innerW, innerH, max, ticks: 2, unit: 'kWh' });

    const floors = constraints.minimum_energy_kwh ?? [];
    const floorPath = floors.map((v, i) => `M${x(i)},${y(v)} L${x(i + 1)},${y(v)}`).join(' ');

    root.appendChild(el('path', {
        d: `${floors.map((v, i) => `${i === 0 ? 'M' : 'L'}${x(i)},${y(v)} L${x(i + 1)},${y(v)}`).join(' ')} L${x(24)},${y(0)} L${x(0)},${y(0)} Z`,
        fill: 'var(--status-critical)',
        opacity: 0.07,
    }));

    root.appendChild(el('path', {
        d: floorPath,
        fill: 'none',
        stroke: 'var(--status-critical)',
        'stroke-width': 1.5,
        opacity: 0.55,
    }));

    root.appendChild(el('line', {
        x1: pad.left,
        x2: pad.left + innerW,
        y1: y(battery.capacity_kwh),
        y2: y(battery.capacity_kwh),
        stroke: 'var(--baseline)',
        'stroke-width': 1,
    }));

    root.appendChild(label('capacity', {
        x: pad.left + innerW,
        y: y(battery.capacity_kwh) - 5,
        'text-anchor': 'end',
        'font-size': 9,
    }));

    const points = [[x(0), y(battery.initial_energy_kwh)]];
    plan.forEach((p, i) => points.push([x(i + 1), y(p.battery_energy_after_kwh)]));

    const d = points.map(([px, py], i) => `${i === 0 ? 'M' : 'L'}${px},${py}`).join(' ');

    root.appendChild(el('path', {
        d: `${d} L${x(24)},${y(0)} L${x(0)},${y(0)} Z`,
        fill: 'var(--series-battery)',
        opacity: 0.1,
    }));

    const line = el('path', {
        d,
        fill: 'none',
        stroke: 'var(--series-battery)',
        'stroke-width': 2,
        'stroke-linejoin': 'round',
        'stroke-linecap': 'round',
    });

    root.appendChild(line);

    if (!reduced()) {
        const length = line.getTotalLength?.() ?? 0;

        if (length) {
            line.style.strokeDasharray = String(length);
            line.animate(
                [{ strokeDashoffset: length }, { strokeDashoffset: 0 }],
                { duration: 1100, delay: 200, easing: 'ease-out', fill: 'both' },
            );
        }
    }

    const last = points.at(-1);

    root.appendChild(el('circle', {
        cx: last[0],
        cy: last[1],
        r: 4,
        fill: 'var(--series-battery)',
        stroke: 'var(--surface-1)',
        'stroke-width': 2,
    }));

    root.appendChild(label(`${formatTick(battery.initial_energy_kwh)} back to start`, {
        x: last[0] - 6,
        y: last[1] - 9,
        'text-anchor': 'end',
        fill: 'var(--text-secondary)',
        'font-size': 9.5,
        'font-weight': 600,
    }));

    const crosshair = el('line', {
        y1: pad.top,
        y2: pad.top + innerH,
        stroke: 'var(--baseline)',
        'stroke-width': 1,
        opacity: 0,
    });

    root.appendChild(crosshair);

    plan.forEach((p, i) => {
        const cx = x(i + 1);
        const hit = el('rect', {
            x: cx - innerW / 48,
            y: pad.top,
            width: innerW / 24,
            height: innerH,
            fill: 'transparent',
        });

        const show = (event) => {
            crosshair.setAttribute('x1', cx);
            crosshair.setAttribute('x2', cx);
            crosshair.setAttribute('opacity', '1');
            tooltip.show(
                event,
                `End of hour ${String(p.hour).padStart(2, '0')}`,
                [
                    ['Stored', p.battery_energy_after_kwh, 'var(--series-battery)'],
                    ['Floor', constraints.minimum_energy_kwh[p.hour], 'var(--status-critical)'],
                ],
                'kWh',
            );
        };

        hit.addEventListener('pointerenter', show);
        hit.addEventListener('pointermove', show);
        hit.addEventListener('pointerleave', () => {
            crosshair.setAttribute('opacity', '0');
            tooltip.hide();
        });

        root.appendChild(hit);
    });

    hourAxis(root, { pad, innerW, innerH });
    container.appendChild(root);
}

export function createTooltip() {
    const node = document.createElement('div');
    node.className = 'tooltip';
    node.setAttribute('role', 'status');
    document.body.appendChild(node);

    let raf = null;

    return {
        show(event, title, rows, unit) {
            node.replaceChildren();

            const heading = document.createElement('p');
            heading.className = 'mb-1.5 text-[11px] font-semibold';
            heading.style.color = 'var(--text-primary)';
            heading.textContent = title;
            node.appendChild(heading);

            for (const [name, value, color] of rows) {
                const row = document.createElement('div');
                row.className = 'flex items-center gap-2 leading-5';

                const key = document.createElement('span');
                key.className = 'swatch-line';
                key.style.background = color;

                const amount = document.createElement('span');
                amount.className = 'tooltip-value';
                amount.textContent = Number(value).toLocaleString('en-US', { maximumFractionDigits: 2 });

                const text = document.createElement('span');
                text.className = 'tooltip-label';
                text.textContent = name;

                row.append(key, amount, text);
                node.appendChild(row);
            }

            const unitNode = document.createElement('p');
            unitNode.className = 'mt-1 text-[10px]';
            unitNode.style.color = 'var(--text-muted)';
            unitNode.textContent = unit;
            node.appendChild(unitNode);

            const rect = event?.target?.getBoundingClientRect?.();
            const left = event?.clientX ?? (rect ? rect.left + rect.width / 2 : 0);
            const top = rect ? rect.top : (event?.clientY ?? 0);

            cancelAnimationFrame(raf);
            raf = requestAnimationFrame(() => {
                node.style.left = `${Math.max(90, Math.min(window.innerWidth - 90, left))}px`;
                node.style.top = `${Math.max(90, top)}px`;
                node.dataset.open = 'true';
            });
        },
        hide() {
            cancelAnimationFrame(raf);
            node.dataset.open = 'false';
        },
    };
}

export const SERIES_META = SERIES;
