// website/shared/navbar/navbar.component.ts
import { Component, HostListener } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterLink, RouterLinkActive } from '@angular/router';
import { environment } from '../../../../environments/environment';

@Component({
  selector: 'ws-navbar',
  standalone: true,
  imports: [CommonModule, RouterLink, RouterLinkActive],
  template: `
    <nav class="ws-nav" [class.scrolled]="scrolled">
      <div class="nav-inner">
        <a routerLink="/" class="nav-brand">
          <img src="/assets/img/logo.png" alt="Central LMS" />
        </a>
        <div class="nav-links">
          <a routerLink="/" routerLinkActive="active" [routerLinkActiveOptions]="{exact:true}" class="nav-link">Home</a>
          <a routerLink="/features" routerLinkActive="active" class="nav-link">Features</a>
          <a routerLink="/pricing" routerLinkActive="active" class="nav-link">Pricing</a>
<!--          <a routerLink="/content-marketplace" routerLinkActive="active" class="nav-link">Marketplace</a>-->
          <a routerLink="/blog" routerLinkActive="active" class="nav-link">Blog</a>
          <a routerLink="/contact" routerLinkActive="active" class="nav-link">Contact</a>
        </div>
        <div class="nav-actions">
          <a [href]="appUrl + '/login'" class="btn-hero sm outline">Sign In</a>
<!--          <a routerLink="/onboarding" class="btn-hero sm primary">Sign Up</a>-->
        </div>
        <button class="nav-toggle" (click)="mobileOpen = true"><i class="bi bi-list"></i></button>
      </div>
    </nav>
    <div class="mobile-backdrop" [class.open]="mobileOpen" (click)="mobileOpen = false"></div>
    <div class="mobile-menu" [class.open]="mobileOpen">
      <button class="mobile-close" (click)="mobileOpen = false"><i class="bi bi-x-lg"></i></button>
      <div style="padding-top:2.5rem;">
        <a routerLink="/" class="mobile-link" (click)="mobileOpen=false">Home</a>
        <a routerLink="/features" class="mobile-link" (click)="mobileOpen=false">Features</a>
        <a routerLink="/pricing" class="mobile-link" (click)="mobileOpen=false">Pricing</a>
<!--        <a routerLink="/content-marketplace" class="mobile-link" (click)="mobileOpen=false">Marketplace</a>-->
        <a routerLink="/blog" class="mobile-link" (click)="mobileOpen=false">Blog</a>
        <a routerLink="/contact" class="mobile-link" (click)="mobileOpen=false">Contact</a>
        <div style="padding-top:1.5rem; display:flex; flex-direction:column; gap:0.75rem;">
          <a [href]="appUrl + '/login'" class="btn-hero outline" style="justify-content:center;" (click)="mobileOpen=false">Sign In</a>
<!--          <a routerLink="/onboarding" class="btn-hero primary" style="justify-content:center;" (click)="mobileOpen=false">Sign Up</a>-->
        </div>
      </div>
    </div>
  `
})
export class NavbarComponent {
  /** The application, which is a separate deployment. */
  readonly appUrl = environment.appUrl;

  scrolled = false;
  mobileOpen = false;
  @HostListener('window:scroll') onScroll() { this.scrolled = window.scrollY > 60; }
}
