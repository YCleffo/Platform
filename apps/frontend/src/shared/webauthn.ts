import { ApiError, ApiResponseError, RequestAbortedError } from './api/errors';

export interface CredentialDescriptorJson {
  type: 'public-key';
  id: string;
  transports?: AuthenticatorTransport[];
}
export interface CreationOptionsJson {
  challenge: string;
  rp: PublicKeyCredentialRpEntity;
  user: { id: string; name: string; displayName: string };
  pubKeyCredParams: PublicKeyCredentialParameters[];
  timeout: number;
  authenticatorSelection: AuthenticatorSelectionCriteria;
  attestation: AttestationConveyancePreference;
  excludeCredentials: CredentialDescriptorJson[];
}
export interface RequestOptionsJson {
  challenge: string;
  rpId: string;
  timeout: number;
  userVerification: UserVerificationRequirement;
  allowCredentials?: CredentialDescriptorJson[];
}
const object = (v: unknown): v is Record<string, unknown> =>
  typeof v === 'object' && v !== null && !Array.isArray(v);
const encoded = (v: unknown): v is string =>
  typeof v === 'string' && v.length > 0 && /^[A-Za-z0-9_-]+$/.test(v) && v.length % 4 !== 1;
const descriptors = (v: unknown) =>
  Array.isArray(v) &&
  v.every(
    (d) =>
      object(d) &&
      d.type === 'public-key' &&
      encoded(d.id) &&
      (d.transports === undefined ||
        (Array.isArray(d.transports) && d.transports.every((t) => typeof t === 'string'))),
  );
export function isCreationOptions(v: unknown): v is CreationOptionsJson {
  return (
    object(v) &&
    encoded(v.challenge) &&
    object(v.rp) &&
    typeof v.rp.id === 'string' &&
    typeof v.rp.name === 'string' &&
    object(v.user) &&
    encoded(v.user.id) &&
    typeof v.user.name === 'string' &&
    typeof v.user.displayName === 'string' &&
    Array.isArray(v.pubKeyCredParams) &&
    v.pubKeyCredParams.length > 0 &&
    v.pubKeyCredParams.every(
      (p) => object(p) && p.type === 'public-key' && Number.isInteger(p.alg),
    ) &&
    typeof v.timeout === 'number' &&
    v.timeout > 0 &&
    object(v.authenticatorSelection) &&
    v.authenticatorSelection.userVerification === 'required' &&
    v.authenticatorSelection.residentKey === 'required' &&
    v.attestation === 'none' &&
    descriptors(v.excludeCredentials)
  );
}
export function isRequestOptions(v: unknown): v is RequestOptionsJson {
  return (
    object(v) &&
    encoded(v.challenge) &&
    typeof v.rpId === 'string' &&
    v.rpId.length > 0 &&
    typeof v.timeout === 'number' &&
    v.timeout > 0 &&
    v.userVerification === 'required' &&
    (v.allowCredentials === undefined || descriptors(v.allowCredentials))
  );
}
export function passkeyAvailability(): 'available' | 'insecure' | 'unsupported' {
  if (!window.isSecureContext) return 'insecure';
  return typeof PublicKeyCredential !== 'undefined' &&
    typeof navigator.credentials?.create === 'function' &&
    typeof navigator.credentials?.get === 'function'
    ? 'available'
    : 'unsupported';
}
export function decodeBase64Url(value: string): ArrayBuffer {
  if (!encoded(value)) throw new ApiResponseError('/auth/passkeys');
  const padded =
    value.replaceAll('-', '+').replaceAll('_', '/') + '='.repeat((4 - (value.length % 4)) % 4);
  return Uint8Array.from(atob(padded), (character) => character.charCodeAt(0)).buffer;
}
export function encodeBase64Url(value: ArrayBuffer): string {
  return btoa(Array.from(new Uint8Array(value), (byte) => String.fromCharCode(byte)).join(''))
    .replaceAll('+', '-')
    .replaceAll('/', '_')
    .replace(/=+$/, '');
}
function credentialJson(credential: Credential | null): Record<string, unknown> {
  if (credential === null || credential.type !== 'public-key')
    throw new ApiError(400, 'passkey_cancelled', 'No credential was selected.');
  const key = credential as PublicKeyCredential;
  const response = key.response;
  const common = {
    id: key.id,
    rawId: encodeBase64Url(key.rawId),
    type: key.type,
    authenticatorAttachment: key.authenticatorAttachment,
    clientExtensionResults: key.getClientExtensionResults(),
  };
  if ('attestationObject' in response) {
    const attestation = response as AuthenticatorAttestationResponse;
    return {
      ...common,
      response: {
        clientDataJSON: encodeBase64Url(response.clientDataJSON),
        attestationObject: encodeBase64Url(attestation.attestationObject),
        transports: attestation.getTransports?.() ?? [],
      },
    };
  }
  const assertion = response as AuthenticatorAssertionResponse;
  return {
    ...common,
    response: {
      clientDataJSON: encodeBase64Url(response.clientDataJSON),
      authenticatorData: encodeBase64Url(assertion.authenticatorData),
      signature: encodeBase64Url(assertion.signature),
      userHandle: assertion.userHandle === null ? null : encodeBase64Url(assertion.userHandle),
    },
  };
}
async function ceremony(
  action: () => Promise<Credential | null>,
  signal: AbortSignal,
): Promise<Record<string, unknown>> {
  if (signal.aborted) throw new RequestAbortedError();
  const availability = passkeyAvailability();
  if (availability !== 'available')
    throw new ApiError(400, `passkey_${availability}`, 'Passkeys are unavailable here.');
  try {
    const result = await action();
    if (signal.aborted) throw new RequestAbortedError();
    return credentialJson(result);
  } catch (error) {
    if (signal.aborted) throw new RequestAbortedError();
    if (error instanceof DOMException) {
      const code =
        error.name === 'NotAllowedError' || error.name === 'AbortError'
          ? 'passkey_cancelled'
          : error.name === 'InvalidStateError'
            ? 'passkey_duplicate'
            : error.name === 'SecurityError'
              ? 'passkey_origin'
              : 'passkey_unavailable';
      throw new ApiError(400, code, 'The browser could not complete the passkey operation.');
    }
    throw error;
  }
}
export function createPasskey(
  options: CreationOptionsJson,
  signal: AbortSignal,
): Promise<Record<string, unknown>> {
  const publicKey: PublicKeyCredentialCreationOptions = {
    ...options,
    challenge: decodeBase64Url(options.challenge),
    user: { ...options.user, id: decodeBase64Url(options.user.id) },
    excludeCredentials: options.excludeCredentials.map((d) => ({
      ...d,
      id: decodeBase64Url(d.id),
    })),
  };
  return ceremony(() => navigator.credentials.create({ publicKey, signal }), signal);
}
export function authenticatePasskey(
  options: RequestOptionsJson,
  signal: AbortSignal,
): Promise<Record<string, unknown>> {
  const publicKey: PublicKeyCredentialRequestOptions = {
    ...options,
    challenge: decodeBase64Url(options.challenge),
    allowCredentials: options.allowCredentials?.map((d) => ({ ...d, id: decodeBase64Url(d.id) })),
  };
  return ceremony(() => navigator.credentials.get({ publicKey, signal }), signal);
}
