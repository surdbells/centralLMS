/**
 * The unit of migration.
 *
 * Each module moves from the legacy backend to the new API independently, so
 * every endpoint declares which module it belongs to and the router resolves
 * the base URL from there. Migrating a module is then a one-line change in the
 * environment file — deployable, and revertible, on its own.
 *
 * These deliberately mirror the module list in `docs/migration/backend-port-status.md`.
 */
export enum ApiModule {
  /** Health, version — operational endpoints with no legacy equivalent. */
  Platform = 'platform',

  Auth = 'auth',
  Users = 'users',
  Dictionary = 'dictionary',
  Courses = 'courses',
  Enrollments = 'enrollments',
  Assessments = 'assessments',
  Proctoring = 'proctoring',
  Ilt = 'ilt',
  LearningPaths = 'learningPaths',
  Certificates = 'certificates',
  Media = 'media',
  Messaging = 'messaging',
  Skills = 'skills',
  Reports = 'reports',
  Dashboards = 'dashboards',
  TenantSettings = 'tenantSettings',
}
