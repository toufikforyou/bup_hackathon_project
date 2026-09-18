const NS = 'http://www.w3.org/2000/svg';

const COLORS = {
    solar: 'var(--color-solar)',
    battery: 'var(--color-battery)',
    grid: 'var(--color-grid)',
    demand: 'var(--color-demand)',
    tariff: 'var(--color-tariff)',
    line: 'var(--color-line)',
    ink3: 'var(--color-ink-3)',
    danger: 'var(--color-danger)',
};

function el(name, attrs = {}, children = []) {
    const node = document.createElementNS(NS, name);

    for (const [key, value] of Object.entries(attrs)) {
        if (value !== null && value !== undefined) {
            node.setAttribute(key, String(value));
        }
    }

    for (const child of children) {
        node.appendChild(child);
    }

    return node;
}

function text(content, attrs = {}) {
    const node = el('text', { 'font-size': 10, fill: COLORS.ink3, ...attrs });
    node.textContent = content;

    return node;
}

function niceCeiling(value) {
    if (value <= 0) return 1;

    const magnitude = 10 ** Math.floor(Math.log10(value));
    const normalised = value / magnitude;
    const step = normalised <= 1 ? 1 : normalised <= 2 ? 2 : normalised <= 5 ? 5 : 10;

    return step * magnitude;
}

function frame(width, height) {
    const svg = el('svg', {
        viewBox: `0 0 ${width} ${height}`,
        class: 'w-full',
        style: `height:${height}px`,
        preserveAspectRatio: 'none',
    });

    return svg;
}

export function renderEnergyChart(container, { plan, hours, constraints }) {
    container.replaceChildren();

    const width = 960;
    const height = 300;
    const pad = { top: 16, right: 46, bottom: 34, left: 46 };
    const innerW = width - pad.left - pad.right;
    const innerH = height - pad.top - pad.bottom;

    const supply = plan.map((p) => p.grid_kwh + p.solar_used_kwh + (p.battery_action === 'discharge' ? p.battery_kwh : 0));
    const charge = plan.map((p) => (p.battery_action === 'charge' ? p.battery_kwh : 0));
    const maxSupply = niceCeiling(Math.max(...supply, ...hours.map((h) => h.demand_kwh), 1));
    const maxTariff = niceCeiling(Math.max(...hours.map((h) => h.tariff_bdt_per_kwh), 1));

    const svg = frame(width, height);
    const x = (i) => pad.left + (i + 0.5) * (innerW / 24);
    const y = (v) => pad.top + innerH - (v / maxSupply) * innerH;
    const band = innerW / 24;
    const barW = band * 0.62;

    for (let i = 0; i <= 4; i++) {
        const value = (maxSupply / 4) * i;
        const yy = y(value);
        svg.appendChild(el('line', { x1: pad.left, x2: width - pad.right, y1: yy, y2: yy, stroke: COLORS.line, 'stroke-width': 1 }));
        svg.appendChild(text(String(Math.round(value)), { x: pad.left - 8, y: yy + 3, 'text-anchor': 'end' }));
    }

    for (const [hour, cap] of Object.entries(constraints.max_grid_kwh ?? {})) {
        if (cap === null) continue;
        const i = Number(hour);
        svg.appendChild(el('rect', {
            x: x(i) - band / 2,
            y: pad.top,
            width: band,
            height: innerH,
            fill: COLORS.danger,
            opacity: 0.07,
        }));
    }

    plan.forEach((p, i) => {
        const discharge = p.battery_action === 'discharge' ? p.battery_kwh : 0;
        const segments = [
            { value: p.solar_used_kwh, color: COLORS.solar },
            { value: discharge, color: COLORS.battery },
            { value: p.grid_kwh, color: COLORS.grid },
        ];

        let cursor = 0;

        for (const segment of segments) {
            if (segment.value <= 0.0001) continue;

            svg.appendChild(el('rect', {
                x: x(i) - barW / 2,
                y: y(cursor + segment.value),
                width: barW,
                height: Math.max(0.6, y(cursor) - y(cursor + segment.value)),
                fill: segment.color,
                opacity: 0.85,
                rx: 1.5,
            }));

            cursor += segment.value;
        }

        if (charge[i] > 0.0001) {
            svg.appendChild(el('rect', {
                x: x(i) - barW / 2,
                y: y(cursor + charge[i]),
                width: barW,
                height: Math.max(0.6, y(cursor) - y(cursor + charge[i])),
                fill: COLORS.battery,
                opacity: 0.28,
                rx: 1.5,
            }));
        }

        svg.appendChild(el('title', {}, [])).textContent =
            `hour ${p.hour} · grid ${p.grid_kwh} · solar ${p.solar_used_kwh} · battery ${p.battery_action} ${p.battery_kwh}`;
    });

    const demandPath = hours.map((h, i) => `${i === 0 ? 'M' : 'L'}${x(i)},${y(h.demand_kwh)}`).join(' ');
    svg.appendChild(el('path', {
        d: demandPath,
        fill: 'none',
        stroke: COLORS.demand,
        'stroke-width': 1.6,
        'stroke-dasharray': '4 3',
        opacity: 0.9,
    }));

    const tariffY = (v) => pad.top + innerH - (v / maxTariff) * innerH;
    const tariffPath = hours.map((h, i) => `${i === 0 ? 'M' : 'L'}${x(i)},${tariffY(h.tariff_bdt_per_kwh)}`).join(' ');
    svg.appendChild(el('path', { d: tariffPath, fill: 'none', stroke: COLORS.tariff, 'stroke-width': 1.4, opacity: 0.75 }));

    for (let i = 0; i <= 4; i++) {
        const value = (maxTariff / 4) * i;
        svg.appendChild(text(String(Math.round(value)), {
            x: width - pad.right + 8,
            y: tariffY(value) + 3,
            'text-anchor': 'start',
            fill: COLORS.tariff,
            opacity: 0.8,
        }));
    }

    for (let i = 0; i < 24; i += 2) {
        svg.appendChild(text(String(i), { x: x(i), y: height - 12, 'text-anchor': 'middle' }));
    }

    container.appendChild(svg);
}

