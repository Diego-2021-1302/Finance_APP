export type CurrencyCode = 'DOP' | 'USD' | 'EUR' | 'CAD' | 'OTHER';

const decimalPattern = /^-?(?:0|[1-9]\d*)(?:\.\d{1,2})?$/;

export function decimalToMinor(value: string | number | null | undefined): bigint | null {
  if (typeof value === 'number') {
    if (!Number.isFinite(value)) return null;
    const text = value.toFixed(2);
    return decimalToMinor(text);
  }
  if (typeof value !== 'string') return null;
  const trimmed = value.trim();
  if (!trimmed || !decimalPattern.test(trimmed)) return null;
  const negative = trimmed.startsWith('-');
  const unsigned = negative ? trimmed.slice(1) : trimmed;
  const [wholeText, fractionText = ''] = unsigned.split('.');
  const fraction = (fractionText + '00').slice(0, 2);
  const minor = BigInt(wholeText || '0') * 100n + BigInt(fraction || '0');
  return negative ? -minor : minor;
}

export function minorToDecimal(value: bigint): string {
  const negative = value < 0n;
  const absolute = negative ? -value : value;
  const whole = absolute / 100n;
  const fraction = (absolute % 100n).toString().padStart(2, '0');
  return `${negative ? '-' : ''}${whole}.${fraction}`;
}

export function sumDecimals(values: Array<string | number | null | undefined>): bigint | null {
  let total = 0n;
  for (const value of values) {
    const minor = decimalToMinor(value);
    if (minor === null) return null;
    total += minor;
  }
  return total;
}

export function formatMoney(value: string | number | null | undefined, currency: CurrencyCode = 'DOP'): string {
  const minor = decimalToMinor(value);
  if (minor === null) return 'Importe no disponible';
  const decimal = minorToDecimal(minor);
  const numeric = Number(decimal);
  if (!Number.isFinite(numeric)) return 'Importe no disponible';
  if (currency === 'DOP') {
    return `RD$ ${new Intl.NumberFormat('es-DO', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(numeric)}`;
  }
  return new Intl.NumberFormat('es-DO', {
    style: 'currency',
    currency,
  }).format(numeric);
}

export function formatChartMoney(value: string | number | null | undefined): number {
  const minor = decimalToMinor(value);
  return minor === null ? 0 : Number(minorToDecimal(minor));
}
