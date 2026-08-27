// website/pages/pricing/pricing.component.ts
import { Component } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterLink } from '@angular/router';
import { NavbarComponent } from '../../shared/navbar/navbar.component';
import { FooterComponent } from '../../shared/footer/footer.component';
import { Title, Meta } from '@angular/platform-browser';


@Component({
  selector: 'ws-pricing', standalone: true,
  imports: [CommonModule, RouterLink, NavbarComponent, FooterComponent],
  styleUrls: ['../../website.scss'],
  template: `
    <div class="website-shell">
      <div class="grain-overlay"></div>
      <ws-navbar></ws-navbar>

    <section style="padding:8rem 0 3rem; background:var(--c-bg-alt); border-bottom:1px solid var(--c-border-light); text-align:center;">
      <div class="ws-container">
        <span class="eyebrow"></span>
        <h1 class="display-lg" style="margin-top:0.5rem;">Flexible LMS Pricing for  <em>African Businesses</em></h1>
        <!-- Currency + Billing toggles -->
        <div style="display:flex; gap:2rem; justify-content:center; margin-top:2rem; flex-wrap:wrap; align-items:center;">
          <div class="toggle-group">
            <span [class.active]="billing === 'monthly'" (click)="billing='monthly'">Monthly</span>
            <div class="toggle-pill" [class.on]="billing === 'yearly'" (click)="billing = billing === 'monthly' ? 'yearly' : 'monthly'"><div class="toggle-dot"></div></div>
            <span [class.active]="billing === 'yearly'" (click)="billing='yearly'">Yearly <span class="save-tag">Save 20%</span></span>
          </div>
          <div class="toggle-group">
            <span [class.active]="currency === 'usd'" (click)="currency='usd'">USD ($)</span>
            <div class="toggle-pill" [class.on]="currency === 'ngn'" (click)="currency = currency === 'usd' ? 'ngn' : 'usd'"><div class="toggle-dot"></div></div>
            <span [class.active]="currency === 'ngn'" (click)="currency='ngn'">NGN (\u20A6)</span>
          </div>
        </div>
      </div>
    </section>

    <!-- Plan Cards -->
    <section class="ws-section" style="padding-top:3rem;">
      <h4 class="display-sm text-center pb-5 mb-5" style="margin-top:0.5rem;">Choose a Plan That Fits Your Needs. </h4>

      <div class="ws-container">
        <div class="plans-row">
          <div class="plan-card" *ngFor="let plan of plans" [class.popular]="plan.popular">
            <div class="popular-ribbon" *ngIf="plan.popular">Most Popular</div>
            <h3>{{ plan.name }}</h3>
            <p class="plan-tagline">{{ plan.tagline }}</p>
            <div class="plan-price-block">
              <span class="plan-amt" *ngIf="!plan.custom">{{ getPrice(plan) }}</span>
              <span class="plan-amt" *ngIf="plan.custom"></span>
              <span class="plan-period" *ngIf="!plan.custom">/mo</span>
            </div>
            <p class="plan-billed" *ngIf="!plan.custom && billing === 'yearly'">Billed {{ currency === 'usd' ? '$' : '\u20A6' }}{{ getYearlyTotal(plan) }} annually</p>
            <a [routerLink]="plan.custom ? '/contact' : '/onboarding/' + plan.id" class="btn-hero" [class.primary]="plan.popular" [class.outline]="!plan.popular" style="width:100%; justify-content:center; margin:1.5rem 0;">
              {{ plan.custom ? 'Contact Sales' : 'Sign Up' }}
            </a>
            <ul class="plan-features-list">
              <li *ngFor="let f of plan.features"><i class="bi bi-check-lg"></i> {{ f }}</li>
            </ul>
          </div>
        </div>
        <div class="container-fluid my-5 py-2">
      <div class="row justify-content-center">
        <div class="col-12 col-lg-10">

          <div class="card border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="row g-0 align-items-center">

              <!-- LEFT: Content -->
              <div class="col-md-6 p-5">

                <!-- Badge -->
                <span class="badge bg-black text-white mb-3 px-3 py-2 rounded-pill">
                  Central Mobile Learning App
                </span>

                <!-- Title -->
                <h3 class="fw-bold mb-2">Simple. Powerful. Cost-Effective</h3>
                <p class="text-muted mb-3">
                  Launch your own branded mobile learning app without the complexity or high cost of custom development. Integrates with Central LMS
                </p>

                <!-- Features -->
                <div class="row mt-5 mb-5">
                  <div class="col-12 col-md-6 mb-2">
                    <i class="bi bi-phone text-black me-2"></i> Android App (Play Store)
                  </div>
                  <div class="col-12 col-md-6 mb-2">
                    <i class="bi bi-apple text-black me-2"></i> iOS App (App Store)
                  </div>
                  <div class="col-12 col-md-6 mb-2">
                    <i class="bi bi-lightning-charge text-black me-2"></i> Fast Performance
                  </div>
                  <div class="col-12 col-md-6 mb-2">
                    <i class="bi bi-bell text-black me-2"></i> Push Notifications
                  </div>
                  <div class="col-12 col-md-6 mb-2">
                    <i class="bi bi-graph-up text-black me-2"></i> Progress Tracking
                  </div>
                  <div class="col-12 col-md-6 mb-2">
                    <i class="bi bi-shield-lock text-black me-2"></i> Secure API Layer
                  </div>
                </div>

              </div>

              <!-- RIGHT: Image -->
              <div class="col-md-6 bg-light text-center p-4">
                <img src="/assets/img/prcing.png"
                    alt="LMS pricing plans basic premium enterprise Africa"
                    class="img-fluid rounded-4 shadow-sm"
                    style="max-height: 750px; object-fit: contain;">
              </div>

            </div>
          </div>

        </div>
      </div>
</div>
        <!-- Comparison Table -->
        <div style="margin-top:4rem;">
          <h3 class="display-md" style="text-align:center; margin-bottom:2rem;">Feature Comparison</h3>
          <div class="comparison-table-wrap">
            <table class="comparison-table">
              <thead>
                <tr><th>Feature</th><th>Starter</th><th class="pop-col">Professional</th><th>Enterprise</th></tr>
              </thead>
              <tbody>
                <tr *ngFor="let row of comparison">
                  <td>{{ row.feature }}</td>
                  <td [innerHTML]="formatCell(row.starter)"></td>
                  <td class="pop-col" [innerHTML]="formatCell(row.pro)"></td>
                  <td [innerHTML]="formatCell(row.enterprise)"></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- FAQ -->
        <div style="max-width:700px; margin:4rem auto 0;">
          <h3 class="display-md" style="text-align:center; margin-bottom:2rem;">Frequently Asked Questions</h3>
          <div class="faq-item" *ngFor="let faq of faqs; let i = index" (click)="toggleFaq(i)">
            <div class="faq-q"><span>{{ faq.q }}</span><i class="bi" [class]="openFaq === i ? 'bi-dash' : 'bi-plus'"></i></div>
            <div class="faq-a" *ngIf="openFaq === i">{{ faq.a }}</div>
          </div>
        </div>
      </div>
    </section>

    <ws-footer></ws-footer></div>
  `,
  styles: [`
    .toggle-group { display: flex; align-items: center; gap: 0.5rem;
      span { font-size: 0.88rem; color: var(--c-text-muted); cursor: pointer; &.active { color: var(--c-text); font-weight: 600; } }
    }
    .toggle-pill { width: 42px; height: 24px; background: #DEE2E6; border-radius: 100px; position: relative; cursor: pointer; transition: background 0.3s; &.on { background: var(--c-primary); } }
    .toggle-dot { width: 18px; height: 18px; background: #fff; border-radius: 50%; position: absolute; top: 3px; left: 3px; transition: transform 0.3s; box-shadow: 0 1px 3px rgba(0,0,0,0.15); .on & { transform: translateX(18px); } }
    .save-tag { background: rgba(5,150,105,0.12); color: #059669; padding: 0.1rem 0.4rem; border-radius: 100px; font-size: 0.7rem; font-weight: 700; }

    .plans-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.5rem; align-items: start; }
    .plan-card { background: var(--c-surface); border: 1px solid var(--c-border-light); border-radius: var(--radius-xl); padding: 2.5rem; position: relative; transition: all 0.3s var(--ease-out);
      &:hover { transform: translateY(-4px); box-shadow: var(--shadow-lg); }
      &.popular { border-color: var(--c-primary); box-shadow: var(--shadow-lg); }
    }
    .popular-ribbon { position: absolute; top: -12px; left: 50%; transform: translateX(-50%); padding: 0.25rem 1rem; background: linear-gradient(135deg, var(--c-primary), var(--c-primary-light)); color: #fff; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; border-radius: 100px; white-space: nowrap; }
    .plan-card h3 { font-family: var(--font-display); font-size: 1.4rem; font-weight: 700; }
    .plan-tagline { font-size: 0.88rem; color: var(--c-text-muted); margin: 0.25rem 0 1.25rem; }
    .plan-amt { font-family: var(--font-display); font-size: 2.75rem; font-weight: 800; color: var(--c-text); }
    .plan-period { font-size: 1rem; color: var(--c-text-muted); }
    .plan-billed { font-size: 0.78rem; color: var(--c-text-muted); margin-top: 0.25rem; }
    .plan-features-list { list-style: none; padding: 0; margin: 0; li { display: flex; align-items: flex-start; gap: 0.625rem; padding: 0.5rem 0; font-size: 0.88rem; color: var(--c-text-secondary); border-bottom: 1px solid var(--c-border-light); i { color: var(--c-success); font-size: 0.9rem; margin-top: 2px; } &:last-child { border-bottom: none; } } }

    .comparison-table-wrap { overflow-x: auto; }
    .comparison-table { width: 100%; border-collapse: collapse; font-size: 0.88rem;
      th, td { padding: 0.75rem 1rem; text-align: center; border-bottom: 1px solid var(--c-border-light); }
      th { font-weight: 600; font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--c-text-muted); background: var(--c-bg-alt); }
      td:first-child, th:first-child { text-align: left; font-weight: 500; }
      .pop-col { background: rgba(10,79,42,0.03); }
    }
    .check-icon { color: var(--c-success); font-weight: 700; }
    .dash-icon { color: var(--c-border); }

    .faq-item { border: 1px solid var(--c-border-light); border-radius: var(--radius-md); margin-bottom: 0.75rem; cursor: pointer; transition: border-color 0.2s; &:hover { border-color: rgba(10,79,42,0.2); } }
    .faq-q { display: flex; justify-content: space-between; align-items: center; padding: 1rem 1.25rem; font-weight: 600; font-size: 0.95rem; i { color: var(--c-text-muted); } }
    .faq-a { padding: 0 1.25rem 1rem; font-size: 0.88rem; color: var(--c-text-secondary); line-height: 1.7; }

    @media (max-width: 767px) { .plans-row { grid-template-columns: 1fr; } }
  `]
})
export class PricingComponent {
  billing: 'monthly' | 'yearly' = 'monthly';
  currency: 'usd' | 'ngn' = 'usd';
  openFaq: number | null = null;
  private ngnRate = 1600; // USD to NGN

