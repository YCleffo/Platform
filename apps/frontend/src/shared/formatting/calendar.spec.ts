import { describe, expect, it } from 'vitest';
import { addCalendarDays, calendarWeek, zonedDateTimeToIso } from './calendar';

describe('account calendar dates', () => {
  it('keeps Monday weeks and calendar arithmetic independent of the browser timezone', () => {
    expect(calendarWeek('2026-11-01')).toEqual(['2026-10-26','2026-10-27','2026-10-28','2026-10-29','2026-10-30','2026-10-31','2026-11-01']);
    expect(addCalendarDays('2026-12-31', 1)).toBe('2027-01-01');
  });
  it('converts the account timezone and rejects nonexistent or repeated DST wall times', () => {
    expect(zonedDateTimeToIso('2026-11-06T10:00', 'Asia/Yekaterinburg')).toBe('2026-11-06T05:00:00.000Z');
    expect(zonedDateTimeToIso('2026-03-08T03:30', 'America/New_York')).toBe('2026-03-08T07:30:00.000Z');
    expect(() => zonedDateTimeToIso('2026-03-08T02:30', 'America/New_York')).toThrow(RangeError);
    expect(() => zonedDateTimeToIso('2026-11-01T01:30', 'America/New_York')).toThrow(RangeError);
  });
});
