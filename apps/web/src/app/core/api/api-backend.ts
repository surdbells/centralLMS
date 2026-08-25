/**
 * Which backend is serving a request.
 *
 * The two differ in more than their URL — they disagree on how a caller
 * authenticates and on the shape of every response — so the client needs to
 * know which one it is talking to. See `ApiClient`.
 */
export enum ApiBackend {
  /**
   * The legacy PHP API.
   *
   * Credentials travel in the request *body* (`token`, `id`, `tenant_id`) and
   * responses are wrapped in `{ response_code, status, message, data }`.
   */
  Legacy = 'legacy',

  /**
   * The Slim/PostgreSQL API.
   *
   * Bearer token in the `Authorization` header; responses are `{ data, meta }`
   * or `{ error: { code, message, details } }`.
   */
  V1 = 'v1',
}
