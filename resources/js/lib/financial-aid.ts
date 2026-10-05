const USDC_SCALE = 1_000_000n;

/** Keep all six base-unit decimals without passing monetary values through Number. */
export function formatUsdc(baseUnits: string): string {
    if (!/^-?\d+$/.test(baseUnits)) {
        return 'Unavailable';
    }

    const amount = BigInt(baseUnits);
    const absolute = amount < 0n ? -amount : amount;
    const whole = (absolute / USDC_SCALE).toLocaleString('en-US');
    const fraction = (absolute % USDC_SCALE)
        .toString()
        .padStart(6, '0')
        .replace(/0+$/, '')
        .padEnd(2, '0');

    return `${amount < 0n ? '-' : ''}${whole}.${fraction} USDC`;
}

export function requestedAmountError(value: string): string {
    if (!/^\d+(\.\d{1,6})?$/.test(value)) {
        return 'Enter a USDC amount using up to 6 decimal places, without commas.';
    }

    const [whole, fraction = ''] = value.split('.');
    const amount = BigInt(whole) * USDC_SCALE + BigInt(fraction.padEnd(6, '0'));

    if (amount <= 0n || amount > 1_000_000n * USDC_SCALE) {
        return 'Enter an amount greater than 0 and no more than 1,000,000 USDC.';
    }

    return '';
}

export function formatSubmittedAt(value: string): string {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return 'Date unavailable';
    }

    return new Intl.DateTimeFormat('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(date);
}

export function formatAidLabel(value: string): string {
    return value
        .replace(/[_-]+/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}