  constructor(
    private titleService: Title, 
    private metaService: Meta
  ) {}

  ngOnInit(): void {
      this.titleService.setTitle('LMS Pricing Africa | Affordable Learning Management System Nigeria');
      this.metaService.updateTag({ name: 'title', content: 'Central LMS Pricing Plans Africa | Corporate Training Software Cost' });
      this.metaService.updateTag({ name: 'description', content: 'Flexible LMS pricing plans for African businesses. Choose Basic, Premium, or Enterprise packages for scalable employee training solutions.' });
      this.metaService.updateTag({ name: 'keywords', content: 'LMS pricing Africa, cost of LMS Nigeria, eLearning pricing Africa, corporate training software pricing, affordable LMS Africa, training software cost Africa, enterprise LMS pricing' });
  }

  plans = [
    { id: 1, name: 'Starter', tagline: 'Perfect for small teams looking to launch training quickly and affordably.', custom: false, popular: false, usd: 99, features: ['Up to 100 users','Course creation & management','Basic reporting & analytics ','Content marketplace access ','SCORM 1.2','Assessments & Certifications','Standard support','Secure cloud hosting','Mobile-responsive learning','Built in AI teaching assistant'] },
    { id: 2, name: 'Professional', tagline: 'Ideal for organizations ready to scale training and improve learner engagement.', custom: false, popular: true, usd: 249, features: ['Everything in Starter, plus: ','Up to 500 users','Advanced reporting & insights (Up to 80 reports)','Learning paths & automation','Proctored assessments','Live training (ILT)','SCORM 1.2 and 2004','Custom branding','Single Sign-On (SSO)/SAML','Integration support (HR tools, APIs)','Priority support']},
    { id: 3, name: 'Enterprise', tagline: 'Designed for enterprises that need flexibility, scale, and full control.', custom: true, popular: false, usd: 0, features: ['Everything in Professional, plus: ','Unlimited users','Unlimited storage','Advanced analytics & dashboards (127+ reports)','Fully customizable platform','Multi-tenant architecture','API access & custom integrations','SCORM 1.2, 2004, AICC and xAPI','Compliance tracking & reporting','Dedicated success manager','SLA-backed support','Advanced security & compliance features','Advanced security & compliance features','Custom integrations & onboarding','On-premise deployment option'] },
  ]

