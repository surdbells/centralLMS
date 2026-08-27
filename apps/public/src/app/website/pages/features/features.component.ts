// website/pages/features/features.component.ts
import { Component, AfterViewInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterLink } from '@angular/router';
import { NavbarComponent } from '../../shared/navbar/navbar.component';
import { FooterComponent } from '../../shared/footer/footer.component';
import { Title, Meta } from '@angular/platform-browser';


@Component({
  selector: 'ws-features', standalone: true,
  imports: [CommonModule, RouterLink, NavbarComponent, FooterComponent],
  styleUrls: ['../../website.scss'],
  template: `
    <div class="website-shell"><div class="grain-overlay"></div><ws-navbar></ws-navbar>

    <!-- Header -->
    <section style="padding:8rem 0 3rem; background:var(--c-bg-alt); border-bottom:1px solid var(--c-border-light);">
      <div class="ws-container" style="text-align:center;">
        <span class="eyebrow">Platform Features</span>
        <h1 class="display-lg" style="margin-top:0.5rem;">Powerful LMS Features for  <em>Modern Organizations.</em></h1>
        <p class="body-lg" style="max-width:600px; margin:0.75rem auto 2rem;">Every module works together to deliver the complete learning lifecycle; from content creation to compliance reporting.</p>
        <div style="display:flex; gap:1rem; justify-content:center; flex-wrap:wrap;">
          <a routerLink="/book-demo" class="btn-hero primary">Book a Demo <i class="bi bi-arrow-right ms-1"></i></a>
          <a routerLink="/pricing" class="btn-hero outline">View Pricing</a>
        </div>
      </div>
    </section>

    <!-- Module Grid -->
    <section class="ws-section" data-section="modules">
      <div class="ws-container">
        <div class="modules-grid">
          <div class="module-card glass-card reveal" *ngFor="let m of modules; let i = index"
               [class.visible]="sv['modules']" [class]="'stagger-' + (i % 8 + 1)"
               [class.expanded]="expandedModule === m.id" (click)="toggleModule(m.id)">
            <div class="module-header">
              <div class="module-icon" [style.background]="m.color + '10'" [style.color]="m.color">
                <i class="bi" [class]="m.icon"></i>
              </div>
              <div class="module-title-area">
                <h3>{{ m.title }}</h3>
                <span class="module-tag" [style.background]="m.color + '12'" [style.color]="m.color">{{ m.tag }}</span>
              </div>
              <!-- <i class="bi module-chevron" [class]="expandedModule === m.id ? 'bi-chevron-up' : 'bi-chevron-down'"></i> -->
            </div>
            <p class="module-desc">{{ m.desc }}</p>
<!--             <div class="module-details" *ngIf="expandedModule === m.id">
              <div class="module-feature" *ngFor="let f of m.features">
                <i class="bi bi-check-circle-fill" [style.color]="m.color"></i>
                <span>{{ f }}</span>
              </div>
              <div class="module-stats" *ngIf="m.stats">
                <div class="ms-item" *ngFor="let s of m.stats">
                  <strong [style.color]="m.color">{{ s.val }}</strong>
                  <span>{{ s.label }}</span>
                </div>
              </div>
            </div> -->
          </div>
        </div>
      </div>
    </section>

    <!-- CTA -->
    <section class="ws-section ws-section--dark" style="text-align:center; overflow:hidden;">
      <div class="mesh-orb mesh-orb--gold" style="width:500px;height:500px;bottom:-200px;right:-100px;opacity:0.3;"></div>
      <div class="ws-container" style="position:relative;z-index:1; max-width:750px;">
        <h2 class="display-md" style="color:#fff;">Everything You Need for Effective Training</h2>
        <p style="color:rgba(255,255,255,0.5); margin:1rem 0 2rem;">Book a personalized demo with our team.</p>
        <div style="display:flex; gap:1rem; justify-content:center; flex-wrap:wrap;">
          <a routerLink="/book-demo" class="btn-hero outline-light lg">Book a Demo</a>
        </div>
      </div>
    </section>

    <ws-footer></ws-footer></div>
  `,
  styles: [`
    .modules-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1.25rem; }
    .module-card { cursor: pointer; transition: all 0.3s var(--ease-out); }
    .module-card:hover { border-color: rgba(10,79,42,0.2); }
    .module-header { display: flex; align-items: center; gap: 1rem; }
    .module-icon { width: 48px; height: 48px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; flex-shrink: 0; }
    .module-title-area { flex: 1; }
    .module-title-area h3 { font-family: var(--font-display); font-size: 1.1rem; font-weight: 600; margin: 0 0 0.25rem; }
    .module-tag { display: inline-block; padding: 0.15rem 0.5rem; border-radius: var(--radius-full); font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; }
    .module-chevron { font-size: 1rem; color: var(--c-text-muted); transition: transform 0.3s; }
    .module-desc { font-size: 0.88rem; color: var(--c-text-secondary); line-height: 1.6; margin: 0.75rem 0 0; }
    .module-details { margin-top: 1.25rem; padding-top: 1.25rem; border-top: 1px solid var(--c-border-light); animation: slideDown 0.3s var(--ease-out); }
    .module-feature { display: flex; align-items: flex-start; gap: 0.625rem; padding: 0.375rem 0; font-size: 0.85rem; color: var(--c-text-secondary); i { font-size: 0.85rem; margin-top: 2px; flex-shrink: 0; } }
    .module-stats { display: flex; gap: 1.5rem; margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--c-border-light); }
    .ms-item { strong { display: block; font-family: var(--font-display); font-size: 1.5rem; font-weight: 700; } span { font-size: 0.72rem; color: var(--c-text-muted); text-transform: uppercase; letter-spacing: 0.03em; } }
    @keyframes slideDown { from { opacity: 0; max-height: 0; } to { opacity: 1; max-height: 600px; } }
    @media (max-width: 767px) { .modules-grid { grid-template-columns: 1fr; } }
  `]
})
export class FeaturesComponent implements AfterViewInit, OnDestroy {
  sv: Record<string, boolean> = {};
  expandedModule: string | null = null;
  private observer!: IntersectionObserver;
  constructor(
    private titleService: Title, 
    private metaService: Meta
  ) {}

