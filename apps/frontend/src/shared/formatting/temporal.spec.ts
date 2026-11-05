import { describe, expect, it } from 'vitest';
import { displayTemporal, normalizeTemporal, shiftDay, temporalStep, validDate, withinTemporal } from './temporal';
describe('temporal field contracts', () => {
  it('rejects impossible calendar dates instead of normalizing them into another month', () => {
    expect(validDate('2024-02-29')).toBe(true);
    expect(validDate('2026-02-29')).toBe(false);
    expect(normalizeTemporal('2026-04-31', 'date', 'en')).toBeNull();
    expect(validDate('0000-01-01')).toBe(false);
  });
  it('distinguishes local day/month ordering and accepts ISO keyboard input', () => {
    expect(normalizeTemporal('04.11.2026', 'date', 'ru')).toBe('2026-11-04');
    expect(normalizeTemporal('11/04/2026', 'date', 'en')).toBe('2026-11-04');
    expect(normalizeTemporal('2026-11-04', 'date', 'ru')).toBe('2026-11-04');
  });
  it('keeps wall time and seconds without an implicit UTC conversion', () => {
    expect(normalizeTemporal('04.11.2026 13:52:15', 'datetime-local', 'ru')).toBe('2026-11-04T13:52:15');
    expect(displayTemporal('2026-11-04T13:52', 'datetime-local', 'ru')).toBe('04.11.2026 13:52');
    expect(normalizeTemporal('24:00', 'time', 'en')).toBeNull();
    expect(normalizeTemporal('7:5', 'time', 'en')).toBe('07:05');
  });
  it('applies exact date and datetime bounds including minute precision', () => {
    expect(withinTemporal('2026-11-05', 'date', '2026-11-05', '2026-11-06')).toBe(true);
    expect(withinTemporal('2026-11-04', 'date', '2026-11-05')).toBe(false);
    expect(withinTemporal('2026-11-05T13:30:01', 'datetime-local', undefined, '2026-11-05T13:30')).toBe(false);
  });
  it('supports a time range that crosses midnight', () => {
    expect(withinTemporal('23:00', 'time', '22:00', '06:00')).toBe(true);
    expect(withinTemporal('05:59', 'time', '22:00', '06:00')).toBe(true);
    expect(withinTemporal('12:00', 'time', '22:00', '06:00')).toBe(false);
  });
  it('honors the interval and its min-value base', () => {
    expect(temporalStep('13:07', 'time', 300, '00:02')).toBe(true);
    expect(temporalStep('13:08', 'time', 300, '00:02')).toBe(false);
    expect(temporalStep('13:30:01', 'time', 60)).toBe(false);
    expect(temporalStep('13:30:01', 'time', 1)).toBe(true);
    expect(temporalStep('2026-11-05', 'date', 2, '2026-11-04')).toBe(false);
  });
  it('moves across month/year and leap boundaries without local DST arithmetic', () => {
    expect(shiftDay('2024-02-28', 1)).toBe('2024-02-29');
    expect(shiftDay('2026-12-31', 1)).toBe('2027-01-01');
    expect(shiftDay('2026-03-29', 1)).toBe('2026-03-30');
  });
});
