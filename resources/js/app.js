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
