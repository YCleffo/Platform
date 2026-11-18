export class ApiError extends Error {
  readonly status: number;
  readonly code: string;
  readonly retryAfterSeconds: number | null;
  readonly meta?: Readonly<Record<string, unknown>>;

  constructor(
    status: number, code: string, message: string, retryAfterSeconds: number | null = null,
    meta?: Readonly<Record<string, unknown>>,
  ) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.retryAfterSeconds = retryAfterSeconds;
    this.meta = meta;
  }
}

export class NetworkError extends Error {
  constructor(message = 'The network request could not be completed.') {
    super(message);
    this.name = 'NetworkError';
  }
}

export class RequestAbortedError extends Error {
  constructor() {
    super('The request was cancelled.');
    this.name = 'RequestAbortedError';
  }
}

/** A newer operation replaced this one; its result must be ignored. */
export class SupersededOperationError extends Error {
  constructor() {
    super('The operation was superseded by a newer request.');
    this.name = 'SupersededOperationError';
  }
}

/** The server answered with a payload that does not match the documented contract. */
export class ApiResponseError extends Error {
  readonly path: string;

  constructor(path: string) {
    super('The server returned an unexpected response.');
    this.name = 'ApiResponseError';
    this.path = path;
  }
}
