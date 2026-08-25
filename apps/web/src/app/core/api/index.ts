/**
 * Public surface of the API layer.
 *
 * Feature code imports from here and nowhere deeper, so the internals can be
 * reshaped during the migration without a repo-wide find-and-replace.
 */
export { ApiBackend } from './api-backend';
export { ApiModule } from './api-module';
export { ApiRouter, APP_ENVIRONMENT } from './api-routing';
export {
  Endpoints,
  type CrudPaths,
  type LookupKind,
  type LookupPaths,
} from './api-endpoints';
export { ApiClient, type QueryParams, type UploadProgress } from './api.client';
export {
  API_BACKEND,
  ApiError,
  codeForStatus,
  type LegacyEnvelope,
  type PageMeta,
  type Paged,
  type V1Failure,
  type V1Success,
} from './api-response';
export { authInterceptor } from './interceptors/auth.interceptor';
export { requestIdInterceptor } from './interceptors/request-id.interceptor';
