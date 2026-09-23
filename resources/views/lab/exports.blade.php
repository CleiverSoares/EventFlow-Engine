<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EventFlow — Exports lab</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        :root {
            --bg: #0f1419;
            --panel: #1a222c;
            --line: #2a3542;
            --text: #e7eef7;
            --muted: #8b9bb0;
            --pending: #e0b34e;
            --processing: #4ea1e0;
            --completed: #3ecf8e;
            --failed: #e05a5a;
            --accent: #7dd3c0;
            --font: "IBM Plex Sans", "Segoe UI", sans-serif;
            --mono: "IBM Plex Mono", ui-monospace, monospace;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            background:
                radial-gradient(1200px 600px at 10% -10%, #1d2a38 0%, transparent 55%),
                radial-gradient(900px 500px at 100% 0%, #243528 0%, transparent 50%),
                var(--bg);
            color: var(--text);
            font-family: var(--font);
        }
        .wrap { max-width: 1100px; margin: 0 auto; padding: 2rem 1.25rem 3rem; }
        header { display: flex; flex-wrap: wrap; gap: 1rem; justify-content: space-between; align-items: end; margin-bottom: 1.75rem; }
        h1 { margin: 0; font-size: 1.75rem; letter-spacing: -0.02em; }
        .sub { color: var(--muted); margin-top: 0.35rem; font-size: 0.95rem; max-width: 40rem; }
        .ws { font-size: 0.85rem; padding: 0.35rem 0.7rem; border: 1px solid var(--line); border-radius: 999px; }
        .ws.ok { border-color: #2f6b52; color: var(--completed); }
        .ws.wait { border-color: #6b5a2f; color: var(--pending); }
        .meta { text-align: right; color: var(--muted); font-size: 0.85rem; }
        .grid-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 0.75rem; margin-bottom: 1.25rem; }
        .stat { background: var(--panel); border: 1px solid var(--line); border-radius: 0.75rem; padding: 1rem; }
        .stat-label { display: block; color: var(--muted); text-transform: uppercase; letter-spacing: 0.06em; font-size: 0.7rem; margin-bottom: 0.35rem; }
        .stat-value { font-size: 1.75rem; font-weight: 600; font-variant-numeric: tabular-nums; }
        .panel { background: var(--panel); border: 1px solid var(--line); border-radius: 0.75rem; overflow: hidden; margin-bottom: 1.25rem; }
        .panel h2 { margin: 0; padding: 0.9rem 1rem; font-size: 0.95rem; border-bottom: 1px solid var(--line); color: var(--accent); }
        table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        th, td { padding: 0.65rem 1rem; text-align: left; border-bottom: 1px solid var(--line); vertical-align: top; }
        th { color: var(--muted); font-weight: 500; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; }
        tr:last-child td { border-bottom: 0; }
        .muted { color: var(--muted); font-size: 0.75rem; margin-top: 0.15rem; }
        .mono { font-family: var(--mono); font-size: 0.8rem; }
        .plan { display: inline-block; margin-left: 0.35rem; padding: 0.1rem 0.4rem; border-radius: 0.35rem; background: #243041; color: var(--muted); font-size: 0.7rem; text-transform: uppercase; }
        .badge { display: inline-block; padding: 0.15rem 0.5rem; border-radius: 999px; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.04em; }
        .num { font-variant-numeric: tabular-nums; font-weight: 600; }
        .status-pending, .badge.status-pending { color: var(--pending); }
        .status-processing, .badge.status-processing { color: var(--processing); }
        .status-completed, .badge.status-completed { color: var(--completed); }
        .status-failed, .badge.status-failed { color: var(--failed); }
        .badge.status-pending { background: #3a3218; }
        .badge.status-processing { background: #183040; }
        .badge.status-completed { background: #183528; }
        .badge.status-failed { background: #3a1c1c; }
        footer { color: var(--muted); font-size: 0.8rem; margin-top: 1rem; }
        @media (max-width: 800px) {
            .grid-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            header { align-items: start; }
            .meta { text-align: left; }
        }
    </style>
</head>
<body>
    <div class="wrap" id="exports-lab" data-snapshot='@json($snapshot)'>
        <header>
            <div>
                <h1>Exports lab</h1>
                <p class="sub">Live multi-tenant fairness board. One snapshot on load, then Reverb WebSocket pushes — no polling.</p>
            </div>
            <div class="meta">
                <div data-ws-status class="ws wait">WebSocket connecting…</div>
                <div data-last-event style="margin-top:0.4rem">waiting for export.updated</div>
            </div>
        </header>

        <div class="grid-stats" data-totals></div>

        <section class="panel">
            <h2>By tenant</h2>
            <table>
                <thead>
                    <tr>
                        <th>Tenant</th>
                        <th>Plan</th>
                        <th>Pending</th>
                        <th>Processing</th>
                        <th>Completed</th>
                        <th>Failed</th>
                    </tr>
                </thead>
                <tbody data-tenants></tbody>
            </table>
        </section>

        <section class="panel">
            <h2>Recent exports</h2>
            <table>
                <thead>
                    <tr>
                        <th>Id</th>
                        <th>Tenant</th>
                        <th>Status</th>
                        <th>Wait</th>
                        <th>Rows</th>
                        <th>Time</th>
                    </tr>
                </thead>
                <tbody data-recent></tbody>
            </table>
        </section>

        <footer>
            Run k6 <code>exports-multi-tenant.js</code> with <code>process-exports</code> + Reverb up.
            Channel <code>exports.lab</code> · event <code>export.updated</code>
        </footer>
    </div>
</body>
</html>
