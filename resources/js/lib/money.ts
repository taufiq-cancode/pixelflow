/**
 * Money arrives from the server as exact decimal strings in US dollars
 * ("0.083240625"). These helpers only manipulate the string: no parseFloat,
 * so what is shown is exactly what is charged, never rounded.
 */

/**
 * "0.083240625" → "$0.083240625", "25.000000000" → "$25.00",
 * "0.014000000" → "$0.014", "-1.500000000" → "−$1.50".
 */
export function formatUsd(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const negative = value.startsWith('-');
    const unsigned = negative ? value.slice(1) : value;
    const [whole, fraction = ''] = unsigned.split('.');
    const decimals = fraction.replace(/0+$/, '').padEnd(2, '0');
    const groupedWhole = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');

    return `${negative ? '−' : ''}$${groupedWhole}.${decimals}`;
}

/**
 * True when a decimal string is below zero.
 */
export function isNegative(value: string): boolean {
    return value.startsWith('-') && /[1-9]/.test(value);
}

/**
 * Compares two non-negative decimal strings with the same number of
 * decimals (the server always sends nine): -1, 0 or 1.
 */
export function compareUsd(a: string, b: string): number {
    const [aWhole, aFraction = ''] = a.split('.');
    const [bWhole, bFraction = ''] = b.split('.');
    const width = Math.max(aWhole.length, bWhole.length);
    const left = aWhole.padStart(width, '0') + aFraction;
    const right = bWhole.padStart(width, '0') + bFraction;

    return left === right ? 0 : left < right ? -1 : 1;
}

export function formatTokens(count: number): string {
    return count.toLocaleString('en-US');
}
