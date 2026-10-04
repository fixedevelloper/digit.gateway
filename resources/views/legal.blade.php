<!DOCTYPE html>
<html lang="{{ $lang }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} — Digit</title>
    <meta name="description" content="{{ $title }} — Digit">
    <style>
        :root { --bg:#f8fafb; --card:#fff; --text:#1f2933; --muted:#667085; --accent:#00695c; --border:#e6eaee; }
        @media (prefers-color-scheme: dark) {
            :root { --bg:#10181a; --card:#182224; --text:#e6ecef; --muted:#9aa8ad; --accent:#4db6ac; --border:#263336; }
        }
        * { box-sizing: border-box; }
        body { margin:0; background:var(--bg); color:var(--text); font:16px/1.65 system-ui,-apple-system,Segoe UI,Roboto,sans-serif; }
        .wrap { max-width:760px; margin:0 auto; padding:24px 16px 64px; }
        header { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
        h1 { font-size:1.7rem; margin:20px 0 4px; }
        .meta { color:var(--muted); font-size:.85rem; }
        .lang a, .other a { color:var(--accent); text-decoration:none; font-weight:600; }
        .lang a.active { text-decoration:underline; }
        nav.toc, section { background:var(--card); border:1px solid var(--border); border-radius:14px; padding:16px 20px; margin-top:16px; }
        nav.toc ol { margin:8px 0 0; padding-left:0; list-style:none; }
        nav.toc a { color:var(--accent); text-decoration:none; font-size:.92rem; display:block; padding:3px 0; }
        h2 { font-size:1.05rem; margin:0 0 8px; scroll-margin-top:16px; }
        p { margin:0 0 10px; font-size:.95rem; }
        footer { margin-top:28px; font-size:.85rem; color:var(--muted); }
    </style>
</head>
<body>
<div class="wrap">
    <header>
        <strong>Digit</strong>
        <div class="lang">
            <a href="?lang=fr" @class(['active' => $lang === 'fr'])>FR</a> ·
            <a href="?lang=en" @class(['active' => $lang === 'en'])>EN</a>
        </div>
    </header>

    <h1>{{ $title }}</h1>
    <div class="meta">{{ $lang === 'en' ? 'Last updated' : 'Dernière mise à jour' }} : {{ $version }}</div>

    <nav class="toc" aria-label="{{ $lang === 'en' ? 'Contents' : 'Sommaire' }}">
        <strong>{{ $lang === 'en' ? 'Contents' : 'Sommaire' }}</strong>
        <ol>
            @foreach ($sections as $i => $section)
                <li><a href="#s{{ $i + 1 }}">{{ $section['title'] }}</a></li>
            @endforeach
        </ol>
    </nav>

    @foreach ($sections as $i => $section)
        <section id="s{{ $i + 1 }}">
            <h2>{{ $section['title'] }}</h2>
            @foreach ($section['paragraphs'] as $paragraph)
                <p>{{ $paragraph }}</p>
            @endforeach
        </section>
    @endforeach

    <footer class="other">
        <a href="{{ url('/' . $otherDocument) }}?lang={{ $lang }}">{{ $otherTitle }}</a>
    </footer>
</div>
</body>
</html>
