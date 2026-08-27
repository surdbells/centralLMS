// website/pages/book-demo/book-demo.component.ts
import { Component } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { NavbarComponent } from '../../shared/navbar/navbar.component';
import { FooterComponent } from '../../shared/footer/footer.component';
import { Title, Meta } from '@angular/platform-browser';


@Component({
  selector: 'ws-book-demo', standalone: true,
  imports: [CommonModule, FormsModule, RouterLink, NavbarComponent, FooterComponent],
  styleUrls: ['../../website.scss'],
  template: `
    <div class="website-shell"><div class="grain-overlay"></div><ws-navbar></ws-navbar>

    <section class="demo-header">
      <div class="ws-container" style="text-align:center; max-width:700px;">
        <span class="eyebrow">Book a Demo</span>
        <h1 class="display-lg" style="margin-top:0.5rem;">See Central LMS <em>in Action</em></h1>
        <p class="body-lg" style="max-width:560px; margin:0.75rem auto 0;">
          Schedule a personalized demo with our team. We'll walk you through the platform and answer all your questions.
        </p>
      </div>
    </section>

    <section class="ws-section" style="padding-top:2rem;">
      <div class="ws-container">
        <div class="demo-layout">
          <div class="demo-form-card">
            <!-- ═══ SUCCESS STATE ═══ -->
            <div *ngIf="submitted" class="success-block">
              <div class="success-icon"><i class="bi bi-calendar-check-fill"></i></div>
              <h3>Demo Scheduled!</h3>
              <p>Thank you, {{ form.firstName }}. Our team will confirm your demo within 24 hours at <strong>{{ form.email }}</strong>.</p>
              <div class="success-summary">
                <div class="ss-row" *ngIf="form.company"><span>Company</span><strong>{{ form.company }}</strong></div>
                <div class="ss-row" *ngIf="selectedUseCases.length"><span>Use Cases</span><strong>{{ selectedUseCases.join(', ') }}</strong></div>
                <div class="ss-row" *ngIf="form.numLearners"><span>Learners</span><strong>{{ form.numLearners }}</strong></div>
                <div class="ss-row" *ngIf="selectedFeatures.length"><span>Features</span><strong>{{ selectedFeatures.join(', ') }}</strong></div>
                <div class="ss-row" *ngIf="form.preferredDate"><span>Preferred Date</span><strong>{{ form.preferredDate }}</strong></div>
              </div>
              <a routerLink="/" class="btn-hero primary" style="margin-top:1.5rem;">Back to Home</a>
            </div>

            <!-- ═══ FORM ═══ -->
            <form *ngIf="!submitted" (ngSubmit)="submitForm()">

              <!-- SECTION 1: Contact Information -->
              <div class="form-section-header">
                <div class="fsh-icon"><i class="bi bi-person-badge"></i></div>
                <div><h4>Contact Information</h4><p>Tell us about yourself and your organization.</p></div>
              </div>

              <div class="form-row">
                <div class="form-group"><label>First Name <span class="req">*</span></label><input type="text" [(ngModel)]="form.firstName" name="firstName" required placeholder="John" /></div>
                <div class="form-group"><label>Last Name <span class="req">*</span></label><input type="text" [(ngModel)]="form.lastName" name="lastName" required placeholder="Doe" /></div>
              </div>
              <div class="form-group"><label>Work Email <span class="req">*</span></label><input type="email" [(ngModel)]="form.email" name="email" required placeholder="john&#64;company.com" /></div>
              <div class="form-row">
                <div class="form-group"><label>Company Name <span class="req">*</span></label><input type="text" [(ngModel)]="form.company" name="company" required placeholder="Acme Corporation" /></div>
                <div class="form-group"><label>Job Title</label><input type="text" [(ngModel)]="form.jobTitle" name="jobTitle" placeholder="Head of L&D" /></div>
              </div>
              <div class="form-row">
                <div class="form-group"><label>Phone Number</label><input type="tel" [(ngModel)]="form.phone" name="phone" placeholder="+234 800 000 0000" /></div>
                <div class="form-group">
                  <label>Company Size <span class="req">*</span></label>
                  <select [(ngModel)]="form.companySize" name="companySize" required>
                    <option value="" disabled>Select size</option>
                    <option value="1-10">1–10 employees</option>
                    <option value="11-50">11–50 employees</option>
                    <option value="51-200">51–200 employees</option>
                    <option value="201-500">201–500 employees</option>
                    <option value="501-1000">501–1,000 employees</option>
                    <option value="1001+">1,000+ employees</option>
                  </select>
                </div>
              </div>
              <div class="form-row">
                <div class="form-group">
                  <label>Industry <span class="req">*</span></label>
                  <select [(ngModel)]="form.industry" name="industry" required>
                    <option value="" disabled>Select industry</option>
                    <option *ngFor="let ind of industries" [value]="ind">{{ ind }}</option>
                  </select>
                </div>
                <div class="form-group"><label>Preferred Demo Date</label><input type="date" [(ngModel)]="form.preferredDate" name="preferredDate" /></div>
              </div>
              <div class="form-group">
                <label>Preferred Time</label>
                <select [(ngModel)]="form.preferredTime" name="preferredTime">
                  <option value="">Select time (WAT)</option>
                  <option *ngFor="let t of timeSlots" [value]="t">{{ t }} WAT</option>
                </select>
              </div>

              <!-- SECTION 2: Use Case & Requirements -->
              <div class="form-divider"></div>
              <div class="form-section-header">
                <div class="fsh-icon" style="background:rgba(201,162,39,0.1); color:#C9A227;"><i class="bi bi-bullseye"></i></div>
                <div><h4>Use Case & Requirements</h4><p>Help us understand your training needs so we can tailor the demo.</p></div>
              </div>

              <div class="form-group">
                <label>Primary Use Case <span class="req">*</span> <span class="label-hint">(select all that apply)</span></label>
                <div class="checkbox-grid">
                  <label class="check-card" *ngFor="let uc of useCaseOptions" [class.selected]="selectedUseCases.includes(uc.value)" (click)="toggleUseCase(uc.value)">
                    <i class="bi" [class]="selectedUseCases.includes(uc.value) ? 'bi-check-square-fill' : 'bi-square'" [style.color]="selectedUseCases.includes(uc.value) ? 'var(--c-primary)' : 'var(--c-border)'"></i>
                    <span>{{ uc.label }}</span>
                  </label>
                </div>
              </div>

              <div class="form-row">
                <div class="form-group">
                  <label>Number of Learners <span class="req">*</span></label>
                  <select [(ngModel)]="form.numLearners" name="numLearners" required>
                    <option value="" disabled>Select range</option>
                    <option value="1-50">1–50</option>
                    <option value="51-200">51–200</option>
                    <option value="201-1000">201–1,000</option>
                    <option value="1000+">1,000+</option>
                  </select>
                </div>
                <div class="form-group">
                  <label>Current LMS (if any)</label>
                  <select [(ngModel)]="form.currentLms" name="currentLms">
                    <option value="">Select</option>
                    <option *ngFor="let lms of lmsOptions" [value]="lms">{{ lms }}</option>
                    <option value="Other">Other (specify below)</option>
                  </select>
                </div>
              </div>
              <div class="form-group" *ngIf="form.currentLms === 'Other'">
                <label>Please specify your current LMS</label>
                <input type="text" [(ngModel)]="form.currentLmsOther" name="currentLmsOther" placeholder="e.g., Cornerstone, Docebo..." />
              </div>

              <!-- SECTION 3: Feature Interests -->
              <div class="form-divider"></div>
              <div class="form-section-header">
                <div class="fsh-icon" style="background:rgba(2,132,199,0.1); color:#0284C7;"><i class="bi bi-gear"></i></div>
                <div><h4>Feature Interests</h4><p>Which capabilities are most important to you?</p></div>
              </div>

              <div class="feature-grid">
                <label class="feature-chip" *ngFor="let feat of featureOptions"
                       [class.selected]="selectedFeatures.includes(feat.value)"
                       (click)="toggleFeature(feat.value)">
                  <i class="bi" [class]="feat.icon" style="font-size:1rem;"></i>
                  <span>{{ feat.label }}</span>
                  <i class="bi check-mark" [class]="selectedFeatures.includes(feat.value) ? 'bi-check-circle-fill' : 'bi-circle'"
                     [style.color]="selectedFeatures.includes(feat.value) ? 'var(--c-primary)' : 'var(--c-border)'"></i>
                </label>
              </div>

              <!-- SECTION 4: Additional Info -->
              <div class="form-divider"></div>
              <div class="form-section-header">
                <div class="fsh-icon" style="background:rgba(124,58,237,0.1); color:#7C3AED;"><i class="bi bi-chat-left-text"></i></div>
                <div><h4>Additional Information</h4><p>Any extra context helps us prepare a more relevant demo.</p></div>
              </div>

              <div class="form-group">
                <label>What would you like to see in the demo?</label>
                <textarea [(ngModel)]="form.demoExpectations" name="demoExpectations" rows="3"
                          placeholder="e.g., Compliance workflow walkthrough, reporting dashboard, mobile app experience..."></textarea>
              </div>
              <div class="form-group">
                <label>Any specific challenges you're trying to solve?</label>
                <textarea [(ngModel)]="form.challenges" name="challenges" rows="3"
                          placeholder="e.g., Low training completion rates, manual compliance tracking, lack of analytics..."></textarea>
              </div>

              <!-- SUBMIT -->
              <button type="submit" class="btn-hero primary lg" style="width:100%; justify-content:center; margin-top:0.5rem;"
                      [disabled]="!form.firstName || !form.email || !form.company || !form.companySize || !form.industry || !selectedUseCases.length || !form.numLearners">
                Schedule My Demo <i class="bi bi-calendar-check ms-1"></i>
              </button>
              <p style="text-align:center; font-size:0.78rem; color:var(--c-text-muted); margin-top:1rem;">
                <i class="bi bi-lock me-1"></i>Your information is secure. We never share your data with third parties.
              </p>
            </form>
          </div>

          <!-- ═══ SIDEBAR ═══ -->
          <div class="demo-benefits">
            <h4 style="font-family:var(--font-display); font-size:1.15rem; font-weight:600; margin-bottom:1.5rem;">What to Expect</h4>
            <div class="demo-benefit" *ngFor="let b of benefits">
              <div class="db-icon"><i class="bi" [class]="b.icon"></i></div>
              <div><strong>{{ b.title }}</strong><p>{{ b.desc }}</p></div>
            </div>

            <div style="margin-top:2rem; padding:1.5rem; background:var(--c-primary-lighter); border-radius:var(--radius-lg);">
              <h5 style="font-size:0.95rem; font-weight:600; margin-bottom:0.5rem;">Prefer to explore on your own?</h5>
              <p style="font-size:0.85rem; color:var(--c-text-secondary); margin:0 0 1rem;">Start your free 14-day trial with no credit card required.</p>
              <a routerLink="/onboarding" class="btn-hero primary sm" style="width:100%; justify-content:center;">Sign Up Free</a>
            </div>

            <div style="margin-top:1.5rem; padding:1.25rem; border:1px solid var(--c-border-light); border-radius:var(--radius-md);">
              <p style="font-size:0.88rem; color:var(--c-text-secondary); margin:0 0 0.5rem;"><i class="bi bi-envelope me-2" style="color:var(--c-primary);"></i>sales&#64;kodekinnovations.com</p>
              <p style="font-size:0.88rem; color:var(--c-text-secondary); margin:0;"><i class="bi bi-telephone me-2" style="color:var(--c-primary);"></i>+234 XXX XXX XXXX</p>
            </div>
          </div>
        </div>
      </div>
    </section>

    <ws-footer></ws-footer></div>
  `,
  styles: [`
    .demo-header { padding: 8rem 0 3rem; background: var(--c-bg-alt); border-bottom: 1px solid var(--c-border-light); }
    .demo-layout { display: grid; grid-template-columns: 1.4fr 1fr; gap: 3rem; align-items: start; }
    .demo-form-card { background: var(--c-surface); border: 1px solid var(--c-border-light); border-radius: var(--radius-lg); padding: 2.5rem; }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
    .form-group { margin-bottom: 1.25rem;
      label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.375rem; color: var(--c-text); }
      input, select, textarea { width: 100%; padding: 0.625rem 0.875rem; border: 1px solid var(--c-border); border-radius: var(--radius-sm); font-size: 0.9rem; font-family: var(--font-body); outline: none; transition: border-color 0.2s; background: var(--c-bg);
        &:focus { border-color: var(--c-primary); box-shadow: 0 0 0 3px rgba(10,79,42,0.08); }
      }
      textarea { resize: vertical; min-height: 80px; }
    }
    .req { color: #DC2626; }
    .label-hint { font-weight: 400; color: var(--c-text-muted); font-size: 0.78rem; }

    /* Section headers */
    .form-section-header {
      display: flex; gap: 1rem; align-items: flex-start; margin-bottom: 1.5rem;
      h4 { font-family: var(--font-display); font-size: 1.1rem; font-weight: 600; margin: 0 0 0.2rem; }
      p { font-size: 0.82rem; color: var(--c-text-muted); margin: 0; line-height: 1.4; }
    }
    .fsh-icon {
      width: 42px; height: 42px; border-radius: var(--radius-md); flex-shrink: 0;
      background: var(--c-primary-lighter); color: var(--c-primary);
      display: flex; align-items: center; justify-content: center; font-size: 1.15rem;
    }
    .form-divider { height: 1px; background: var(--c-border-light); margin: 1.75rem 0 2rem; }

    /* Multi-select use case cards */
    .checkbox-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; }
    .check-card {
      display: flex; align-items: center; gap: 0.625rem; padding: 0.75rem 1rem;
      border: 1px solid var(--c-border-light); border-radius: var(--radius-sm);
      cursor: pointer; transition: all 0.2s; font-size: 0.88rem; color: var(--c-text-secondary);
      &:hover { border-color: rgba(10,79,42,0.25); background: var(--c-primary-lighter); }
      &.selected { border-color: var(--c-primary); background: rgba(10,79,42,0.04);
        span { color: var(--c-text); font-weight: 500; }
      }
      i { font-size: 1.05rem; flex-shrink: 0; }
    }

    /* Feature interest chips */
    .feature-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-bottom: 1.25rem; }
    .feature-chip {
      display: flex; align-items: center; gap: 0.625rem; padding: 0.7rem 1rem;
      border: 1px solid var(--c-border-light); border-radius: var(--radius-sm);
      cursor: pointer; transition: all 0.2s; font-size: 0.85rem; color: var(--c-text-secondary);
      &:hover { border-color: rgba(2,132,199,0.25); background: rgba(2,132,199,0.03); }
      &.selected { border-color: var(--c-primary); background: rgba(10,79,42,0.04);
        span { color: var(--c-text); font-weight: 500; }
      }
      span { flex: 1; }
      .check-mark { font-size: 0.95rem; }
    }

    /* Success state */
    .success-block { text-align: center; padding: 2rem 0; }
    .success-icon { width: 64px; height: 64px; border-radius: 50%; background: rgba(5,150,105,0.1); color: var(--c-success); display: inline-flex; align-items: center; justify-content: center; font-size: 1.75rem; margin-bottom: 1rem; }
    .success-block h3 { font-family: var(--font-display); font-size: 1.5rem; margin-bottom: 0.5rem; }
    .success-block p { color: var(--c-text-secondary); font-size: 0.95rem; }
    .success-summary {
      text-align: left; max-width: 400px; margin: 1.5rem auto 0;
      background: var(--c-bg-alt); border-radius: var(--radius-md); padding: 1.25rem;
    }
    .ss-row {
      display: flex; justify-content: space-between; gap: 1rem; padding: 0.5rem 0;
      border-bottom: 1px solid var(--c-border-light); font-size: 0.85rem;
      &:last-child { border-bottom: none; }
      span { color: var(--c-text-muted); }
      strong { color: var(--c-text); text-align: right; max-width: 220px; }
    }

    /* Sidebar */
    .demo-benefits {
      position: sticky; top: 5.5rem;
      max-height: calc(100vh - 6.5rem);
      overflow-y: auto;
      padding-right: 0.25rem;
      scrollbar-width: thin;
      scrollbar-color: var(--c-border) transparent;
      mask-image: linear-gradient(to bottom, black calc(100% - 2rem), transparent);
      -webkit-mask-image: linear-gradient(to bottom, black calc(100% - 2rem), transparent);
      &::-webkit-scrollbar { width: 4px; }
      &::-webkit-scrollbar-track { background: transparent; }
      &::-webkit-scrollbar-thumb { background: var(--c-border); border-radius: 4px; }
    }
    .demo-benefit { display: flex; gap: 1rem; margin-bottom: 1.5rem;
      strong { display: block; font-size: 0.92rem; margin-bottom: 0.2rem; }
      p { font-size: 0.82rem; color: var(--c-text-muted); line-height: 1.55; margin: 0; }
    }
    .db-icon { width: 40px; height: 40px; border-radius: var(--radius-md); background: var(--c-primary-lighter); color: var(--c-primary); display: flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0; }

    @media (max-width: 767px) {
      .demo-layout { grid-template-columns: 1fr; }
      .form-row, .checkbox-grid, .feature-grid { grid-template-columns: 1fr; }
      .demo-benefits { position: static; }
    }
  `]
})
export class BookDemoComponent {
  form: any = {
    firstName: '', lastName: '', email: '', company: '', jobTitle: '',
    phone: '', companySize: '', industry: '', preferredDate: '', preferredTime: '',
    numLearners: '', currentLms: '', currentLmsOther: '',
    demoExpectations: '', challenges: ''
  };
  submitted = false;
  selectedUseCases: string[] = [];
  selectedFeatures: string[] = [];
  constructor(
    private titleService: Title, 
    private metaService: Meta
  ) {}

