<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="LLM-assisted operator directive interpretation and 24-hour campus energy optimisation.">
    <title>GridWise · Energy Operations Console</title>
    <script>
        (() => {
            try {
                const stored = localStorage.getItem('gridwise-theme');
                if (stored) document.documentElement.dataset.theme = stored;
            } catch (error) {
                /* storage unavailable */
            }
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen font-sans">

<div
    id="console"
    data-samples="{{ json_encode($samples) }}"
    data-provider="{{ json_encode($provider) }}"
    data-endpoint="{{ route('dashboard.optimize', absolute: false) }}"
>
    <header class="sticky top-0 z-40 border-b" style="border-color: var(--border); background: color-mix(in oklab, var(--plane) 88%, transparent); backdrop-filter: blur(12px);">
        <div class="mx-auto flex max-w-[1560px] flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-6">
            <div class="flex items-center gap-3">
                <span class="grid h-9 w-9 place-items-center rounded-[10px]" style="background: var(--accent); color: var(--accent-ink);">
                    <svg viewBox="0 0 24 24" class="h-[18px] w-[18px]" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M13 2 4.5 13.5H11l-1 8.5 8.5-11.5H12l1-8.5Z"/>
                    </svg>
                </span>
                <div>
                    <h1 class="text-[15px] font-semibold leading-tight tracking-tight">GridWise</h1>
                    <p class="text-[11.5px]" style="color: var(--text-muted)">Operator directive interpretation &amp; 24-hour scheduling</p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <span class="chip" id="health-chip">
                    <span class="pulse-dot" id="health-dot" style="background: var(--text-muted)"></span>
                    <span id="health-text">checking /health</span>
                </span>
                <span class="chip" id="provider-chip">
                    <span class="swatch" style="background: var(--accent)"></span>
                    <span id="provider-text">{{ $provider['driver'] }} · {{ $provider['model'] }}</span>
                </span>
                <button type="button" class="btn !px-2.5 !py-2" id="theme-toggle" aria-label="Switch colour theme" title="Switch colour theme">
                    <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" data-icon="sun">
                        <circle cx="12" cy="12" r="4"/>
                        <path d="M12 2v2m0 16v2M2 12h2m16 0h2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4m0-14.2-1.4 1.4M6.3 17.7l-1.4 1.4"/>
                    </svg>
                    <svg viewBox="0 0 24 24" class="hidden h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" data-icon="moon">
                        <path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/>
                    </svg>
                </button>
            </div>
        </div>
    </header>

    <main class="mx-auto grid max-w-[1560px] grid-cols-1 gap-5 px-4 py-5 sm:px-6 xl:grid-cols-[336px_minmax(0,1fr)]">

        <aside class="flex flex-col gap-4">
            <section class="card">
                <div class="card-head">
                    <span class="card-title">Scenario</span>
                    <span class="text-[11px]" style="color: var(--text-muted)" id="sample-count"></span>
                </div>
                <div class="scroll-thin max-h-[15rem] space-y-1 overflow-y-auto p-2" id="sample-list" role="group" aria-label="Public sample scenarios"></div>
            </section>

            <section class="card">
                <div class="card-head">
                    <span class="card-title">Operator notes</span>
                    <button type="button" class="btn !px-2.5 !py-1 !text-[11.5px]" id="add-note">Add</button>
                </div>
                <div class="space-y-2 p-3" id="note-list"></div>
                <p class="px-3 pb-3 text-[11.5px] leading-relaxed" style="color: var(--text-muted)">
                    One to three free-text notes. The model classifies each into a supported directive or marks it
                    <span class="font-medium" style="color: var(--text-secondary)">no_op</span>.
                </p>
            </section>

            <section class="card">
                <div class="card-head">
                    <span class="card-title">Battery</span>
                </div>
                <div class="grid grid-cols-2 gap-2.5 p-3" id="battery-fields"></div>
            </section>

            <button type="button" class="btn btn-primary sweep w-full !py-3 !text-[13.5px]" id="run">
                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M5 4.5v15l13-7.5-13-7.5Z"/>
                </svg>
                <span id="run-label">Interpret &amp; optimise</span>
            </button>
        </aside>

        <section class="flex flex-col gap-5" id="results">

            <div class="card grid place-items-center px-6 py-20 text-center" id="empty-state">
                <div class="max-w-sm space-y-2">
                    <p class="text-sm font-semibold">No plan yet</p>
                    <p class="text-[12.5px] leading-relaxed" style="color: var(--text-muted)">
                        Choose a sample scenario or write your own operator notes, then run the pipeline to see the
                        interpretation, the 24-hour schedule and the replay verification.
                    </p>
                </div>
            </div>

            <div class="hidden flex-col gap-5" id="output">

                <div class="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1.4fr)]">
                    <div class="card p-5" id="hero-card">
                        <p class="eyebrow">Total grid electricity cost</p>
                        <p class="hero-figure mt-2" id="hero-value">0</p>
                        <p class="mt-1 text-[12.5px]" style="color: var(--text-secondary)">BDT across the 24-hour horizon</p>
                        <p class="mt-3 text-[12px] font-medium" id="hero-delta"></p>
                    </div>

                    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3" id="stats"></div>
                </div>

                <div class="card">
                    <div class="card-head">
                        <span class="card-title">Directive interpretation</span>
                        <span class="chip" id="interpretation-source"></span>
                    </div>
                    <div class="space-y-2.5 p-3" id="directives"></div>
                </div>

                <div class="card">
                    <div class="card-head flex-wrap">
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="card-title">24-hour plan</span>
                            <span class="flex flex-wrap items-center gap-3 text-[11.5px]" style="color: var(--text-secondary)" id="legend"></span>
                        </div>
                        <div class="segmented" role="tablist" aria-label="Plan view">
                            <button type="button" role="tab" aria-selected="true" data-view="chart">Chart</button>
                            <button type="button" role="tab" aria-selected="false" data-view="table">Table</button>
                        </div>
                    </div>

                    <div id="view-chart">
                        <div class="px-3 pt-3" id="energy-chart"></div>
                        <div class="px-3 pb-1" id="tariff-chart"></div>
                        <p class="px-4 pb-3 text-[11.5px]" style="color: var(--text-muted)">
                            Each bar stacks the supply that meets demand in that hour. Anything above the demand line is
                            extra energy stored in the battery. Tariff is plotted separately on its own scale.
                        </p>
                    </div>

                    <div class="hidden" id="view-table">
                        <div class="scroll-thin max-h-[26rem] overflow-auto px-3 pb-3">
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

                <div class="grid grid-cols-1 gap-5 2xl:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)]">
                    <div class="card">
                        <div class="card-head">
                            <span class="card-title">Battery state of charge</span>
                            <span class="text-[11.5px]" style="color: var(--text-muted)" id="soc-note"></span>
                        </div>
                        <div class="px-3 py-3" id="soc-chart"></div>
                    </div>

                    <div class="card">
                        <div class="card-head">
                            <span class="card-title">Replay verification</span>
                            <span class="badge" id="replay-badge"></span>
                        </div>
                        <div class="p-3" id="replay"></div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-head">
                        <span class="card-title">API response</span>
                        <button type="button" class="btn !px-2.5 !py-1 !text-[11.5px]" id="copy-json">Copy JSON</button>
                    </div>
                    <pre class="scroll-thin max-h-80 overflow-auto p-4 text-[11.5px] leading-relaxed" style="color: var(--text-secondary)" id="raw-json"></pre>
                </div>
            </div>
        </section>
    </main>
</div>
</body>
</html>
