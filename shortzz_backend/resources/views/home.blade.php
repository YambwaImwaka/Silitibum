<!DOCTYPE html>
<html lang="en" dir="ltr">

<head>
    <meta charset="utf-8" />
    <title>{{ $appName }} &ndash; {{ __('Short videos, live streams and chat') }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="{{ $description }}" />
    <link rel="canonical" href="{{ $homeUrl }}" />
    <meta property="og:type" content="website" />
    <meta property="og:site_name" content="{{ $appName }}" />
    <meta property="og:title" content="{{ $appName }} &ndash; {{ __('Short videos, live streams and chat') }}" />
    <meta property="og:description" content="{{ $description }}" />
    <meta property="og:url" content="{{ $homeUrl }}" />
    <meta property="og:image" content="{{ asset('assets/img/favicon.png') }}" />
    <meta name="twitter:card" content="summary" />
    <meta name="theme-color" content="#17120F" />
    <link rel="shortcut icon" href="{{ asset('assets/img/favicon.png') }}">
    <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
    {{-- Self-contained for the same reason as delete_account: this is the
         page search engines and first-time visitors land on, so it renders
         with no JS and no vendor CSS. Colours mirror assets/css/brand.css. --}}
    <style>
        :root {
            color-scheme: light dark;
            --brand: #C2410C;
            --ink: #292524;
            --muted: #78716C;
            --bg: #FAF9F8;
            --card: #FFFFFF;
            --line: #EDEAE7;
            --tint: #FDF1EA;
            --night: #17120F;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font: 16px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            color: var(--ink);
            background: var(--bg);
        }
        .wrap { max-width: 68rem; margin: 0 auto; padding: 0 1rem; }

        .hero {
            color: #fff;
            background:
                radial-gradient(60rem 30rem at 85% -10%, rgba(224, 85, 20, .45), transparent 60%),
                radial-gradient(36rem 20rem at -5% 45%, rgba(154, 52, 18, .35), transparent 65%),
                var(--night);
        }
        .brand {
            display: flex; align-items: center; gap: .65rem;
            padding: 1.5rem 0; font-size: 1.15rem; font-weight: 700; letter-spacing: -.01em;
        }
        .brand img { width: 2.25rem; height: 2.25rem; border-radius: 22%; }
        .hero-grid {
            display: grid; align-items: center; gap: 3rem;
            grid-template-columns: minmax(0, 1fr);
            padding: 2.5rem 0 4.5rem;
        }
        h1 {
            margin: 0 0 1rem; max-width: 20ch;
            font-size: clamp(2rem, 6vw, 3.4rem); line-height: 1.08; letter-spacing: -.025em;
        }
        .lede { margin: 0 0 2rem; max-width: 34rem; font-size: 1.15rem; color: #D6D3D1; }
        .cta {
            display: inline-flex; align-items: center; gap: .65rem;
            padding: .9rem 1.6rem; border-radius: 999px;
            background: #fff; color: var(--night);
            font-weight: 700; text-decoration: none;
            box-shadow: 0 12px 32px rgba(0, 0, 0, .35);
        }
        .cta svg { width: 1.1rem; height: 1.1rem; color: var(--brand); }
        .cta:hover { background: var(--tint); }
        .cta:focus-visible { outline: 3px solid #FDBA74; outline-offset: 3px; }
        .fine { margin: 1rem 0 0; font-size: .9rem; color: #A8A29E; }
        .hero-icon { display: none; }

        main.wrap { padding-top: 4rem; padding-bottom: 4rem; }
        h2 { margin: 0 0 .5rem; font-size: 1.6rem; line-height: 1.25; letter-spacing: -.015em; }
        .intro { margin: 0 0 2rem; max-width: 40rem; color: var(--muted); }
        .features {
            list-style: none; margin: 0; padding: 0;
            display: grid; gap: 1rem;
            grid-template-columns: repeat(auto-fit, minmax(14.5rem, 1fr));
        }
        .features li {
            padding: 1.5rem; border: 1px solid var(--line); border-radius: 16px;
            background: var(--card);
        }
        .features svg {
            display: block; width: 2.75rem; height: 2.75rem; padding: .65rem;
            border-radius: 12px; background: var(--tint); color: var(--brand);
        }
        h3 { margin: 1rem 0 .35rem; font-size: 1.05rem; }
        .features p { margin: 0; color: var(--muted); }

        footer { border-top: 1px solid var(--line); color: var(--muted); font-size: .9rem; }
        footer .wrap {
            display: flex; flex-wrap: wrap; justify-content: space-between; gap: .75rem 2rem;
            padding-top: 1.5rem; padding-bottom: 2.5rem;
        }
        footer nav { display: flex; flex-wrap: wrap; gap: .5rem 1.25rem; }
        footer a { color: inherit; }
        footer a:hover { color: var(--brand); }

        @media (min-width: 52rem) {
            .hero-grid { grid-template-columns: minmax(0, 1fr) auto; padding: 4rem 0 6.5rem; }
            .hero-icon {
                display: block; width: 15rem; height: 15rem; margin-right: 2rem;
                border-radius: 22%; transform: rotate(5deg);
                box-shadow: 0 30px 80px rgba(0, 0, 0, .55), 0 0 0 1px rgba(255, 255, 255, .08);
            }
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --brand: #FDBA74;
                --ink: #E7E5E4;
                --muted: #A8A29E;
                --bg: #17120F;
                --card: #221A16;
                --line: #3A2D28;
                --tint: rgba(194, 65, 12, .18);
            }
            .cta svg { color: #C2410C; }
            .cta:hover { background: #FDF1EA; }
        }
    </style>
</head>

<body>
    <header class="hero">
        <div class="wrap">
            <div class="brand">
                <img src="{{ asset('assets/img/favicon.png') }}" alt="" width="36" height="36">
                <span>{{ $appName }}</span>
            </div>
            <div class="hero-grid">
                <div>
                    <h1>{{ __('Short videos, live streams and friends, in one app.') }}</h1>
                    <p class="lede">{{ $appName }} {{ __('is a social video app. Watch and share short videos, post photos and stories, go live, and chat with the people you follow.') }}</p>
                    <a class="cta" href="{{ $playStoreUrl }}" rel="noopener">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M6 3.5v17a1 1 0 0 0 1.52.86l14-8.5a1 1 0 0 0 0-1.72l-14-8.5A1 1 0 0 0 6 3.5z"/></svg>
                        {{ __('Get it on Google Play') }}
                    </a>
                    <p class="fine">{{ __('Available for Android phones and tablets.') }}</p>
                </div>
                <img class="hero-icon" src="{{ asset('assets/img/favicon.png') }}" alt="" width="240" height="240">
            </div>
        </div>
    </header>

    <main class="wrap">
        <h2>{{ __('What you can do on') }} {{ $appName }}</h2>
        <p class="intro">{{ __('Everything you post, watch and share lives in one feed, with the people you follow at the centre of it.') }}</p>
        <ul class="features">
            <li>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="3"/><path d="M10 9l5 3-5 3z"/></svg>
                <h3>{{ __('Short videos') }}</h3>
                <p>{{ __('Scroll an endless feed of short videos, then shoot, edit and post your own with music.') }}</p>
            </li>
            <li>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
                <h3>{{ __('Posts and stories') }}</h3>
                <p>{{ __('Share photos, videos and text posts, or a story that disappears after 24 hours.') }}</p>
            </li>
            <li>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="2"/><path d="M7.8 16.2a6 6 0 0 1 0-8.4M16.2 7.8a6 6 0 0 1 0 8.4M4.9 19.1a10 10 0 0 1 0-14.2M19.1 4.9a10 10 0 0 1 0 14.2"/></svg>
                <h3>{{ __('Live streams') }}</h3>
                <p>{{ __('Go live to your followers, bring a co-host on screen and talk to viewers in real time.') }}</p>
            </li>
            <li>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.5 8.5 0 0 1-12.6 7.4L3 20l1.1-5.4A8.5 8.5 0 1 1 21 11.5z"/></svg>
                <h3>{{ __('Messages') }}</h3>
                <p>{{ __('Chat privately with friends and send posts and videos straight into a conversation.') }}</p>
            </li>
        </ul>
    </main>

    <footer>
        <div class="wrap">
            <nav>
                <a href="{{ route('privacy_policy') }}">{{ __('Privacy Policy') }}</a>
                <a href="{{ route('terms_of_uses') }}">{{ __('Terms of Use') }}</a>
                <a href="{{ route('delete_account') }}">{{ __('Delete your account') }}</a>
                <a href="mailto:{{ $helpMail }}">{{ $helpMail }}</a>
            </nav>
            <span>&copy; {{ date('Y') }} {{ $appName }}</span>
        </div>
    </footer>
</body>

</html>