  comparison = [
    { feature: 'Users', starter: 'Up to 100', pro: 'Up to 500', enterprise: 'Unlimited' },
    { feature: 'Courses', starter: '10', pro: 'Unlimited', enterprise: 'Unlimited' },
    { feature: 'Storage', starter: '1 GB', pro: '25 GB', enterprise: 'Unlimited' },
    { feature: 'Reports', starter: '10 basic', pro: '77+ advanced', enterprise: '127+ enterprise' },
    { feature: 'Live Training (ILT)', starter: '\u2014', pro: '\u2713', enterprise: '\u2713' },
    { feature: 'Proctored Assessments', starter: '\u2014', pro: '\u2713', enterprise: '\u2713' },
    { feature: 'Gamification', starter: '\u2713', pro: '\u2713', enterprise: '\u2713' },
    { feature: 'Messaging', starter: '\u2713', pro: '\u2713', enterprise: '\u2713' },
    { feature: 'Certificates', starter: '\u2713', pro: '\u2713', enterprise: '\u2713' },
    { feature: 'Custom Branding', starter: '\u2014', pro: '\u2713', enterprise: '\u2713' },
    { feature: 'API Access', starter: '\u2014', pro: '\u2713', enterprise: '\u2713' },
    { feature: 'SSO / SAML', starter: '\u2014', pro: '\u2713', enterprise: '\u2713' },
    { feature: 'Content Marketplace', starter: '\u2713', pro: '\u2713', enterprise: '\u2713' },
    { feature: 'Custom Report Builder', starter: '\u2014', pro: '\u2713', enterprise: '\u2713' },
    { feature: 'White-Label', starter: '\u2014', pro: '\u2014', enterprise: '\u2713' },
    { feature: 'On-Premise Option', starter: '\u2014', pro: '\u2014', enterprise: '\u2713' },
    { feature: 'SLA Guarantee', starter: '\u2014', pro: '\u2014', enterprise: '\u2713' },
    { feature: 'Dedicated Account Manager', starter: '\u2014', pro: '\u2014', enterprise: '\u2713' },
    { feature: 'Support', starter: 'Email (48h)', pro: 'Priority (12h)', enterprise: 'Dedicated (4h SLA)' },
  ];

