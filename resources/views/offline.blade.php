<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="{{ \App\Http\Controllers\PwaController::THEME_COLOR }}">
    <title>{{ __('Нет подключения') }} · Zafiro</title>
    <script>
        (() => {
            const texts = @json($texts);
            let code = null;
            try { code = localStorage.getItem('zafiro.locale'); } catch (e) {}
            code = texts[code] ? code : (navigator.languages || []).map((l) => l.toLowerCase().split('-')[0]).find((l) => texts[l]);
            if (!code) return;
            document.documentElement.lang = code;
            document.addEventListener('DOMContentLoaded', () => {
                document.title = texts[code].title + ' · Zafiro';
                document.querySelectorAll('[data-text]').forEach((el) => { el.textContent = texts[code][el.dataset.text]; });
            });
        })();
    </script>
    <style>
        :root { color-scheme: light dark; }
        body { margin: 0; min-height: 100vh; min-height: 100dvh; display: flex; align-items: center; justify-content: center; padding: 24px; box-sizing: border-box;
            font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: #101713; color: #e5ece7; text-align: center; }
        main { max-width: 360px; }
        img { width: 96px; height: 96px; border-radius: 22px; }
        h1 { margin: 20px 0 8px; font-size: 24px; line-height: 1.3; }
        p { margin: 0 0 24px; color: #a7b5ab; font-size: 16px; line-height: 1.5; }
        button { border: 0; border-radius: 12px; padding: 12px 24px; background: #d90058; color: #fff; font: inherit; font-weight: 600; cursor: pointer; }
        button:focus-visible { outline: 3px solid #fff; outline-offset: 2px; }
    </style>
</head>
<body>
<main>
    <img src="/icons/icon-192.png" alt="">
    <h1 data-text="heading">{{ __('Нет подключения к интернету') }}</h1>
    <p data-text="text">{{ __('Zafiro работает с актуальными данными клуба, поэтому без сети страница недоступна. Проверьте подключение и повторите.') }}</p>
    <button type="button" data-text="retry" onclick="location.reload()">{{ __('Повторить') }}</button>
</main>
<script>addEventListener('online', () => location.reload());</script>
</body>
</html>