  ngOnInit(): void {
      this.titleService.setTitle('LMS Features & Capabilities | Central LMS Africa');
      this.metaService.updateTag({ name: 'title', content: 'Best LMS Features Africa | Corporate Training Software Nigeria' });
      this.metaService.updateTag({ name: 'description', content: 'Discover powerful LMS features including gamification, SCORM support, onboarding tools, certifications, and collaborative learning for African organizations.' });
      this.metaService.updateTag({ name: 'keywords', content: 'LMS features Africa, eLearning tools Nigeria, corporate LMS features Africa, SCORM LMS Africa, gamification LMS Nigeria, training software features' });
  }

  toggleModule(id: string) { this.expandedModule = this.expandedModule === id ? null : id; }

  modules = [
    { id: 'lms', icon: 'bi-collection-play', title: 'Course Management', tag: 'Core LMS', color: '#0A4F2A',
      desc: 'Full lifecycle management for training content - creation, delivery, SCORM tracking, and enrollment.',
      features: ['SCORM 1.2/2004 with full CMI data tracking','xAPI (Tin Can) support','Video, audio, PDF, HTML, interactive content','Drag-and-drop module/lesson builder','Course duplication and version control','6 course types: Regular, SCORM, ILT, Blended, Webinar, External','Configurable pass marks, time limits, attempt tracking','Content interaction tracking (play, pause, complete, download)'],
      stats: [{ val: '6', label: 'Content Types' }, { val: '6', label: 'Course Types' }] },
    { id: 'paths', icon: 'bi-signpost-split', title: 'Learning Paths', tag: 'Guided Learning', color: '#6F42C1',
      desc: 'Structured learning journeys with prerequisites, milestones, and auto-assignment rules.',
      features: ['Sequential or flexible course ordering','Prerequisite chains and milestone markers','Auto-assignment by department, team, branch, role','Item-by-item progress tracking','Skill gap analysis and recommendations','Estimated completion time calculations'],
      stats: null },
    { id: 'ilt', icon: 'bi-camera-video', title: 'Live Training (ILT)', tag: '12 Tables', color: '#0891B2',
      desc: 'Complete instructor-led training with video conferencing, QR attendance, and vendor settlement.',
      features: ['Physical, virtual (Zoom/Teams/Meet), and hybrid sessions','QR code attendance (time-bound, enrollment-validated)','Virtual attendance auto-sync via webhooks','Capacity limits and automatic waitlists','Configurable reminders (24h, 1h, 15m)','Multi-channel notifications: email, SMS, WhatsApp, in-app','Vendor settlement: partial, full, overdraft modes','Instructor dashboard with session management'],
      stats: [{ val: '12', label: 'Database Tables' }, { val: '40+', label: 'API Actions' }] },
    { id: 'assess', icon: 'bi-shield-exclamation', title: 'Proctored Assessments', tag: '45 Tables', color: '#DC2626',
      desc: 'AI-powered assessment engine with webcam proctoring, trust scoring, and automated grading.',
      features: ['6 question types: single/multiple choice, true/false, short answer, essay, fill-blank','Question bank with tagging, difficulty levels, bulk import','AI webcam monitoring with periodic snapshots','Tab-switch detection, copy/paste prevention','Trust score calculation with 30+ violation types','Rubric-based manual grading for essays','Auto-generated certificates upon passing','Standalone or course/ILT-integrated modes'],
      stats: [{ val: '45', label: 'Database Tables' }, { val: '42K', label: 'Lines of Code' }] },
    { id: 'reports', icon: 'bi-graph-up-arrow', title: 'Reports & Analytics', tag: '127+ Reports', color: '#BE185D',
      desc: 'The most comprehensive analytics engine with admin, learner, and assessment reports.',
      features: ['77 admin reports across 23 categories','43 learner self-service reports across 9 categories','7 proctored assessment specialist reports','All-SVG chart rendering (7 chart types)','Custom report builder with drag-and-drop','Drill-down navigation from summary to detail','Export to CSV, XLSX, and PDF','Scheduled email delivery (daily, weekly, monthly)'],
      stats: [{ val: '127+', label: 'Reports' }, { val: '23', label: 'Categories' }] },
    { id: 'users', icon: 'bi-people', title: 'User Management & RBAC', tag: '36 Permissions', color: '#0A4F2A',
      desc: 'Complete user lifecycle with granular role-based access control and audit trails.',
      features: ['Full CRUD with search across 12 filter parameters','Bulk operations: activate, suspend, assign roles','CSV/XLSX import with 14-alias smart column mapping','8 system roles with 36 granular permissions','Custom role creation per tenant','Complete activity audit trail','Self-service: profile, password, avatar, consent','Account security: lockout, suspension, 2FA'],
      stats: [{ val: '8', label: 'System Roles' }, { val: '36', label: 'Permissions' }] },
    { id: 'gamification', icon: 'bi-trophy', title: 'Gamification & Certificates', tag: 'Engagement', color: '#C9A227',
      desc: 'Points, badges, leaderboards, streaks, and auto-generated certificates with QR verification.',
      features: ['Configurable points per action','Achievement, milestone, and streak badges','Global, department, and team leaderboards','Daily/weekly streak tracking with bonus multiplier','Progression levels (Bronze → Expert)','Auto-generated PDF certificates','Custom certificate templates','QR verification codes and validity tracking'],
      stats: null },
    { id: 'messaging', icon: 'bi-chat-dots', title: 'Messaging & Collaboration', tag: '17 Tables', color: '#7C3AED',
      desc: 'Full internal communication platform with DMs, groups, channels, and broadcasts.',
      features: ['Direct messages with read receipts','Group chats and public/private channels','Admin broadcasts with targeting','Emoji reactions and @mentions','Threaded replies and file sharing','Message bookmarks and drafts','User blocking and moderation tools','Configurable notification digests (hourly/daily/weekly)'],
      stats: [{ val: '17', label: 'Database Tables' }, { val: '50+', label: 'API Actions' }] },
    { id: 'settings', icon: 'bi-gear', title: 'Tenant Settings', tag: '8 Categories', color: '#0D9488',
      desc: 'Complete tenant configuration with branding, security policies, and integration management.',
      features: ['General: timezone, language, currency','Branding: logo, colors, login page, emails','Learning: pass scores, attempts, certificate rules','Security: password policy, lockout, 2FA, sessions','Storage: upload limits per file type','Notifications: per-event email toggles','Gamification: points, leaderboard config','Settings export/import with audit history'],
      stats: null },
    { id: 'onboarding', icon: 'bi-rocket-takeoff', title: 'Self-Service Onboarding', tag: 'SaaS', color: '#D97706',
      desc: 'Automated tenant provisioning with onboarding codes, content marketplace, and guided setup.',
      features: ['6-step onboarding wizard','Onboarding code gatekeeper with usage limits','Plan selection with monthly/yearly billing','Auto-provision: tenant, admin, departments, roles, categories','Content package marketplace installation','Subdomain generation and availability check','Transactional provisioning with rollback','Complete provisioning audit trail'],
      stats: null },
    { id: 'marketplace', icon: 'bi-box-seam', title: 'Content Marketplace', tag: '6 Packs', color: '#059669',
      desc: 'Pre-built course bundles with SCORM content, assessments, certificates, and learning paths.',
      features: ['Compliance Essentials (12 courses, $499)','HR & Onboarding (15 courses, $599)','IT & Cybersecurity (8 courses, $399)','Healthcare Pack (10 courses, $699)','Finance & Banking (10 courses, $599)','Professional Skills (12 courses, $449)','Each pack includes assessments and certificates','One-click installation during or after onboarding'],
      stats: null },
    { id: 'enrollment', icon: 'bi-person-plus', title: 'Course Enrollment', tag: 'Admin', color: '#4F46E5',
      desc: 'Bulk enrollment with department/team/branch targeting and preview before execution.',
      features: ['Enroll by department, team, branch, or individual','Bulk enrollment with preview','Mandatory/optional flags and deadline management','Waitlist management for capacity-limited courses','Enrollment notifications (email hooks)','Self-enrollment toggle per course','Enrollment history and unenroll with reason tracking'],
      stats: null },
  ];

  ngAfterViewInit() {
    this.observer = new IntersectionObserver(entries => {
      entries.forEach(e => { if (e.isIntersecting) { const id = e.target.getAttribute('data-section'); if (id) this.sv[id] = true; } });
    }, { threshold: 0.05 });
    setTimeout(() => document.querySelectorAll('[data-section]').forEach(el => this.observer.observe(el)), 100);
  }
  ngOnDestroy() { this.observer?.disconnect(); }
}
