// website/website.routes.ts
// Public-facing website pages - no auth guard
// These replace the single-page homepage approach

import { Routes } from '@angular/router';

export const websiteRoutes: Routes = [
  { path: '', loadComponent: () => import('./pages/home/home.component').then(m => m.HomeComponent), title: 'Central LMS \u2014 Enterprise Learning Management System' },
  { path: 'features', loadComponent: () => import('./pages/features/features.component').then(m => m.FeaturesComponent), title: 'Features \u2014 Central LMS' },
  { path: 'pricing', loadComponent: () => import('./pages/pricing/pricing.component').then(m => m.PricingComponent), title: 'Pricing \u2014 Central LMS' },
  { path: 'content-marketplace', loadComponent: () => import('./pages/marketplace/marketplace.component').then(m => m.MarketplaceComponent), title: 'Content Marketplace \u2014 Central LMS' },
  { path: 'blog', loadComponent: () => import('./pages/blog/blog.component').then(m => m.BlogComponent), title: 'Blog & Insights \u2014 Central LMS' },
  { path: 'blog/:slug', loadComponent: () => import('./pages/blog/blog-detail.component').then(m => m.BlogDetailComponent), title: 'Article \u2014 Central LMS' },
  { path: 'contact', loadComponent: () => import('./pages/contact/contact.component').then(m => m.ContactComponent), title: 'Contact Us \u2014 Central LMS' },
  { path: 'book-demo', loadComponent: () => import('./pages/book-demo/book-demo.component').then(m => m.BookDemoComponent), title: 'Book a Demo \u2014 Central LMS' },
  { path: 'onboarding', loadComponent: () => import('./pages/onboarding.component').then(m => m.OnboardingComponent), title: 'Get Started \u2014 Central LMS' },
  { path: 'onboarding/:planId', loadComponent: () => import('./pages/onboarding.component').then(m => m.OnboardingComponent), title: 'Get Started \u2014 Central LMS' },
];
// ─────────────────────────────────────────────
// INTEGRATION: Add to your app.routes.ts
//
//   import { websiteRoutes } from './website/website.routes';
//
//   export const routes: Routes = [
//     ...websiteRoutes,
//     { path: 'login', loadComponent: ... },
//     { path: 'register', loadComponent: ... },
//     { path: 'admin', loadChildren: ... },
//     { path: 'account', loadComponent: ... },
//     ...
//   ];
//
// IMPORTANT: Import website.scss in your global styles:
//   @import './app/website/website.scss';
//
// Or add to angular.json styles array:
//   "styles": ["src/app/website/website.scss"]
// ─────────────────────────────────────────────