export function renderSocChart(container, { plan, constraints, battery }) {
    container.replaceChildren();

    const width = 960;
    const height = 220;
    const pad = { top: 16, right: 16, bottom: 30, left: 46 };
    const innerW = width - pad.left - pad.right;
    const innerH = height - pad.top - pad.bottom;

    const ceiling = niceCeiling(battery.capacity_kwh);
    const svg = frame(width, height);
    const x = (i) => pad.left + (i * innerW) / 24;
    const y = (v) => pad.top + innerH - (v / ceiling) * innerH;

    for (let i = 0; i <= 4; i++) {
        const value = (ceiling / 4) * i;
        const yy = y(value);
        svg.appendChild(el('line', { x1: pad.left, x2: width - pad.right, y1: yy, y2: yy, stroke: COLORS.line }));
        svg.appendChild(text(String(Math.round(value)), { x: pad.left - 8, y: yy + 3, 'text-anchor': 'end' }));
    }

    const floors = constraints.minimum_energy_kwh ?? [];
    const floorPath = floors
        .map((v, i) => `${i === 0 ? 'M' : 'L'}${x(i)},${y(v)} L${x(i + 1)},${y(v)}`)
        .join(' ');

    svg.appendChild(el('path', {
        d: `${floorPath} L${x(24)},${y(0)} L${x(0)},${y(0)} Z`,
        fill: COLORS.danger,
        opacity: 0.08,
    }));

    svg.appendChild(el('path', { d: floorPath, fill: 'none', stroke: COLORS.danger, 'stroke-width': 1.3, opacity: 0.65 }));

    const points = [[x(0), y(battery.initial_energy_kwh)]];
    plan.forEach((p, i) => points.push([x(i + 1), y(p.battery_energy_after_kwh)]));

    const linePath = points.map(([px, py], i) => `${i === 0 ? 'M' : 'L'}${px},${py}`).join(' ');

    svg.appendChild(el('path', {
        d: `${linePath} L${x(24)},${y(0)} L${x(0)},${y(0)} Z`,
        fill: COLORS.battery,
        opacity: 0.14,
    }));

    svg.appendChild(el('path', { d: linePath, fill: 'none', stroke: COLORS.battery, 'stroke-width': 2, 'stroke-linejoin': 'round' }));

    points.forEach(([px, py]) => {
        svg.appendChild(el('circle', { cx: px, cy: py, r: 2, fill: COLORS.battery }));
    });

    for (let i = 0; i < 24; i += 2) {
        svg.appendChild(text(String(i), { x: x(i), y: height - 10, 'text-anchor': 'middle' }));
    }

    container.appendChild(svg);
}
