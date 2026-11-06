import { describe, expect, it } from 'vitest';
import { qrcodegenTyped } from './qrcodegen';

describe('local QR generator', () => {
  it('encodes an otpauth URI into a square matrix with finder patterns', () => {
    const qr = qrcodegenTyped.QrCode.encodeText(
      'otpauth://totp/Platform:user@example.test?secret=JBSWY3DPEHPK3PXP&issuer=Platform',
      qrcodegenTyped.QrCode.Ecc.MEDIUM,
    );
    expect(qr.size).toBeGreaterThanOrEqual(21);
    for (let index = 0; index < qr.size; index += 1) {
      expect(qr.getModule(0, index)).toBeTypeOf('boolean');
      expect(qr.getModule(index, 0)).toBeTypeOf('boolean');
    }
    expect(qr.getModule(0, 0)).toBe(true);
    expect(qr.getModule(6, 6)).toBe(true);
    expect(qr.getModule(0, 6)).toBe(true);
    expect(qr.getModule(6, 0)).toBe(true);
  });
});
