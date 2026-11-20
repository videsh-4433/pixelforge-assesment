// Money arrives from the server as exact decimal strings ("0.211350000"). Converting to Number here is
// for display only; nothing the user is charged is ever computed in the browser.

/** "$0.2114" — enough precision to show sub-cent prices without noise. */
export function usd(decimal, { precise = false } = {}) {
    if (decimal === null || decimal === undefined) return '—';
    const value = Number(decimal);
    const abs = Math.abs(value);
    const digits = precise ? 9 : abs !== 0 && abs < 0.01 ? 6 : 4;
    const formatted = abs.toLocaleString('en-US', {
        minimumFractionDigits: precise ? 9 : 2,
        maximumFractionDigits: digits,
    });
    return `${value < 0 ? '−' : ''}$${formatted}`;
}

/** Full-precision string for tooltips: "$0.211350000". */
export function usdExact(decimal) {
    if (decimal === null || decimal === undefined) return '';
    return decimal.startsWith('-') ? `−$${decimal.slice(1)}` : `$${decimal}`;
}
