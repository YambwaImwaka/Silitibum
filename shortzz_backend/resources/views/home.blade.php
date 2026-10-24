<!DOCTYPE html>
<html lang="en" dir="ltr">

<head>
    <meta charset="utf-8" />
    <title>{{ $appName }} &ndash; {{ __('Share your moments. Earn from your fans.') }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="{{ $description }}" />
    <link rel="canonical" href="{{ $homeUrl }}" />
    <meta property="og:type" content="website" />
    <meta property="og:site_name" content="{{ $appName }}" />
    <meta property="og:title" content="{{ $appName }} &ndash; {{ __('Share your moments. Earn from your fans.') }}" />
    <meta property="og:description" content="{{ $description }}" />
    <meta property="og:url" content="{{ $homeUrl }}" />
    <meta property="og:image" content="{{ asset('assets/img/landing/snaps.webp') }}" />
    <meta name="twitter:card" content="summary_large_image" />
    <meta name="theme-color" content="#E05514" />
    <link rel="shortcut icon" href="{{ asset('assets/img/favicon.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('assets/img/favicon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    {{-- The app's own typefaces: Unbounded for headings, Outfit for text. --}}
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600&family=Unbounded:wght@600;700&display=swap" rel="stylesheet">
    <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
    {{-- The page search engines and first-time visitors land on: no JS, no
         vendor CSS. Colours are the app's (ColorRes.themeGradient1/2,
         themeAccentSolid). Phone screens are cropped from the Play listing
         images in assets/img/landing. --}}
    <style>
        :root {
            color-scheme: light dark;
            --brand: #E05514;
            --brand-deep: #9A3412;
            --accent: #C2410C;
            --ink: #17120F;
            --muted: #6B625D;
            --bg: #fff;
            --surface: #fff;
            --nav-bg: rgba(255,255,255,.88);
            --line: #F1E7E0;
            --soft: #FFF4EC;
            --soft-line: #F6D9C6;
            --ring: #F3D9C9;
            --btn-bg: #17120F;
            --btn-fg: #fff;
            --glow: .55;
            --radius: 22px;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --accent: #F2793A;
                --ink: #F7F1EC;
                --muted: #B5A9A1;
                --bg: #120E0C;
                --surface: #1C1714;
                --nav-bg: rgba(18,14,12,.85);
                --line: #2E2521;
                --soft: #1A1411;
                --soft-line: #3A2A22;
                --ring: #3A2A22;
                --btn-bg: #F7F1EC;
                --btn-fg: #17120F;
                --glow: .3;
            }
        }
        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            margin: 0;
            font-family: "Outfit", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            font-size: 17px;
            line-height: 1.6;
            color: var(--ink);
            background: var(--bg);
            -webkit-font-smoothing: antialiased;
        }
        img { max-width: 100%; display: block; }
        a { color: inherit; text-decoration: none; }
        .wrap { width: min(1180px, 100% - 40px); margin-inline: auto; }
        h1, h2, h3 { font-family: "Unbounded", "Outfit", sans-serif; letter-spacing: -.01em; line-height: 1.12; margin: 0; }
        em { font-style: normal; color: var(--accent); }

        /* Header */
        .nav { position: sticky; top: 0; z-index: 10; background: var(--nav-bg); backdrop-filter: blur(12px); border-bottom: 1px solid var(--line); }
        .nav .wrap { display: flex; align-items: center; gap: 28px; height: 72px; }
        .brand { display: flex; align-items: center; gap: 10px; font-family: "Unbounded", sans-serif; font-weight: 700; font-size: 1.15rem; }
        .brand img { width: 36px; height: 36px; border-radius: 9px; }
        .links { display: flex; gap: 26px; margin-left: 12px; color: var(--muted); font-weight: 500; }
        .links a:hover { color: var(--accent); }
        .nav .btn { margin-left: auto; }

        /* Buttons */
        .btn { display: inline-flex; align-items: center; gap: 10px; border-radius: 14px; font-weight: 600; transition: transform .15s ease, box-shadow .15s ease; }
        .btn:hover { transform: translateY(-1px); }
        .btn-dark { background: var(--btn-bg); color: var(--btn-fg); padding: 11px 18px; }
        .btn-dark:hover { box-shadow: 0 10px 24px rgba(23,18,15,.25); }
        .btn-play { padding: 12px 22px 12px 18px; }
        .btn-play small { display: block; font-size: .68rem; font-weight: 500; letter-spacing: .06em; opacity: .8; line-height: 1; }
        .btn-play strong { display: block; font-size: 1.15rem; line-height: 1.2; }
        .btn-play svg { width: 26px; height: 26px; }
        .btn-white { background: #fff; color: #17120F; }
        .link-arrow { font-weight: 600; color: var(--accent); }
        .link-arrow:hover { text-decoration: underline; }

        /* Phones: a device frame around a real app screen */
        .device { position: absolute; width: 230px; aspect-ratio: 1 / 2; padding: 8px; border-radius: 40px; background: #0F0C0B; box-shadow: 0 30px 60px rgba(60,25,8,.28), inset 0 0 0 1.5px #3A322D; }
        .device > img { width: 100%; height: 100%; object-fit: cover; object-position: top; border-radius: 32px; background: #000; }
        .glow { position: absolute; border-radius: 50%; filter: blur(60px); opacity: var(--glow); pointer-events: none; }
        .rings, .rings::before, .rings::after { position: absolute; border: 1px solid var(--ring); border-radius: 50%; }
        .rings { width: 520px; height: 520px; left: 50%; top: 50%; transform: translate(-50%, -50%); }
        .rings::before { content: ""; inset: 70px; }
        .rings::after { content: ""; inset: 140px; }

        /* Hero */
        .hero { position: relative; overflow: hidden; padding: 64px 0 56px; }
        .hero .wrap { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; align-items: center; }
        .eyebrow { display: inline-flex; align-items: center; gap: 8px; padding: 7px 14px; border-radius: 999px; background: var(--soft); color: var(--accent); font-weight: 600; font-size: .92rem; }
        .eyebrow::before { content: ""; width: 8px; height: 8px; border-radius: 50%; background: var(--brand); }
        .hero h1 { font-size: clamp(2.3rem, 5vw, 3.9rem); margin: 22px 0 18px; }
        .lede { font-size: 1.18rem; color: var(--muted); max-width: 33rem; margin: 0 0 30px; }
        .cta-row { display: flex; flex-wrap: wrap; align-items: center; gap: 22px; }
        .trust { display: flex; flex-wrap: wrap; gap: 10px 22px; list-style: none; padding: 0; margin: 30px 0 0; color: var(--muted); font-size: .95rem; }
        .trust li { display: flex; align-items: center; gap: 8px; }
        .trust li::before { content: ""; width: 8px; height: 8px; border-radius: 50%; background: var(--brand); }
        .hero-art { position: relative; width: 100%; max-width: 560px; height: 560px; margin-inline: auto; }
        .hero-art .g1 { width: 380px; height: 380px; right: 4%; top: 6%; background: #FF8A4C; }
        .hero-art .g2 { width: 300px; height: 300px; left: 0; bottom: 0; background: #FFC9A8; }
        /* Overlaps stay under a fifth of a side phone, so the splash's
           centred wordmark is never covered. */
        .hero-art .d1, .hero-art .d3 { width: 205px; }
        .hero-art .d1 { left: 0; top: 80px; transform: rotate(-8deg); }
        .hero-art .d2 { left: 50%; top: 10px; width: 240px; transform: translateX(-50%); z-index: 2; }
        .hero-art .d3 { right: 0; top: 100px; transform: rotate(8deg); }

        /* Feature cards */
        section { padding: 84px 0; }
        .section-head { text-align: center; max-width: 44rem; margin: 0 auto 46px; }
        .section-head h2 { font-size: clamp(1.8rem, 3.6vw, 2.6rem); margin-bottom: 14px; }
        .section-head p { color: var(--muted); margin: 0; font-size: 1.1rem; }
        .cards { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; }
        .card { padding: 26px; border: 1px solid var(--line); border-radius: var(--radius); background: var(--surface); transition: box-shadow .2s ease, transform .2s ease; }
        .card:hover { transform: translateY(-3px); box-shadow: 0 18px 40px rgba(60,25,8,.08); }
        .icon { width: 50px; height: 50px; border-radius: 15px; display: grid; place-items: center; background: linear-gradient(135deg, var(--brand), var(--brand-deep)); color: #fff; margin-bottom: 18px; }
        .icon svg { width: 25px; height: 25px; }
        .card h3 { font-size: 1.12rem; margin-bottom: 8px; }
        .card p { margin: 0; color: var(--muted); font-size: 1rem; }

        /* Split sections: a phone on rings beside a list */
        .split .wrap { display: grid; grid-template-columns: 1fr 1fr; gap: 64px; align-items: center; }
        .split.alt { background: var(--soft); }
        .split.alt .art { order: 2; }
        .art { position: relative; display: grid; place-items: center; min-height: 600px; }
        .art .rings { width: 480px; height: 480px; }
        .art .glow { width: 320px; height: 320px; background: #FF9A63; left: 50%; top: 50%; transform: translate(-50%, -50%); }
        .art .device { position: relative; width: 260px; transform: rotate(-4deg); z-index: 1; }
        .poster { position: relative; z-index: 1; width: 290px; height: auto; border-radius: 28px; box-shadow: 0 30px 60px rgba(60,25,8,.22); transform: rotate(4deg); }
        .kicker { color: var(--accent); font-weight: 600; letter-spacing: .08em; text-transform: uppercase; font-size: .85rem; }
        .split h2 { font-size: clamp(1.8rem, 3.4vw, 2.5rem); margin: 12px 0 18px; }
        .split p.lead { color: var(--muted); font-size: 1.1rem; margin: 0 0 26px; }
        .points { list-style: none; padding: 0; margin: 0; display: grid; gap: 18px; }
        .points li { display: grid; grid-template-columns: 46px 1fr; gap: 14px; align-items: start; }
        .points .dot { width: 46px; height: 46px; border-radius: 14px; background: var(--surface); border: 1px solid var(--line); display: grid; place-items: center; color: var(--accent); }
        .split.alt .points .dot { border-color: var(--soft-line); }
        .points .dot svg { width: 22px; height: 22px; }
        .points strong { display: block; font-size: 1.05rem; }
        .points span { color: var(--muted); font-size: .98rem; }

        /* What a live room looks like, drawn over a real camera frame */
        .live-ui { position: absolute; inset: 8px; border-radius: 32px; overflow: hidden; display: flex; flex-direction: column; justify-content: space-between; padding: 16px 12px 14px; color: #fff; font-size: 12px; line-height: 1.35; background: linear-gradient(180deg, rgba(0,0,0,.45), rgba(0,0,0,0) 22%, rgba(0,0,0,0) 52%, rgba(0,0,0,.65)); }
        .live-top { display: flex; align-items: center; gap: 6px; }
        .host { display: flex; align-items: center; gap: 7px; padding: 4px 12px 4px 4px; border-radius: 999px; background: rgba(0,0,0,.38); backdrop-filter: blur(6px); }
        .host i { width: 26px; height: 26px; border-radius: 50%; display: grid; place-items: center; font-style: normal; font-weight: 600; background: linear-gradient(135deg, var(--brand), var(--brand-deep)); }
        .tag-live { margin-left: auto; padding: 3px 8px; border-radius: 6px; font-weight: 700; font-size: 10px; letter-spacing: .08em; background: linear-gradient(90deg, var(--brand), var(--brand-deep)); }
        .live-chat { display: grid; gap: 6px; }
        .live-chat p { margin: 0; justify-self: start; max-width: 88%; padding: 6px 10px; border-radius: 14px; background: rgba(0,0,0,.4); backdrop-filter: blur(6px); }
        .live-chat p.joined { background: none; padding: 0 2px; opacity: .8; }
        .live-chat p.gifted { background: linear-gradient(90deg, rgba(224,85,20,.92), rgba(154,52,18,.92)); }
        .live-bar { display: flex; align-items: center; gap: 8px; margin-top: 10px; }
        .live-bar .say { flex: 1; min-width: 0; padding: 8px 12px; border-radius: 999px; background: rgba(255,255,255,.18); color: rgba(255,255,255,.85); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .live-bar .round { width: 32px; height: 32px; flex: none; border-radius: 50%; display: grid; place-items: center; background: rgba(255,255,255,.18); }
        .live-bar .round.hot { background: linear-gradient(135deg, var(--brand), var(--brand-deep)); }
        .live-bar svg { width: 16px; height: 16px; }

        /* Stats */
        .stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-top: 30px; }
        .stat { background: var(--surface); border-radius: 18px; padding: 18px; border: 1px solid var(--soft-line); }
        .stat b { display: block; font-family: "Unbounded", sans-serif; font-size: 1.5rem; color: var(--accent); }
        .stat span { color: var(--muted); font-size: .92rem; }

        /* Gallery */
        .gallery { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; }
        .gallery img { width: 100%; height: auto; border-radius: 24px; box-shadow: 0 14px 30px rgba(60,25,8,.12); }

        /* Final call to action */
        .final { padding: 84px 0; }
        .final .band { position: relative; overflow: hidden; border-radius: 30px; padding: 56px; background: linear-gradient(120deg, var(--brand), var(--brand-deep)); color: #fff; display: grid; grid-template-columns: 1.4fr 1fr; gap: 30px; align-items: center; }
        .final h2 { font-size: clamp(1.7rem, 3.2vw, 2.4rem); margin-bottom: 12px; }
        .final p { margin: 0; opacity: .9; font-size: 1.1rem; }
        .final .actions { justify-self: end; position: relative; z-index: 1; }
        .final .band::after { content: ""; position: absolute; right: -80px; top: -80px; width: 300px; height: 300px; border-radius: 50%; border: 1px solid rgba(255,255,255,.25); box-shadow: 0 0 0 60px rgba(255,255,255,.05), 0 0 0 120px rgba(255,255,255,.04); }

        /* Footer */
        footer { border-top: 1px solid var(--line); padding: 34px 0; color: var(--muted); font-size: .95rem; }
        footer .wrap { display: flex; flex-wrap: wrap; gap: 18px 30px; align-items: center; }
        footer nav { display: flex; flex-wrap: wrap; gap: 8px 22px; margin-left: auto; }
        footer a:hover { color: var(--accent); }

        a:focus-visible { outline: 3px solid var(--brand); outline-offset: 3px; border-radius: 8px; }

        @media (max-width: 980px) {
            .links { display: none; }
            .hero .wrap, .split .wrap, .final .band { grid-template-columns: 1fr; }
            .hero { padding-top: 40px; }
            .split.alt .art { order: 0; }
            .art { min-height: 540px; }
            .cards { grid-template-columns: repeat(2, 1fr); }
            .gallery { display: flex; overflow-x: auto; scroll-snap-type: x mandatory; padding: 4px 4px 18px; }
            .gallery img { flex: 0 0 auto; width: 210px; scroll-snap-align: start; }
            .final .actions { justify-self: start; }
        }
        @media (max-width: 560px) {
            body { font-size: 16px; }
            .wrap { width: min(1180px, 100% - 32px); }
            .nav .wrap { height: 64px; }
            .nav .btn-dark { padding: 9px 14px; font-size: .92rem; }
            .hero-art { height: 340px; }
            .hero-art .rings { width: 340px; height: 340px; }
            .hero-art .device { width: 128px; padding: 5px; border-radius: 24px; }
            .hero-art .device > img { border-radius: 19px; }
            .hero-art .d1 { left: 10px; top: 46px; }
            .hero-art .d2 { width: 150px; top: 0; }
            .hero-art .d3 { right: 10px; top: 56px; }
            section { padding: 60px 0; }
            .final { padding: 60px 0; }
            .cards { grid-template-columns: 1fr; gap: 14px; }
            .card { display: grid; grid-template-columns: 50px 1fr; gap: 4px 16px; padding: 20px; }
            .card .icon { grid-row: span 2; margin: 0; }
            .art { min-height: 480px; }
            .art .rings { width: 340px; height: 340px; }
            .art .device { width: 220px; padding: 7px; border-radius: 36px; }
            .art .device > img { border-radius: 29px; }
            .live-ui { inset: 7px; border-radius: 29px; font-size: 11px; }
            .poster { width: 240px; }
            .stats { gap: 10px; }
            .stat { padding: 14px 12px; }
            .stat b { font-size: 1.2rem; }
            .stat span { font-size: .85rem; }
            .final .band { padding: 36px 26px; }
            .gallery img { width: 170px; }
        }
        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }
            .btn, .card { transition: none; }
        }
    </style>
</head>

<body>
    @php
        $play = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="#00D7FE" d="M3.6 2.3 13.3 12 3.6 21.7a1.4 1.4 0 0 1-.6-1.2V3.5c0-.5.2-.9.6-1.2Z"/><path fill="#FFCE00" d="m16.6 15.3-3.3-3.3 3.3-3.3 3.9 2.2c1.1.6 1.1 1.6 0 2.2l-3.9 2.2Z"/><path fill="#FF3A44" d="M16.6 15.3 13.3 12l-9.7 9.7c.4.3 1 .4 1.6 0l11.4-6.4Z"/><path fill="#00F076" d="M16.6 8.7 5.2 2.3c-.6-.4-1.2-.3-1.6 0l9.7 9.7 3.3-3.3Z"/></svg>';
        $gift = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 12v9H4v-9M2 7h20v5H2zM12 22V7M12 7S10.5 2 7.5 2 6 7 12 7Zm0 0s1.5-5 4.5-5S18 7 12 7Z"/></svg>';
        $chat = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a8 8 0 0 1-11.7 7.1L4 20l.9-5.3A8 8 0 1 1 21 12Z"/></svg>';
        $share = (int) ($sharePercent ?? 60);
        // Cash-out copy follows is_withdrawal_on, so the page never promises
        // withdrawals while they are paused.
        $payouts = (bool) ($withdrawalsOn ?? false);
        $gallery = [
            'feed' => __('The Silitibum feed'),
            'discover' => __('Trending hashtags'),
            'identity' => __('Your profile'),
            'control' => __('Settings'),
        ];
    @endphp

    <header class="nav">
        <div class="wrap">
            <a class="brand" href="{{ $homeUrl }}" aria-label="{{ $appName }}">
                <img src="{{ asset('assets/img/logo.png') }}" alt="" width="36" height="36">
                {{ $appName }}
            </a>
            <nav class="links" aria-label="{{ __('Sections') }}">
                <a href="#features">{{ __('Features') }}</a>
                <a href="#creators">{{ __('Creators') }}</a>
                <a href="#live">{{ __('Live') }}</a>
                <a href="#screens">{{ __('Screens') }}</a>
            </nav>
            <a class="btn btn-dark" href="{{ $playStoreUrl }}" rel="noopener">{{ __('Get the app') }}</a>
        </div>
    </header>

    <main>
        <section class="hero">
            <div class="wrap">
                <div>
                    <span class="eyebrow">{{ __('Now on Google Play') }}</span>
                    <h1>{!! __('Share your <em>moments</em>.<br>Earn from your <em>fans</em>.') !!}</h1>
                    <p class="lede">{{ __('Short videos, live streams and chat — and gifts from your fans that turn into earnings in kwacha.') }}</p>
                    <div class="cta-row">
                        <a class="btn btn-dark btn-play" href="{{ $playStoreUrl }}" rel="noopener">
                            {!! $play !!}
                            <span><small>{{ __('GET IT ON') }}</small><strong>Google Play</strong></span>
                        </a>
                        <a class="link-arrow" href="#creators">{{ __('How creators earn') }} &rarr;</a>
                    </div>
                    <ul class="trust">
                        <li>{{ __('Free to use') }}</li>
                        <li>{{ __('Creators keep :share% of gifts', ['share' => $share]) }}</li>
                        <li>{{ __('Earnings in kwacha') }}</li>
                    </ul>
                </div>
                <div class="hero-art" aria-hidden="true">
                    <span class="glow g1"></span>
                    <span class="glow g2"></span>
                    <span class="rings"></span>
                    <div class="device d1"><img src="{{ asset('assets/img/landing/screen-feed.webp') }}" alt="" width="507" height="982"></div>
                    <div class="device d2"><img src="{{ asset('assets/img/landing/screen-snaps.webp') }}" alt="" width="452" height="970"></div>
                    <div class="device d3"><img src="{{ asset('assets/img/landing/screen-splash.webp') }}" alt="" width="369" height="796"></div>
                </div>
            </div>
        </section>

        <section id="features">
            <div class="wrap">
                <div class="section-head">
                    <h2>{!! __('Everything you need to <em>show up</em>') !!}</h2>
                    <p>{{ __('Record, go live, chat and earn — in one app built for Zambian creators and their fans.') }}</p>
                </div>
                <div class="cards">
                    <article class="card">
                        <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="13" height="14" rx="3"/><path d="m16 10 5-3v10l-5-3"/></svg></div>
                        <h3>{{ __('Snaps') }}</h3>
                        <p>{{ __('Short videos with music and filters, and hashtags that help new fans find you.') }}</p>
                    </article>
                    <article class="card">
                        <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="2"/><path d="M16.2 7.8a6 6 0 0 1 0 8.4M7.8 16.2a6 6 0 0 1 0-8.4M19.1 4.9a10 10 0 0 1 0 14.2M4.9 19.1a10 10 0 0 1 0-14.2"/></svg></div>
                        <h3>{{ __('Live') }}</h3>
                        <p>{{ __('Go live, take gifts in real time, bring friends on as co-hosts and battle other creators.') }}</p>
                    </article>
                    <article class="card">
                        <div class="icon">{!! $chat !!}</div>
                        <h3>{{ __('Chat') }}</h3>
                        <p>{{ __('Message friends with photos, voice notes and gifts, and keep up with everyone you follow.') }}</p>
                    </article>
                    <article class="card">
                        <div class="icon">{!! $gift !!}</div>
                        <h3>{{ __('Earnings') }}</h3>
                        <p>
                            @if ($payouts)
                                {{ __('Fans send gifts on your snaps and lives. You keep :share% and withdraw to mobile money.', ['share' => $share]) }}
                            @else
                                {{ __('Fans send gifts on your snaps and lives. You keep :share% of what they spend.', ['share' => $share]) }}
                            @endif
                        </p>
                    </article>
                </div>
            </div>
        </section>

        <section id="creators" class="split alt">
            <div class="wrap">
                <div class="art" aria-hidden="true">
                    <span class="glow"></span>
                    <span class="rings"></span>
                    <img class="poster" src="{{ asset('assets/img/landing/rewards.webp') }}" alt="" width="768" height="1376" loading="lazy">
                </div>
                <div>
                    <span class="kicker">{{ __('For creators') }}</span>
                    <h2>{!! __('Your fans can now <em>pay you</em> for what you make') !!}</h2>
                    <p class="lead">{{ __('Every gift a fan sends on your snaps, posts and lives turns into earnings for you, counted in kwacha.') }}</p>
                    <ul class="points">
                        <li><span class="dot">{!! $gift !!}</span>
                            <div><strong>{{ __('Fans send gifts') }}</strong><span>{{ __('On your snaps, posts and lives — in one tap.') }}</span></div></li>
                        <li><span class="dot"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 5 5 19M6.5 9a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5ZM17.5 20a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5Z"/></svg></span>
                            <div><strong>{{ __('You keep :share%', ['share' => $share]) }}</strong><span>{{ __('Of everything fans spend on gifts to you, in kwacha.') }}</span></div></li>
                        <li><span class="dot"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M11 18h2"/></svg></span>
                            @if ($payouts)
                                <div><strong>{{ __('Withdraw to mobile money') }}</strong><span>{{ __('Airtel Money, MTN MoMo or Zamtel Kwacha, in your own name.') }}</span></div></li>
                            @else
                                <div><strong>{{ __('Mobile money payouts') }}</strong><span>{{ __('Cash out to Airtel Money, MTN MoMo or Zamtel Kwacha — opening soon.') }}</span></div></li>
                            @endif
                        <li><span class="dot"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 12 2 2 4-4M12 2l2.4 2.1 3.2-.4.6 3.2 2.8 1.6-1.3 3 1.3 3-2.8 1.6-.6 3.2-3.2-.4L12 22l-2.4-2.1-3.2.4-.6-3.2L3 15.5l1.3-3L3 9.5l2.8-1.6.6-3.2 3.2.4Z"/></svg></span>
                            <div><strong>{{ __('Get the blue tick') }}</strong><span>{{ __('Show your fans it’s really you.') }}</span></div></li>
                    </ul>
                    <div class="stats">
                        <div class="stat"><b>{{ $share }}%</b><span>{{ __('of gifts to you') }}</span></div>
                        <div class="stat"><b>K</b><span>{{ __('earnings in kwacha') }}</span></div>
                        <div class="stat"><b>{{ __('Free') }}</b><span>{{ __('to join and post') }}</span></div>
                    </div>
                </div>
            </div>
        </section>

        <section id="live" class="split">
            <div class="wrap">
                <div class="art" aria-hidden="true">
                    <span class="glow"></span>
                    <span class="rings"></span>
                    <div class="device">
                        <img src="{{ asset('assets/img/landing/screen-live.webp') }}" alt="" width="295" height="590" loading="lazy">
                        <div class="live-ui">
                            <div class="live-top">
                                <span class="host"><i>M</i><b>Mwila</b></span>
                                <span class="tag-live">LIVE</span>
                            </div>
                            <div>
                                <div class="live-chat">
                                    <p class="joined">Bwalya {{ __('joined') }}</p>
                                    <p><b>Chanda</b> {{ __('this is fire') }} 🔥</p>
                                    <p class="gifted"><b>Natasha</b> {{ __('sent a gift') }} 🌹</p>
                                </div>
                                <div class="live-bar">
                                    <span class="say">{{ __('Say something…') }}</span>
                                    <span class="round hot">{!! $gift !!}</span>
                                    <span class="round"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 21s-7.5-4.6-9.6-9.2C.9 8.4 3 4.5 6.8 4.5c2.1 0 3.6 1.1 5.2 3 1.6-1.9 3.1-3 5.2-3 3.8 0 5.9 3.9 4.4 7.3C19.5 16.4 12 21 12 21Z"/></svg></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div>
                    <span class="kicker">{{ __('Go live') }}</span>
                    <h2>{!! __('Meet your fans <em>live</em>') !!}</h2>
                    <p class="lead">{{ __('Open the Live tab and swipe through who’s on right now. Or go live yourself in seconds.') }}</p>
                    <ul class="points">
                        <li><span class="dot">{!! $chat !!}</span>
                            <div><strong>{{ __('Comments and hearts') }}</strong><span>{{ __('Talk with your audience as it happens.') }}</span></div></li>
                        <li><span class="dot"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/></svg></span>
                            <div><strong>{{ __('Co-hosts and battles') }}</strong><span>{{ __('Bring a friend on, or challenge another creator.') }}</span></div></li>
                        <li><span class="dot">{!! $gift !!}</span>
                            <div><strong>{{ __('Gifts while you’re live') }}</strong><span>{{ __('Every gift counts towards your earnings.') }}</span></div></li>
                    </ul>
                </div>
            </div>
        </section>

        <section id="screens" class="split alt">
            <div class="wrap" style="display: block">
                <div class="section-head">
                    <h2>{!! __('A look <em>inside</em>') !!}</h2>
                    <p>{{ __('Your feed, trending hashtags, your profile and more.') }}</p>
                </div>
                <div class="gallery">
                    @foreach ($gallery as $shot => $label)
                        <img src="{{ asset('assets/img/landing/' . $shot . '.webp') }}" alt="{{ $label }}" loading="lazy" width="768" height="1376">
                    @endforeach
                </div>
            </div>
        </section>

        <section class="final">
            <div class="wrap">
                <div class="band">
                    <div>
                        <h2>{{ __('Your moments deserve an audience') }}</h2>
                        <p>{{ __('Join :app — it’s free on Android.', ['app' => $appName]) }}</p>
                    </div>
                    <div class="actions">
                        <a class="btn btn-white btn-play" href="{{ $playStoreUrl }}" rel="noopener">
                            {!! $play !!}
                            <span><small>{{ __('GET IT ON') }}</small><strong>Google Play</strong></span>
                        </a>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer>
        <div class="wrap">
            <a class="brand" href="{{ $homeUrl }}">
                <img src="{{ asset('assets/img/logo.png') }}" alt="" width="30" height="30">
                {{ $appName }}
            </a>
            <span>&copy; {{ date('Y') }} {{ $appName }}</span>
            <nav aria-label="{{ __('Legal') }}">
                <a href="{{ route('privacy_policy') }}">{{ __('Privacy Policy') }}</a>
                <a href="{{ route('terms_of_uses') }}">{{ __('Terms of Use') }}</a>
                <a href="{{ route('delete_account') }}">{{ __('Delete account') }}</a>
                <a href="mailto:{{ $helpMail }}">{{ $helpMail }}</a>
            </nav>
        </div>
    </footer>
</body>

</html>
