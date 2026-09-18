<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>GridWise · LLM-Assisted Energy Console</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen font-sans antialiased">
<div class="aurora"></div>
<div class="grid-lines"></div>

<div
    id="console"
    data-samples="{{ json_encode($samples) }}"
    data-provider="{{ json_encode($provider) }}"
    data-endpoint="{{ route('dashboard.optimize') }}"
    class="mx-auto flex min-h-screen w-full max-w-[1680px] flex-col px-4 pb-16 sm:px-6 lg:px-8"
>
    <header class="flex flex-wrap items-center justify-between gap-4 py-6">
        <div class="flex items-center gap-3.5">
            <div class="relative grid h-11 w-11 place-items-center rounded-xl border border-[var(--color-line)] bg-[var(--color-surface-2)]">
                <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.7"
                     stroke-linecap="round" stroke-linejoin="round" style="color: var(--color-solar)">
                    <path d="M13 2 4.5 13.5H11l-1 8.5 8.5-11.5H12l1-8.5Z"/>
                </svg>
            </div>
            <div>
                <h1 class="text-lg font-semibold tracking-tight">GridWise</h1>
                <p class="text-xs text-[var(--color-ink-3)]">LLM-assisted operator directive interpretation</p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <span class="pill" id="health-pill">
                <span class="dot" id="health-dot"></span>
                <span id="health-text">checking /health</span>
            </span>
            <span class="pill" id="provider-pill">
                <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.8"
                     stroke-linecap="round">
                    <path d="M12 3v3m0 12v3M3 12h3m12 0h3M5.6 5.6l2.1 2.1m8.6 8.6 2.1 2.1m0-12.8-2.1 2.1M7.7 16.3l-2.1 2.1"/>
                </svg>
                <span id="provider-text">{{ $provider['driver'] }} · {{ $provider['model'] }}</span>
            </span>
        </div>
    </header>

    <main class="grid flex-1 grid-cols-1 gap-5 xl:grid-cols-[360px_minmax(0,1fr)]">

        <aside class="flex flex-col gap-5">
            <section class="panel">
                <div class="panel-head">
                    <span class="panel-title">Scenario</span>
                    <span class="text-[11px] text-[var(--color-ink-3)]" id="sample-count"></span>
                </div>
                <div class="scroll-thin max-h-64 space-y-1.5 overflow-y-auto p-2.5" id="sample-list"></div>
            </section>

            <section class="panel">
                <div class="panel-head">
                    <span class="panel-title">Operator notes</span>
                    <button type="button" class="btn !px-2.5 !py-1 text-xs" id="add-note">Add note</button>
                </div>
                <div class="space-y-2.5 p-3.5" id="note-list"></div>
                <p class="px-3.5 pb-3.5 text-[11px] leading-relaxed text-[var(--color-ink-3)]">
                    One to three free-text notes. The model classifies each one into a supported directive or marks it
                    <span class="text-[var(--color-ink-2)]">no_op</span>.
                </p>
            </section>

            <section class="panel">
                <div class="panel-head">
                    <span class="panel-title">Battery</span>
                </div>
                <div class="grid grid-cols-2 gap-2.5 p-3.5" id="battery-fields"></div>
            </section>

            <button type="button" class="btn btn-primary w-full !py-3" id="run">
                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round">
                    <path d="M5 4.5v15l13-7.5-13-7.5Z"/>
                </svg>
                <span id="run-label">Interpret &amp; optimise</span>
            </button>
        </aside>

        <section class="flex flex-col gap-5" id="results">
            <div class="panel grid place-items-center p-16 text-center" id="empty-state">
                <div class="max-w-sm space-y-2">
                    <p class="text-sm font-medium text-[var(--color-ink-2)]">No plan yet</p>
                    <p class="text-xs leading-relaxed text-[var(--color-ink-3)]">
                        Pick a sample scenario or write your own operator notes, then run the pipeline to see the
                        interpretation, the 24-hour schedule and the replay verification.
                    </p>
                </div>
            </div>

            <div class="hidden flex-col gap-5" id="output">
                <div class="grid grid-cols-2 gap-3 lg:grid-cols-4" id="kpis"></div>

                <div class="panel">
                    <div class="panel-head">
                        <span class="panel-title">Directive interpretation</span>
                        <span class="pill" id="interpretation-source"></span>
                    </div>
                    <div class="space-y-3 p-3.5" id="directives"></div>
                </div>

                <div class="panel">
                    <div class="panel-head">
                        <span class="panel-title">Hourly energy plan</span>
                        <div class="flex flex-wrap items-center gap-3 text-[11px] text-[var(--color-ink-2)]">
                            <span class="flex items-center gap-1.5"><i class="h-2 w-2 rounded-sm" style="background: var(--color-solar)"></i>solar</span>
                            <span class="flex items-center gap-1.5"><i class="h-2 w-2 rounded-sm" style="background: var(--color-battery)"></i>battery</span>
                            <span class="flex items-center gap-1.5"><i class="h-2 w-2 rounded-sm" style="background: var(--color-grid)"></i>grid</span>
                            <span class="flex items-center gap-1.5"><i class="h-0.5 w-3.5 rounded-full" style="background: var(--color-tariff)"></i>tariff</span>
                        </div>
                    </div>
                    <div class="p-3.5" id="energy-chart"></div>
                </div>

                <div class="grid grid-cols-1 gap-5 2xl:grid-cols-2">
                    <div class="panel">
                        <div class="panel-head">
                            <span class="panel-title">Battery state of charge</span>
                            <span class="text-[11px] text-[var(--color-ink-3)]" id="soc-note"></span>
                        </div>
                        <div class="p-3.5" id="soc-chart"></div>
                    </div>

                    <div class="panel">
                        <div class="panel-head">
                            <span class="panel-title">Replay verification</span>
                            <span class="pill" id="replay-pill"></span>
                        </div>
                        <div class="p-3.5" id="replay"></div>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-head">
                        <span class="panel-title">API response</span>
                        <button type="button" class="btn !px-2.5 !py-1 text-xs" id="copy-json">Copy JSON</button>
                    </div>
                    <pre class="scroll-thin max-h-96 overflow-auto p-3.5 text-[11.5px] leading-relaxed text-[var(--color-ink-2)]" id="raw-json"></pre>
                </div>
            </div>
        </section>
    </main>
</div>
</body>
</html>
