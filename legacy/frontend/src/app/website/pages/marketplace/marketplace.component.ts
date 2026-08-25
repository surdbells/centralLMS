// website/pages/marketplace/marketplace.component.ts
import { Component } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterLink } from '@angular/router';
import { NavbarComponent } from '../../shared/navbar/navbar.component';
import { FooterComponent } from '../../shared/footer/footer.component';

@Component({
  selector: 'ws-marketplace', standalone: true,
  imports: [CommonModule, RouterLink, NavbarComponent, FooterComponent],
  styleUrls: ['../../website.scss'],
  template: `
    <div class="website-shell"><div class="grain-overlay"></div><ws-navbar></ws-navbar>

    <section style="padding:8rem 0 3rem; background:var(--c-bg-alt); border-bottom:1px solid var(--c-border-light); text-align:center;">
      <div class="ws-container">
        <span class="eyebrow">Content Marketplace</span>
        <h1 class="display-lg" style="margin-top:0.5rem;">Ready-Made <em>Course Packs</em></h1>
        <p class="body-lg" style="max-width:600px; margin:0.75rem auto 0;">
          Launch your training program instantly with professionally designed course bundles. Each includes SCORM content, assessments, certificates, and learning paths.
        </p>
        <div style="display:flex; gap:0.5rem; justify-content:center; margin-top:1.5rem;">
          <button class="curr-btn" [class.active]="currency === 'usd'" (click)="currency='usd'">USD ($)</button>
          <button class="curr-btn" [class.active]="currency === 'ngn'" (click)="currency='ngn'">NGN (\u20A6)</button>
        </div>
      </div>
    </section>

    <section class="ws-section" style="padding-top:3rem;">
      <div class="ws-container">
        <div class="packs-grid">
          <div class="pack-card" *ngFor="let pkg of packages" [class.popular]="pkg.popular">
            <div class="pack-popular" *ngIf="pkg.popular">Most Popular</div>
            <div class="pack-top">
              <div class="pack-icon" [style.background]="pkg.color + '12'" [style.color]="pkg.color">
                <i class="bi" [class]="pkg.icon"></i>
              </div>
              <h3>{{ pkg.name }}</h3>
              <p class="pack-desc">{{ pkg.desc }}</p>
            </div>

            <div class="pack-stats">
              <div class="ps"><i class="bi bi-book"></i><strong>{{ pkg.courses }}</strong> Courses</div>
              <div class="ps"><i class="bi bi-pencil-square"></i><strong>{{ pkg.assessments }}</strong> Assessments</div>
              <div class="ps"><i class="bi bi-award"></i><strong>{{ pkg.certificates }}</strong> Certificates</div>
            </div>

            <div class="pack-includes">
              <h5>Includes:</h5>
              <div class="pi" *ngFor="let item of pkg.includes"><i class="bi bi-check-circle-fill" [style.color]="pkg.color"></i>{{ item }}</div>
            </div>

            <div class="pack-tags">
              <span *ngFor="let tag of pkg.tags" [style.background]="pkg.color + '10'" [style.color]="pkg.color">{{ tag }}</span>
            </div>

            <div class="pack-bottom">
              <div class="pack-price">
                <span class="pack-amt">{{ getPrice(pkg) }}</span>
                <span class="pack-note">one-time</span>
              </div>
              <a routerLink="/onboarding" class="btn-hero primary sm" style="flex-shrink:0;">Get Started</a>
            </div>
          </div>
        </div>

        <!-- Custom pack CTA -->
        <div style="max-width:680px; margin:4rem auto 0; text-align:center; padding:3rem; background:var(--c-bg-alt); border-radius:var(--radius-xl); border:1px solid var(--c-border-light);">
          <h3 class="display-md">Need a Custom Pack?</h3>
          <p class="body-md" style="margin:0.75rem 0 1.5rem;">We can create bespoke content packages tailored to your industry, compliance requirements, and organizational goals.</p>
          <a routerLink="/contact" class="btn-hero primary">Contact Our Content Team <i class="bi bi-arrow-right ms-1"></i></a>
        </div>
      </div>
    </section>

    <ws-footer></ws-footer></div>
  `,
  styles: [`
    .curr-btn { padding: 0.4rem 1.25rem; border-radius: var(--radius-full); font-size: 0.85rem; font-weight: 600; background: var(--c-surface); border: 1px solid var(--c-border); color: var(--c-text-secondary); cursor: pointer; transition: all 0.2s; font-family: var(--font-body); &:hover { border-color: var(--c-primary); } &.active { background: var(--c-primary); border-color: var(--c-primary); color: #fff; } }
    .packs-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.5rem; }
    .pack-card { background: var(--c-surface); border: 1px solid var(--c-border-light); border-radius: var(--radius-xl); padding: 2rem; position: relative; display: flex; flex-direction: column; transition: all 0.4s var(--ease-out);
      &:hover { transform: translateY(-6px); box-shadow: var(--shadow-xl); }
      &.popular { border-color: var(--c-primary); box-shadow: var(--shadow-md); }
    }
    .pack-popular { position: absolute; top: -10px; right: 1.5rem; padding: 0.2rem 0.75rem; background: linear-gradient(135deg, var(--c-primary), var(--c-primary-light)); color: #fff; font-size: 0.68rem; font-weight: 700; text-transform: uppercase; border-radius: 100px; }
    .pack-top { margin-bottom: 1.25rem; }
    .pack-icon { width: 52px; height: 52px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; margin-bottom: 1rem; }
    .pack-card h3 { font-family: var(--font-display); font-size: 1.2rem; font-weight: 700; margin-bottom: 0.375rem; }
    .pack-desc { font-size: 0.85rem; color: var(--c-text-secondary); line-height: 1.6; margin: 0; }
    .pack-stats { display: flex; gap: 1rem; padding: 1rem 0; border-top: 1px solid var(--c-border-light); border-bottom: 1px solid var(--c-border-light); margin-bottom: 1rem; }
    .ps { font-size: 0.78rem; color: var(--c-text-muted); display: flex; align-items: center; gap: 0.375rem; strong { color: var(--c-text); } i { font-size: 0.85rem; } }
    .pack-includes { flex: 1; margin-bottom: 1rem; h5 { font-size: 0.78rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em; color: var(--c-text-muted); margin-bottom: 0.5rem; } }
    .pi { display: flex; align-items: flex-start; gap: 0.5rem; padding: 0.3rem 0; font-size: 0.82rem; color: var(--c-text-secondary); i { font-size: 0.78rem; margin-top: 2px; flex-shrink: 0; } }
    .pack-tags { display: flex; flex-wrap: wrap; gap: 0.375rem; margin-bottom: 1.25rem; span { padding: 0.15rem 0.5rem; border-radius: 100px; font-size: 0.68rem; font-weight: 600; } }
    .pack-bottom { display: flex; align-items: center; justify-content: space-between; padding-top: 1rem; border-top: 1px solid var(--c-border-light); }
    .pack-amt { font-family: var(--font-display); font-size: 1.75rem; font-weight: 800; color: var(--c-text); }
    .pack-note { font-size: 0.78rem; color: var(--c-text-muted); margin-left: 0.375rem; }
    @media (max-width: 991px) { .packs-grid { grid-template-columns: 1fr 1fr; } }
    @media (max-width: 767px) { .packs-grid { grid-template-columns: 1fr; } }
  `]
})
export class MarketplaceComponent {
  currency: 'usd' | 'ngn' = 'usd';
  private ngnRate = 1600;

