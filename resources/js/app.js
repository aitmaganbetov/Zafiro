const formatPhone = (digits) => {
	let formatted = digits.slice(0, 3);

	if (digits.length > 3) {
		formatted += ` ${digits.slice(3, 6)}`;
	}

	if (digits.length > 6) {
		formatted += `-${digits.slice(6, 8)}`;
	}

	if (digits.length > 8) {
		formatted += `-${digits.slice(8, 10)}`;
	}

	return formatted;
};

document.querySelectorAll('.auth-form').forEach((form) => {
	const phoneInput = form.querySelector('.auth-phone-local');

	if (!phoneInput) {
		return;
	}

	phoneInput.value = formatPhone(phoneInput.value.replace(/\D/g, '').slice(0, 10));

	phoneInput.addEventListener('input', () => {
		const cursor = phoneInput.selectionStart ?? phoneInput.value.length;
		const digitsBeforeCursor = phoneInput.value.slice(0, cursor).replace(/\D/g, '').length;
		const digits = phoneInput.value.replace(/\D/g, '').slice(0, 10);
		const formatted = formatPhone(digits);

		phoneInput.value = formatted;

		let nextCursor = 0;
		let countedDigits = 0;

		while (nextCursor < formatted.length && countedDigits < digitsBeforeCursor) {
			if (/\d/.test(formatted[nextCursor])) {
				countedDigits++;
			}

			nextCursor++;
		}

		if (nextCursor < formatted.length && /\D/.test(formatted[nextCursor])) {
			nextCursor++;
		}

		phoneInput.setSelectionRange(nextCursor, nextCursor);
	});

	form.addEventListener('submit', () => {
		phoneInput.value = phoneInput.value.replace(/\D/g, '');
	});
});

// Remember the club language for the offline page (it is shown when no page can be loaded).
if (document.body.classList.contains('admin-body')) {
	try { localStorage.setItem('zafiro.locale', document.documentElement.lang); } catch (e) {}
}

// Installable app: register the service worker and offer installation where the browser allows it.
if ('serviceWorker' in navigator && window.isSecureContext) {
	window.addEventListener('load', () => {
		navigator.serviceWorker.register('/sw.js').catch(() => {});
	});
}

// Install offer: a dismissible message instead of buttons. Chrome-based browsers hand
// over a prompt (beforeinstallprompt); Safari and Samsung Internet get short steps.
// "Not now" hides it for 30 days; an installed app never shows it.
const banner = document.querySelector('[data-pwa-banner]');
const DISMISS_KEY = 'zafiro.installDismissedAt';
const DISMISS_DAYS = 30;
let installPrompt = null;

const isInstalled = () => window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
const recentlyDismissed = () => {
	try {
		return Date.now() - Number(localStorage.getItem(DISMISS_KEY) || 0) < DISMISS_DAYS * 864e5;
	} catch (e) {
		return false;
	}
};
const manualHint = () => {
	const ua = navigator.userAgent;
	if (/iPhone|iPad|iPod/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1)) return /CriOS|FxiOS|EdgiOS/.test(ua) ? null : 'ios';
	if (/SamsungBrowser/.test(ua)) return 'samsung';
	return null;
};
const showBanner = (hint) => {
	if (!banner || isInstalled() || recentlyDismissed()) return;
	banner.querySelectorAll('[data-pwa-hint]').forEach((p) => { p.hidden = p.dataset.pwaHint !== hint; });
	banner.querySelector('[data-pwa-accept]').hidden = hint !== 'prompt';
	banner.hidden = false;
};
const hideBanner = () => { if (banner) banner.hidden = true; };

window.addEventListener('beforeinstallprompt', (event) => {
	event.preventDefault(); // our message replaces the browser's own banner
	installPrompt = event;
	showBanner('prompt');
});

window.addEventListener('appinstalled', () => {
	installPrompt = null;
	hideBanner();
});

if (banner) {
	const hint = manualHint();
	if (hint) window.addEventListener('load', () => setTimeout(() => showBanner(hint), 1500));

	banner.querySelector('[data-pwa-accept]').addEventListener('click', async () => {
		if (!installPrompt) return;
		const prompt = installPrompt;
		installPrompt = null;
		hideBanner();
		prompt.prompt();
		await prompt.userChoice;
	});

	banner.querySelector('[data-pwa-dismiss]').addEventListener('click', () => {
		try { localStorage.setItem(DISMISS_KEY, String(Date.now())); } catch (e) {}
		hideBanner();
	});
}
