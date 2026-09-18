<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="GridOptima reads campus operator notes with a language model, validates them behind deterministic guardrails and returns a provably cost-optimal 24-hour energy schedule.">
    <title>GridOptima — Smarter Energy, Optimized for Every Hour</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen font-sans">

<div class="glow"></div>

<div
    id="console"
    data-samples="{{ json_encode($samples) }}"
    data-provider="{{ json_encode($provider) }}"
    data-endpoint="{{ route('dashboard.optimize', absolute: false) }}"
>
    <header class="sticky top-0 z-40 border-b" style="border-color: var(--border); background: color-mix(in oklab, var(--plane) 86%, transparent); backdrop-filter: blur(14px);">
        <div class="mx-auto flex max-w-[1640px] flex-wrap items-center justify-between gap-4 px-5 py-4 sm:px-8">
            <div class="flex items-center gap-3.5">
                <span class="brand-mark" aria-hidden="true">
                    <svg viewBox="0 0 28 28" class="h-[22px] w-[22px]" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4 22V15M11 22V9M18 22V13M25 22V5" opacity="0.45"/>
                        <path d="M15.5 2 7 15.5h6l-1 10.5L20.5 12H14l1.5-10Z" fill="currentColor" stroke="none"/>
                    </svg>
                </span>
                <div>
                    <h1 class="brand-word">Grid<span>Optima</span></h1>
                    <p class="brand-tagline">Smarter Energy, Optimized for Every Hour.</p>
                </div>
            </div>

            <nav class="order-3 flex flex-wrap items-center gap-1 lg:order-2" aria-label="Sections">
                <a class="nav-pill" href="#overview" aria-current="true">Overview</a>
                <a class="nav-pill" href="#interpretation">Interpretation</a>
                <a class="nav-pill" href="#plan">24-hour plan</a>
                <a class="nav-pill" href="#verification">Verification</a>
            </nav>

            <div class="order-2 flex flex-wrap items-center gap-2 lg:order-3">
                <span class="chip" id="health-chip">
                    <span class="pulse-dot" id="health-dot" style="background: var(--text-muted)"></span>
                    <span id="health-text">checking /health</span>
                </span>
                <span class="chip" id="provider-chip">
                    <span class="swatch" style="background: var(--accent)"></span>
                    <span id="provider-text">{{ $provider['driver'] }} · {{ $provider['model'] }}</span>
                </span>
            </div>
        </div>
    </header>

    <main class="mx-auto grid max-w-[1640px] grid-cols-1 gap-7 px-5 py-8 sm:px-8 xl:grid-cols-[344px_minmax(0,1fr)]">

        <aside class="flex flex-col gap-6">
            <section class="card">
                <div class="card-head">
                    <span class="card-title">Scenario</span>
                    <span class="text-[11.5px]" style="color: var(--text-muted)" id="sample-count"></span>
                </div>
                <div class="scroll-thin max-h-72 space-y-1.5 overflow-y-auto p-3" id="sample-list" role="group" aria-label="Public sample scenarios"></div>
            </section>

            <section class="card">
                <div class="card-head">
                    <span class="card-title">Operator notes</span>
                    <button type="button" class="btn !px-3 !py-1.5 !text-[11.5px]" id="add-note">Add note</button>
                </div>
                <div class="space-y-3 p-4" id="note-list"></div>
                <p class="px-4 pb-4 text-[11.5px] leading-relaxed" style="color: var(--text-muted)">
                    One to three free-text notes. The model classifies each into a supported directive or marks it
                    <span class="font-medium" style="color: var(--text-secondary)">no_op</span>.
                </p>
            </section>

            <section class="card">
                <div class="card-head">
                    <span class="card-title">Battery</span>
                </div>
                <div class="space-y-4 p-4" id="battery-fields"></div>
            </section>

            <button type="button" class="btn btn-primary sweep w-full !py-3.5 !text-sm" id="run">
                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M5 4.5v15l13-7.5-13-7.5Z"/>
                </svg>
                <span id="run-label">Interpret &amp; optimise</span>
            </button>
        </aside>

        <section class="flex flex-col gap-7" id="results">

            <div class="card grid place-items-center px-8 py-24 text-center" id="empty-state">
                <div class="max-w-sm space-y-3">
                    <div class="mx-auto h-9 w-9 animate-spin rounded-full border-2 border-t-transparent" style="border-color: var(--accent); border-top-color: transparent;"></div>
                    <p class="text-sm font-semibold">Running the pipeline</p>
                    <p class="text-[12.5px] leading-relaxed" style="color: var(--text-muted)">
                        Interpreting the operator notes, validating the directives and solving the 24-hour schedule.
                    </p>
                </div>
            </div>

            <div class="hidden flex-col gap-7" id="output">

                <div class="card overflow-hidden" id="overview">
                    <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,0.95fr)_minmax(0,1.45fr)]">
                        <div class="p-8">
                            <p class="eyebrow">Total grid electricity cost</p>
                            <div class="mt-4 flex items-baseline gap-2.5">
                                <span class="hero-figure" id="hero-value">0</span>
                                <span class="text-base font-medium" style="color: var(--text-muted)">BDT</span>
                            </div>
                            <p class="mt-2 text-[12.5px]" style="color: var(--text-secondary)">across the 24-hour horizon</p>
                            <div class="mt-6" id="hero-delta"></div>
                        </div>

                        <div class="grid grid-cols-1 border-t sm:grid-cols-3 lg:border-l lg:border-t-0" style="border-color: var(--border)" id="metrics"></div>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-7 2xl:grid-cols-[minmax(0,1.45fr)_minmax(0,1fr)]" id="interpretation">
                    <div class="card">
                        <div class="card-head">
                            <span class="card-title">Directive interpretation</span>
                            <span class="chip" id="interpretation-source"></span>
                        </div>
                        <div class="space-y-3.5 p-4" id="directives"></div>
                    </div>

                    <div class="card">
                        <div class="card-head">
                            <span class="card-title">Supply mix</span>
                            <span class="text-[11.5px]" style="color: var(--text-muted)" id="mix-total"></span>
                        </div>
                        <div class="p-5">
                            <div class="mix-bar" id="mix-bar"></div>
                            <div class="mt-4" id="mix-rows"></div>
                        </div>
                    </div>
                </div>

                <div class="card" id="plan">
                    <div class="card-head flex-wrap">
                        <div class="flex flex-wrap items-center gap-4">
                            <span class="card-title">24-hour plan</span>
                            <span class="flex flex-wrap items-center gap-3.5 text-[11.5px]" style="color: var(--text-secondary)" id="legend"></span>
                        </div>
                        <div class="segmented" role="tablist" aria-label="Plan view">
                            <button type="button" role="tab" aria-selected="true" data-view="chart">Chart</button>
                            <button type="button" role="tab" aria-selected="false" data-view="table">Table</button>
                        </div>
                    </div>

                    <div id="view-chart">
                        <div class="px-4 pt-5" id="energy-chart"></div>
                        <div class="px-4 pb-2" id="tariff-chart"></div>
                        <p class="px-6 pb-5 text-[11.5px] leading-relaxed" style="color: var(--text-muted)">
                            Each bar stacks the supply that meets demand in that hour. Anything above the demand line is
                            extra energy stored in the battery. Tariff is plotted separately on its own scale.
                        </p>
                    </div>

                    <div class="hidden" id="view-table">
                        <div class="scroll-thin max-h-[28rem] overflow-auto px-4 pb-4">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Hour</th>
                                        <th scope="col">Demand</th>
                                        <th scope="col">Solar used</th>
                                        <th scope="col">Battery</th>
                                        <th scope="col">Grid</th>
                                        <th scope="col">Stored after</th>
                                        <th scope="col">Tariff</th>
                                        <th scope="col">Hour cost</th>
                                    </tr>
                                </thead>
                                <tbody id="plan-table"></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-7 2xl:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)]" id="verification">
                    <div class="card">
                        <div class="card-head">
                            <span class="card-title">Battery state of charge</span>
                            <span class="text-[11.5px]" style="color: var(--text-muted)" id="soc-note"></span>
                        </div>
                        <div class="px-4 py-5" id="soc-chart"></div>
                    </div>

                    <div class="card">
                        <div class="card-head">
                            <span class="card-title">Replay verification</span>
                            <span class="badge" id="replay-badge"></span>
                        </div>
                        <div class="p-5" id="replay"></div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-head">
                        <span class="card-title">API response</span>
                        <button type="button" class="btn !px-3 !py-1.5 !text-[11.5px]" id="copy-json">Copy JSON</button>
                    </div>
                    <pre class="scroll-thin max-h-80 overflow-auto p-5 text-[11.5px] leading-relaxed" style="color: var(--text-secondary)" id="raw-json"></pre>
                </div>
            </div>
        </section>
    </main>
</div>
</body>
</html>
