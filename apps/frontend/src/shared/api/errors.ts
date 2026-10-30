export class ApiError extends Error {
  readonly status: number;
  readonly code: string;
  readonly retryAfterSeconds: number | null;

  constructor(status: number, code: string, message: string, retryAfterSeconds: number | null = null) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.retryAfterSeconds = retryAfterSeconds;
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

/** The server answered with a payload that does not match the documented contract. */
export class ApiResponseError extends Error {
  readonly path: string;

  constructor(path: string) {
    super('The server returned an unexpected response.');
    this.name = 'ApiResponseError';
    this.path = path;
  }
}