  packages = [
    { name: 'Compliance Essentials', desc: 'Workplace safety, anti-harassment, data protection (GDPR), AML, and ethics training.', icon: 'bi-shield-check', color: '#DC2626', courses: 12, assessments: 24, certificates: 12, usd: 499, popular: true,
      tags: ['Safety', 'GDPR', 'AML', 'Ethics'],
      includes: ['Workplace Safety Fundamentals','Anti-Harassment Training','GDPR Data Protection','Anti-Money Laundering (AML)','Code of Ethics & Conduct','Information Security Basics','Environmental Compliance','Whistleblower Protection'] },
    { name: 'HR & Onboarding', desc: 'Employee onboarding, leadership fundamentals, communication, performance management.', icon: 'bi-people', color: '#6F42C1', courses: 15, assessments: 30, certificates: 10, usd: 599, popular: true,
      tags: ['Onboarding', 'Leadership', 'Communication', 'Performance'],
      includes: ['New Employee Welcome Program','Company Culture & Values','Leadership Fundamentals','Communication Skills','Performance Management','Team Building','Conflict Resolution','Time Management'] },
    { name: 'IT & Cybersecurity', desc: 'Cybersecurity awareness, phishing prevention, password management, incident response.', icon: 'bi-lock', color: '#0891B2', courses: 8, assessments: 16, certificates: 8, usd: 399, popular: false,
      tags: ['Security', 'Phishing', 'Data Protection'],
      includes: ['Cybersecurity Awareness','Phishing Prevention','Password Management','Data Handling & Classification','Incident Response','Social Engineering Defense','Mobile Security','Cloud Security Basics'] },
    { name: 'Healthcare Pack', desc: 'HIPAA compliance, patient safety, infection control, clinical protocols.', icon: 'bi-heart-pulse', color: '#059669', courses: 10, assessments: 20, certificates: 10, usd: 699, popular: false,
      tags: ['HIPAA', 'Patient Safety', 'Clinical', 'Infection Control'],
      includes: ['HIPAA Compliance','Patient Safety Protocols','Infection Prevention & Control','Medical Device Training','Clinical Documentation','Patient Rights & Privacy','Emergency Procedures','Medication Safety'] },
    { name: 'Finance & Banking', desc: 'Regulatory compliance, KYC/AML, risk management, financial reporting.', icon: 'bi-bank', color: '#D97706', courses: 10, assessments: 20, certificates: 8, usd: 599, popular: false,
      tags: ['KYC', 'AML', 'Risk', 'Regulatory', 'CBN'],
      includes: ['KYC/AML Compliance','Regulatory Reporting','Risk Management Fundamentals','Customer Due Diligence','Fraud Detection & Prevention','CBN Regulatory Requirements','Financial Crime Awareness','Operational Risk Management'] },
    { name: 'Professional Skills', desc: 'Time management, presentation skills, conflict resolution, emotional intelligence.', icon: 'bi-lightbulb', color: '#C9A227', courses: 12, assessments: 12, certificates: 6, usd: 449, popular: false,
      tags: ['Soft Skills', 'EQ', 'Productivity', 'Communication'],
      includes: ['Time Management & Productivity','Presentation Skills','Conflict Resolution','Emotional Intelligence','Critical Thinking','Effective Communication','Problem Solving','Negotiation Skills'] },
  ];

  getPrice(pkg: any): string {
    if (this.currency === 'ngn') return '\u20A6' + (pkg.usd * this.ngnRate).toLocaleString();
    return '$' + pkg.usd.toLocaleString();
  }
}