  ngOnInit(): void {
      this.titleService.setTitle('Book a Demo | Central LMS Africa Learning Platform');
      this.metaService.updateTag({ name: 'title', content: 'Request LMS Demo Africa | Central LMS Demo Booking' });
      this.metaService.updateTag({ name: 'description', content: 'Book a personalized demo of Central LMS and discover how to improve employee training, onboarding and performance across Africa.' });
      this.metaService.updateTag({ name: 'keywords', content: 'book LMS demo Africa, LMS demo Nigeria, training software demo Africa' });
  }
  industries = [
    'Corporate', 'Healthcare', 'Manufacturing', 'Finance & Banking',
    'Retail & E-Commerce', 'Education', 'Professional Services',
    'Logistics & Transportation', 'Oil & Gas', 'Technology',
    'Hospitality', 'Government', 'Non-Profit', 'Other'
  ];

  timeSlots = ['9:00 AM', '10:00 AM', '11:00 AM', '12:00 PM', '1:00 PM', '2:00 PM', '3:00 PM', '4:00 PM'];

  useCaseOptions = [
    { value: 'Employee Training', label: 'Employee Training' },
    { value: 'Compliance Training', label: 'Compliance Training' },
    { value: 'Customer Training', label: 'Customer Training' },
    { value: 'Partner Training', label: 'Partner Training' },
    { value: 'Certification Programs', label: 'Certification Programs' },
  ];

