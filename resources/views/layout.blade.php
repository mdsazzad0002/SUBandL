<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Subscription') — {{ config('app.name') }}</title>
    <style>
        :root { --bg:#f5f6f8; --card:#fff; --text:#1f2430; --muted:#6b7280; --line:#e5e7eb; --primary:#2563eb; --ok:#15803d; --warn:#b45309; --bad:#b91c1c; }
        @media (prefers-color-scheme: dark) { :root { --bg:#0f1115; --card:#181b22; --text:#e6e8ec; --muted:#9aa1ad; --line:#2a2f3a; --primary:#60a5fa; --ok:#4ade80; --warn:#fbbf24; --bad:#f87171; } }
        * { box-sizing: border-box; }
        body { margin:0; background:var(--bg); color:var(--text); font:14px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; }
        .wrap { max-width:880px; margin:0 auto; padding:24px 16px 48px; }
        h1 { font-size:22px; margin:0 0 4px; } h2 { font-size:16px; margin:0 0 12px; }
        .muted { color:var(--muted); }
        .card { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:20px; margin-top:16px; }
        .tabs { display:flex; gap:4px; margin-top:20px; border-bottom:1px solid var(--line); flex-wrap:wrap; }
        .tabs a { padding:8px 14px; color:var(--muted); text-decoration:none; border-bottom:2px solid transparent; }
        .tabs a.active { color:var(--primary); border-color:var(--primary); font-weight:600; }
        .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:12px 20px; }
        .label { font-size:12px; color:var(--muted); text-transform:uppercase; letter-spacing:.03em; }
        .value { font-weight:600; word-break:break-word; }
        .badge { display:inline-block; padding:2px 10px; border-radius:99px; font-size:12px; font-weight:600; border:1px solid currentColor; }
        .ok { color:var(--ok); } .warn { color:var(--warn); } .bad { color:var(--bad); }
        input[type=text] { width:100%; padding:9px 12px; border:1px solid var(--line); border-radius:8px; background:transparent; color:inherit; font:inherit; }
        .row { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
        .row input { flex:1 1 260px; }
        button { padding:9px 16px; border-radius:8px; border:1px solid var(--primary); background:var(--primary); color:#fff; font:inherit; font-weight:600; cursor:pointer; }
        button.ghost { background:transparent; color:var(--primary); }
        button:disabled { opacity:.55; cursor:wait; }
        table { width:100%; border-collapse:collapse; font-size:13px; }
        th, td { text-align:left; padding:8px 6px; border-bottom:1px solid var(--line); vertical-align:top; }
        #flash { white-space:pre-line; margin-top:16px; padding:10px 14px; border-radius:8px; display:none; border:1px solid currentColor; }
        a { color:var(--primary); }
    </style>
</head>
<body>
<div class="wrap">
    @yield('content')
</div>
<script>
    window.subandl = {
        async request(method, url, body) {
            const res = await fetch(url, {
                method,
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                },
                body: body ? JSON.stringify(body) : undefined,
            });
            let data = {};
            try { data = await res.json(); } catch (e) {}
            return { ok: res.ok, status: res.status, data };
        },
        flash(message, tone) {
            const el = document.getElementById('flash');
            if (!el) return;
            el.textContent = message;
            el.className = tone || '';
            el.style.display = message ? 'block' : 'none';
        },
        // The provider's reachability is checked from the browser (see ServerHealth).
        async reportHealth(providerUrl) {
            if (!providerUrl) return;
            let healthy = true;
            const ctrl = new AbortController();
            const t = setTimeout(() => ctrl.abort(), 8000);
            try { await fetch(providerUrl, { mode: 'no-cors', cache: 'no-store', signal: ctrl.signal }); }
            catch (e) { healthy = false; }
            clearTimeout(t);
            this.request('POST', @json(url(config('subandl.routes.prefix', '') . '/license/health-report')), { healthy });
        },
    };
</script>
@stack('scripts')
</body>
</html>
