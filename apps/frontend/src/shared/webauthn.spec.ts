import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { authenticatePasskey, createPasskey, decodeBase64Url, encodeBase64Url, isCreationOptions, isRequestOptions } from './webauthn';
const options = { challenge: 'AQID', rp: { id: 'localhost', name: 'Platform' }, user: { id: 'BAUG', name: 'account', displayName: 'Account' }, pubKeyCredParams: [{ type: 'public-key', alg: -7 }], timeout: 300000, authenticatorSelection: { residentKey: 'required', userVerification: 'required' }, attestation: 'none', excludeCredentials: [{ type: 'public-key', id: 'Bw' }] };
const request = { challenge: 'AQID', rpId: 'localhost', timeout: 300000, userVerification: 'required' as const };
beforeEach(() => { vi.stubGlobal('PublicKeyCredential', class {}); Object.defineProperty(window, 'isSecureContext', { value: true, configurable: true }); });
afterEach(() => vi.unstubAllGlobals());
describe('WebAuthn browser boundary', () => {
  it('roundtrips binary data with base64url and rejects malformed server challenges', () => {
    const bytes = Uint8Array.from([0, 255, 239, 128]).buffer;
    expect(encodeBase64Url(decodeBase64Url(encodeBase64Url(bytes)))).toBe(encodeBase64Url(bytes));
    expect(() => decodeBase64Url('a')).toThrow(); expect(isCreationOptions({ ...options, challenge: 'a' })).toBe(false);
    expect(isRequestOptions({ ...request, userVerification: 'discouraged' })).toBe(false);
  });
  it('converts challenge, user handle and exclusions to bytes and serializes an attestation', async () => {
    const create = vi.fn().mockResolvedValue({ type: 'public-key', id: 'AQID', rawId: decodeBase64Url('AQID'), authenticatorAttachment: 'platform', getClientExtensionResults: () => ({}), response: { clientDataJSON: decodeBase64Url('BAUG'), attestationObject: decodeBase64Url('Bw'), getTransports: () => ['internal'] } });
    vi.stubGlobal('navigator', { credentials: { create, get: vi.fn() } });
    expect(isCreationOptions(options)).toBe(true); if (!isCreationOptions(options)) throw new Error();
    const signal = new AbortController().signal; const credential = await createPasskey(options, signal);
    expect(create.mock.calls[0]![0].publicKey.challenge).toBeInstanceOf(ArrayBuffer);
    expect(new Uint8Array(create.mock.calls[0]![0].publicKey.user.id)).toEqual(new Uint8Array([4, 5, 6]));
    expect(create.mock.calls[0]![0].signal).toBe(signal);
    expect(credential.response).toEqual({ clientDataJSON: 'BAUG', attestationObject: 'Bw', transports: ['internal'] });
  });
  it('serializes assertions, preserves a null user handle and classifies browser cancellation', async () => {
    const get = vi.fn().mockResolvedValue({ type: 'public-key', id: 'AQID', rawId: decodeBase64Url('AQID'), authenticatorAttachment: 'platform', getClientExtensionResults: () => ({}), response: { clientDataJSON: decodeBase64Url('AQID'), authenticatorData: decodeBase64Url('BAUG'), signature: decodeBase64Url('Bw'), userHandle: null } });
    vi.stubGlobal('navigator', { credentials: { get, create: vi.fn() } });
    expect((await authenticatePasskey(request, new AbortController().signal)).response).toEqual({ clientDataJSON: 'AQID', authenticatorData: 'BAUG', signature: 'Bw', userHandle: null });
    get.mockRejectedValue(new DOMException('cancel', 'NotAllowedError'));
    await expect(authenticatePasskey(request, new AbortController().signal)).rejects.toMatchObject({ code: 'passkey_cancelled' });
  });
  it('never returns a credential from an aborted native prompt', async () => {
    const controller = new AbortController(); const get = vi.fn().mockImplementation(async () => { controller.abort(); return null; });
    vi.stubGlobal('navigator', { credentials: { get, create: vi.fn() } });
    await expect(authenticatePasskey(request, controller.signal)).rejects.toMatchObject({ name: 'RequestAbortedError' });
    controller.abort(); get.mockClear(); await expect(authenticatePasskey(request, controller.signal)).rejects.toBeInstanceOf(Error); expect(get).not.toHaveBeenCalled();
  });
});
