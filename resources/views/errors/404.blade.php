<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Page introuvable — ProxyMed</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; min-height: 100dvh; display: grid; place-items: center; padding: 1.5rem; background: #f4fafb; color: #0f2a33; font: 1rem/1.6 system-ui, sans-serif; }
        main { width: min(100%, 29rem); padding: clamp(1.5rem, 6vw, 3rem); text-align: center; background: white; border: 1px solid #e2ecee; border-radius: .75rem; }
        .proxymed-brand { display: flex; align-items: center; justify-content: center; gap: .65rem; text-align: left; }
        .proxymed-brand-mark { width: 2.75rem; height: 2.75rem; object-fit: contain; }
        .proxymed-brand-name { color: #1f7c81; font-size: 1.4rem; font-weight: 800; letter-spacing: .015em; line-height: 1.25; }
        .proxymed-brand-name span { color: #d23a4f; }
        .proxymed-brand-caption { color: #3f5963; font-size: .75rem; line-height: 1.6; }
        .error-code { margin: 1.75rem 0 .25rem; color: #d23a4f; font-size: 4rem; font-weight: 800; line-height: 1.1; }
        h1 { margin: .75rem 0; font-size: 1.5rem; line-height: 1.3; }
        p { margin: 0; color: #3f5963; }
        a { display: inline-flex; align-items: center; justify-content: center; min-height: 2.75rem; margin-top: 1.75rem; padding: .65rem 1.25rem; border-radius: .65rem; background: #1f7c81; color: white; font-weight: 600; text-decoration: none; }
        a:hover { background: #196368; }
        a:focus-visible { outline: 3px solid #206ca4; outline-offset: 4px; }
        @media (max-width: 639px) {
            body { background: white; padding: 2rem 1.25rem; }
            main { width: 100%; padding: 0; border: 0; border-radius: 0; }
            a { width: 100%; }
        }
    </style>
</head>
<body>
    <main>
        @include('components.brand')
        <p class="error-code">404</p>
        <h1>Page introuvable</h1>
        <p>La page demandée n’existe pas ou n’est plus disponible.</p>
        <a href="{{ url('/') }}">Retour à l’accueil</a>
    </main>
</body>
</html>
