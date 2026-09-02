/**
 * Public surface of the API layer.
 *
 * Feature code imports from here and nowhere deeper.
 */
export { ApiRouter, APP_ENVIRONMENT } from './api-routing';
export {
  Endpoints,
  lookupPaths,
  type LookupKind,
  type LookupPaths,
} from './api-endpoints';
export { ApiClient, type QueryParams, type UploadProgress } from './api.client';
export { AuthImgDirective } from './auth-img.directive';
export {
  ApiError,
  codeForStatus,
  type ApiFailure,
  type ApiSuccess,
  type PageMeta,
  type Paged,
} from './api-response';
export { authInterceptor } from './interceptors/auth.interceptor';
export { requestIdInterceptor } from './interceptors/request-id.interceptor';