  lmsOptions = ['None', 'Efront', 'Tangerine', 'Moodle', 'TalentLMS', 'SAP SuccessFactors'];

  featureOptions = [
    { value: 'SCORM Compliance', label: 'SCORM Compliance', icon: 'bi-file-earmark-zip' },
    { value: 'Mobile Learning', label: 'Mobile Learning', icon: 'bi-phone' },
    { value: 'Reporting & Analytics', label: 'Reporting & Analytics', icon: 'bi-graph-up-arrow' },
    { value: 'Gamification', label: 'Gamification', icon: 'bi-trophy' },
    { value: 'Multi-Tenant Setup', label: 'Multi-Tenant Setup', icon: 'bi-building' },
    { value: 'API / Integrations', label: 'API / Integrations', icon: 'bi-plug' },
    { value: 'White Labeling', label: 'White Labeling', icon: 'bi-palette' },
    { value: 'Proctored Assessments', label: 'Proctored Assessments', icon: 'bi-shield-exclamation' },
    { value: 'Live Training (ILT)', label: 'Live Training (ILT)', icon: 'bi-camera-video' },
    { value: 'Messaging & Collaboration', label: 'Messaging & Collaboration', icon: 'bi-chat-dots' },
    { value: 'Certificate Management', label: 'Certificate Management', icon: 'bi-award' },
    { value: 'eCommerce / Course Sales', label: 'eCommerce / Course Sales', icon: 'bi-cart3' },
  ];

  benefits = [
    { icon: 'bi-display', title: 'Live Platform Walkthrough', desc: 'See the full LMS in action with a personalized demo tailored to your use case.' },
    { icon: 'bi-chat-dots', title: 'Q&A with Our Experts', desc: 'Ask any questions about features, pricing, implementation, or integrations.' },
    { icon: 'bi-diagram-3', title: 'Custom Implementation Plan', desc: 'Get a tailored rollout roadmap for your organization\'s needs.' },
    { icon: 'bi-clock', title: 'Up to 60-Minute Session', desc: 'Quick, focused demo that respects your time. No pressure, no hard sell.' },
  ];

  toggleUseCase(val: string): void {
    const i = this.selectedUseCases.indexOf(val);
    i >= 0 ? this.selectedUseCases.splice(i, 1) : this.selectedUseCases.push(val);
  }

  toggleFeature(val: string): void {
    const i = this.selectedFeatures.indexOf(val);
    i >= 0 ? this.selectedFeatures.splice(i, 1) : this.selectedFeatures.push(val);
  }

  submitForm(): void {
    // TODO: POST to /api/book-demo.php with all form data + selectedUseCases + selectedFeatures
    this.submitted = true;
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
}