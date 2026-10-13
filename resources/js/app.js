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

// Install: browsers that support it hand over a prompt (beforeinstallprompt); Chrome may
// also show its own banner. Safari, Samsung Internet and others get short instructions.
let installPrompt = null;
const installButtons = () => document.querySelectorAll('[data-pwa-install]');
const isInstalled = () => window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;

if (!isInstalled()) {
	installButtons().forEach((button) => { button.hidden = false; });
}

window.addEventListener('beforeinstallprompt', (event) => {
	installPrompt = event;
});

window.addEventListener('appinstalled', () => {
	installPrompt = null;
	installButtons().forEach((button) => { button.hidden = true; });
});

const installHint = () => {
	const ua = navigator.userAgent;
	if (/iPhone|iPad|iPod/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1)) return 'ios';
	if (/SamsungBrowser/.test(ua)) return 'samsung';
	return 'other';
};

installButtons().forEach((button) => {
	button.addEventListener('click', async () => {
		if (installPrompt) {
			const prompt = installPrompt;
			installPrompt = null;
			prompt.prompt();
			await prompt.userChoice;
			return;
		}
		const dialog = document.querySelector('[data-pwa-dialog]');
		if (!dialog) return;
		const hint = installHint();
		dialog.querySelectorAll('[data-pwa-hint]').forEach((p) => { p.hidden = p.dataset.pwaHint !== hint; });
		dialog.showModal();
	});
});
