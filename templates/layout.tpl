<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{block name="title"}Недвижимость в Анапе{/block}</title>
    <style>
        :root { --brand:#1f6feb; --ink:#1a1d21; --muted:#6b7280; --line:#e6e8eb; --bg:#f5f7fa; }
        * { box-sizing:border-box; }
        body { margin:0; font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif; color:var(--ink); background:var(--bg); line-height:1.5; }
        a { color:var(--brand); }
        .wrap { max-width:1100px; margin:0 auto; padding:0 20px; }
        .hero { background:linear-gradient(135deg,#1f6feb,#3fb1e6); color:#fff; padding:56px 0; }
        .hero h1 { margin:0 0 8px; font-size:34px; }
        .hero p { margin:0; opacity:.9; }
        h2 { font-size:24px; margin:40px 0 16px; }
        .grid { display:grid; gap:20px; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); }
        .card { background:#fff; border:1px solid var(--line); border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.04); display:flex; flex-direction:column; }
        .card__photo { height:180px; background:#eceff3 center/cover no-repeat; display:flex; align-items:center; justify-content:center; color:var(--muted); font-size:14px; }
        .card__body { padding:16px; display:flex; flex-direction:column; gap:8px; }
        .card__price { font-size:20px; font-weight:700; }
        .card__addr { color:var(--muted); font-size:14px; }
        .tags { display:flex; flex-wrap:wrap; gap:6px; }
        .tag { background:#eef3ff; color:var(--brand); border-radius:999px; padding:2px 10px; font-size:12px; }
        .specs { font-size:14px; color:var(--ink); }
        .desc { font-size:14px; color:#374151; border-top:1px solid var(--line); padding-top:8px; }
        .desc p { margin:0 0 6px; }
        .house { background:#fff; border:1px solid var(--line); border-radius:12px; padding:16px; }
        .house__name { font-weight:700; font-size:16px; }
        .house__meta { color:var(--muted); font-size:14px; margin-top:4px; }
        footer { color:var(--muted); font-size:13px; padding:40px 0; text-align:center; }
        .empty { color:var(--muted); }
    </style>
</head>
<body>
    <div class="hero"><div class="wrap">
        <h1>{block name="hero_title"}Недвижимость в Анапе{/block}</h1>
        <p>{block name="hero_sub"}Актуальные предложения вторичного рынка{/block}</p>
    </div></div>
    <div class="wrap">
        {block name="content"}{/block}
    </div>
    <footer><div class="wrap">TestDB · демонстрационный лендинг</div></footer>
</body>
</html>
