<!DOCTYPE html>
<html lang="en" dir="ltr">

<head>
    <meta charset="utf-8" />
    <title>{{ __('Delete your account') }} &middot; {{ $appName }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="How to delete your {{ $appName }} account and what happens to your data." />
    <link rel="shortcut icon" href="{{ asset('assets/img/favicon.png') }}">
    {{-- Deliberately self-contained rather than pulling the admin theme:
         this page is linked from the Play Console Data safety form and is
         opened by people who are not signed in and may be on a slow
         connection. It should render with no JS and no vendor CSS. --}}
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 2rem 1rem 4rem;
            font: 16px/1.65 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            color: #1a1a1a;
            background: #ffffff;
        }
        main { max-width: 44rem; margin: 0 auto; }
        h1 { font-size: 1.75rem; line-height: 1.25; margin: 0 0 .25rem; }
        h2 { font-size: 1.15rem; margin: 2.25rem 0 .5rem; }
        .lede { color: #555; margin: 0 0 2rem; }
        ol, ul { padding-left: 1.25rem; }
        li { margin: .35rem 0; }
        code {
            background: #f1f3f5; padding: .1em .4em; border-radius: 4px;
            font-size: .9em; white-space: nowrap;
        }
        .note {
            border-left: 3px solid #d0d7de; padding: .75rem 1rem;
            background: #f6f8fa; border-radius: 0 6px 6px 0; margin: 1rem 0;
        }
        a { color: #0b62d0; }
        footer { margin-top: 3rem; padding-top: 1rem; border-top: 1px solid #e5e7eb; color: #666; font-size: .9rem; }
        @media (prefers-color-scheme: dark) {
            body { color: #e6e6e6; background: #121212; }
            .lede, footer { color: #a0a0a0; }
            code { background: #23262a; }
            .note { background: #1b1e21; border-left-color: #3a4046; }
            a { color: #6ea8fe; }
            footer { border-top-color: #2a2d31; }
        }
    </style>
</head>

<body>
    <main>
        <h1>{{ __('Delete your account') }}</h1>
        <p class="lede">{{ $appName }}</p>

        <h2>{{ __('Delete it yourself, in the app') }}</h2>
        <p>{{ __('This is immediate and does not require contacting us.') }}</p>
        <ol>
            <li>{{ __('Open') }} {{ $appName }} {{ __('and sign in.') }}</li>
            <li>{{ __('Go to your profile, then open') }} <code>{{ __('Settings') }}</code>.</li>
            <li>{{ __('Tap') }} <code>{{ __('Delete Account') }}</code> {{ __('and confirm.') }}</li>
        </ol>

        <h2>{{ __('Request deletion without the app') }}</h2>
        <p>
            {{ __('If you can no longer sign in or have uninstalled the app, email us from the address on your account and we will delete it for you:') }}
            <a href="mailto:{{ $helpMail }}?subject={{ rawurlencode('Account deletion request') }}">{{ $helpMail }}</a>.
        </p>
        <p>{{ __('Include your username or the phone number/email you signed up with so we can identify the account. We may ask one follow-up question to confirm it is yours before deleting anything.') }}</p>

        <h2>{{ __('What is deleted') }}</h2>
        <ul>
            <li>{{ __('Your account and profile, including your bio, profile photo and profile links') }}</li>
            <li>{{ __('Your followers and following relationships') }}</li>
            <li>{{ __('Your stories') }}</li>
            <li>{{ __('Your likes, comment likes and comment replies') }}</li>
            <li>{{ __('Your notifications') }}</li>
            <li>{{ __('Your withdrawal requests') }}</li>
            <li>{{ __('Reports you filed and reports filed about you') }}</li>
        </ul>

        <h2>{{ __('What is kept, and for how long') }}</h2>
        <p>{{ __('Your posts and your comments on other people\'s content are removed from view immediately, but are retained internally for a limited period rather than erased on the spot.') }}</p>
        <div class="note">
            {{ __('This is deliberate: it keeps deleting an account from working as a way to erase harassment or abuse from the record the moment it is reported. Retained content is not visible to anyone in the app.') }}
        </div>
        <ul>
            <li>{{ __('Hidden posts and comments are purged automatically after') }} {{ $retentionDays }} {{ __('days.') }}</li>
            <li>{{ __('We may keep records longer where the law requires it, or where they are needed to resolve a dispute or investigate abuse.') }}</li>
            <li>{{ __('Deleted content may persist in encrypted backups for a short period before those backups roll over.') }}</li>
        </ul>

        <h2>{{ __('Coins and balances') }}</h2>
        <p>{{ __('Deleting your account forfeits any remaining coin balance. Coins have no cash value and are not refundable. If you have coins you want to withdraw, submit the withdrawal first and wait for it to settle before deleting your account.') }}</p>

        <footer>
            <a href="{{ route('privacy_policy') }}">{{ __('Privacy Policy') }}</a>
            &nbsp;&middot;&nbsp;
            <a href="{{ route('terms_of_uses') }}">{{ __('Terms of Use') }}</a>
        </footer>
    </main>
</body>

</html>