  faqs = [
    { q: 'Can I switch plans at any time?', a: 'Yes, you can upgrade or downgrade your plan at any time. When upgrading, you\u2019ll be charged the pro-rated difference. When downgrading, the credit is applied to your next billing cycle.' },
    { q: 'What happens after the 14-day trial?', a: 'After your trial, you can choose a paid plan to continue. If you don\u2019t subscribe, your account is suspended (not deleted) and you can reactivate anytime within 90 days.' },
    { q: 'Do you offer discounts for NGOs or educational institutions?', a: 'Yes, we offer special pricing for non-profit organizations, educational institutions, and government bodies. Contact our sales team to discuss your needs.' },
    { q: 'Is there a setup fee?', a: 'No setup fees for Starter and Professional plans. Enterprise ( Self-hosted and Hosted Service ) plans includes a one-time licensing and implementation fee depending on the scope of customization required.' },
    { q: 'Can I pay in Nigerian Naira?', a: 'Yes! We accept payments in both USD and NGN. Toggle the currency switch above to see NGN pricing. Local bank transfers are supported for NGN payments.' },
  ];

  getPrice(plan: any): string {
    let discount = 0;
    if (this.billing === 'yearly') {
      if (plan.id === 1) {
        discount = 10;
      } else if (plan.id === 2) {
        discount = 15;
      }
    }
    const base = Math.round(plan.usd - discount);
    if (this.currency === 'ngn') {
      const ngn = base * this.ngnRate;
      return '\u20A6' + ngn.toLocaleString();
    }
    return '$' + base.toLocaleString();
  }

  getYearlyTotal(plan: any): string {
    let discount = 0;

    if (plan.id === 1) {
      discount = 10;
    } else if (plan.id === 2) {
      discount = 15;
    } else {
      discount = 0; // default case
    }

    const monthly = Math.round(plan.usd - discount);
    const annual = monthly * 12;

    if (this.currency === 'ngn') {
      return (annual * this.ngnRate).toLocaleString();
    }

    return annual.toLocaleString();
  }

  formatCell(val: string): string {
    if (val === '\u2713') return '<span class="check-icon">\u2713</span>';
    if (val === '\u2014') return '<span class="dash-icon">\u2014</span>';
    return val;
  }

  toggleFaq(i: number) { this.openFaq = this.openFaq === i ? null : i; }
}
