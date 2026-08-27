// website/pages/contact/contact.component.ts
import { Component } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { NavbarComponent } from '../../shared/navbar/navbar.component';
import { FooterComponent } from '../../shared/footer/footer.component';
import {Title, Meta} from '@angular/platform-browser';


@Component({
  selector: 'ws-contact',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink, NavbarComponent, FooterComponent],
  styleUrls: ['../../website.scss'],
  template: `
    <div class="website-shell">
      <div class="grain-overlay"></div>
      <ws-navbar></ws-navbar>

      <!-- Header -->
      <section style="padding: 8rem 0 3rem; background: var(--c-bg-alt); border-bottom: 1px solid var(--c-border-light);">
        <div class="ws-container ws-container--narrow" style="text-align:center;">
          <span class="eyebrow">Get in Touch</span>
          <h1 class="display-lg" style="margin-top: 0.5rem;">Contact Central LMS</h1>
          <p class="body-lg" style="max-width: 520px; margin: 0.75rem auto 0;">
            Whether you're exploring our platform, need a demo, or want to discuss an enterprise deployment; we'd love to hear from you.
          </p>
        </div>
      </section>

      <!-- Main Content -->
      <section class="ws-section" style="padding-top: 3rem;">
        <div class="ws-container">
          <div class="contact-grid">
            <!-- Form -->
            <div class="contact-form-card">
              <h3 style="font-family: var(--font-display); font-size: 1.4rem; margin-bottom: 0.375rem;">Send Us a Message</h3>
              <p style="font-size: 0.88rem; color: var(--c-text-muted); margin-bottom: 1.5rem;">Fill out the form and our team will respond within 24 hours.</p>

              <div *ngIf="submitted" class="success-msg">
                <i class="bi bi-check-circle-fill"></i>
                <div>
                  <strong>Message sent successfully!</strong>
                  <p>We'll get back to you within 24 hours.</p>
                </div>
              </div>

              <form *ngIf="!submitted" (ngSubmit)="submitForm()">
                <div class="form-row">
                  <div class="form-group">
                    <label>First Name <span class="req">*</span></label>
                    <input type="text" [(ngModel)]="form.firstName" name="firstName" required placeholder="" />
                  </div>
                  <div class="form-group">
                    <label>Last Name <span class="req">*</span></label>
                    <input type="text" [(ngModel)]="form.lastName" name="lastName" required placeholder="" />
                  </div>
                </div>
                <div class="form-group">
                  <label>Work Email <span class="req">*</span></label>
                  <input type="email" [(ngModel)]="form.email" name="email" required placeholder="john@company.com" />
                </div>
                <div class="form-row">
                  <div class="form-group">
                    <label>Company</label>
                    <input type="text" [(ngModel)]="form.company" name="company" placeholder="Company name" />
                  </div>
                </div>
                <div class="form-group">
                <label for="phone">Phone</label>
                <div class="input-group">
                  <select
                      class="form-control"
                      [(ngModel)]="form.countryCode"
                      name="countryCode">

                      <option *ngFor="let c of countries" [value]="c.dial">
                        {{ c.name }} ({{ c.dial }})
                      </option>

                    </select>
                  <!-- Phone Number -->
                  <input
                    type="tel"
                    class="form-control"
                    [(ngModel)]="form.phone"
                    name="phone"
                    placeholder="800 000 0000"
                  />

                </div>
              </div>
                <div class="form-group">
                  <label>Inquiry Type</label>
                  <select [(ngModel)]="form.type" name="type">
                    <option value="">Select an option</option>
                    <option value="demo">Book a Demo</option>
                    <option value="sales">Sales Inquiry</option>
                    <option value="support">Technical Support</option>
                    <option value="partnership">Partnership</option>
                    <option value="other">Other</option>
                  </select>
                </div>
                <div class="form-group">
                  <label>Message <span class="req">*</span></label>
                  <textarea [(ngModel)]="form.message" name="message" required rows="4" placeholder="Tell us how we can help..."></textarea>
                </div>
                <button type="submit" class="btn-hero primary lg" style="width:100%; justify-content:center;" [disabled]="!form.firstName || !form.email || !form.message">
                  Send Message <i class="bi bi-send ms-1"></i>
                </button>
              </form>
            </div>

            <!-- Info Sidebar -->
            <div class="contact-info">
              <div class="info-block">
                <div class="info-icon"><i class="bi bi-envelope"></i></div>
                <div>
                  <h5>Email Us</h5>
                  <p>info&#64;centrallms.com</p>
                  <p>support&#64;centrallms.com</p>
                </div>
              </div>

              <div class="info-block">
                <div class="info-icon"><i class="bi bi-telephone"></i></div>
                <div>
                  <h5>Call Us</h5>
                  <p>+234 902 811 4748</p>
                  <p style="font-size:0.78rem; color:var(--c-text-muted);">Mon-Fri, 9am - 6pm WAT</p>
                </div>
              </div>

              <div class="info-block" style="border-bottom:none;">
                <div class="info-icon"><i class="bi bi-clock"></i></div>
                <div>
                  <h5>Response Time</h5>
                  <p>We typically respond within 24 hours</p>
                </div>
              </div>

              <!-- Quick Links -->
              <div style="margin-top: 2rem; padding: 1.5rem; background: var(--c-primary-lighter); border-radius: var(--radius-lg);">
                <h5 style="font-size: 0.95rem; font-weight: 600; margin-bottom: 0.75rem;">Quick Actions</h5>
                <a routerLink="/onboarding" style="display:flex; align-items:center; gap:0.5rem; padding:0.5rem 0; font-size:0.88rem; color:var(--c-primary); text-decoration:none; font-weight:500;">
                  <i class="bi bi-rocket-takeoff"></i> Sign Up
                </a>
                <a routerLink="/pricing" style="display:flex; align-items:center; gap:0.5rem; padding:0.5rem 0; font-size:0.88rem; color:var(--c-primary); text-decoration:none; font-weight:500;">
                  <i class="bi bi-tag"></i> View Pricing
                </a>
                <a routerLink="/features" style="display:flex; align-items:center; gap:0.5rem; padding:0.5rem 0; font-size:0.88rem; color:var(--c-primary); text-decoration:none; font-weight:500;">
                  <i class="bi bi-list-check"></i> Explore Features
                </a>
              </div>
            </div>
          </div>
        </div>
      </section>

      <ws-footer></ws-footer>
    </div>
  `,
  styles: [`
    .contact-grid { display: grid; grid-template-columns: 1.4fr 1fr; gap: 3rem; align-items: start; }
    .contact-form-card {
      background: var(--c-surface); border: 1px solid var(--c-border-light);
      border-radius: var(--radius-lg); padding: 2.5rem;
    }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
    .form-group { margin-bottom: 1.25rem; }
    .form-group label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.375rem; color: var(--c-text); }
    .req { color: var(--c-danger); }
    .form-group input, .form-group select, .form-group textarea {
      width: 100%; padding: 0.625rem 0.875rem; border: 1px solid var(--c-border);
      border-radius: var(--radius-sm); font-size: 0.9rem; font-family: var(--font-body);
      outline: none; transition: border-color 0.2s; background: var(--c-bg);
      &:focus { border-color: var(--c-primary); box-shadow: 0 0 0 3px rgba(10,79,42,0.08); }
    }
    .form-group textarea { resize: vertical; min-height: 100px; }
    .contact-info { position: sticky; top: 6rem; }
    .info-block {
      display: flex; gap: 1rem; padding: 1.25rem 0;
      border-bottom: 1px solid var(--c-border-light);
    }
    .info-icon {
      width: 42px; height: 42px; border-radius: var(--radius-md);
      background: var(--c-primary-lighter); color: var(--c-primary);
      display: flex; align-items: center; justify-content: center;
      font-size: 1.1rem; flex-shrink: 0;
    }
    .info-block h5 { font-size: 0.95rem; font-weight: 600; margin-bottom: 0.25rem; }
    .info-block p { font-size: 0.88rem; color: var(--c-text-secondary); margin: 0; line-height: 1.5; }
    .success-msg {
      display: flex; align-items: flex-start; gap: 1rem; padding: 1.5rem;
      background: rgba(5,150,105,0.06); border: 1px solid rgba(5,150,105,0.15);
      border-radius: var(--radius-md); color: var(--c-success);
      i { font-size: 1.5rem; flex-shrink: 0; margin-top: 2px; }
      strong { display: block; color: var(--c-text); margin-bottom: 0.25rem; }
      p { font-size: 0.88rem; color: var(--c-text-secondary); margin: 0; }
    }
    @media (max-width: 767px) {
      .contact-grid { grid-template-columns: 1fr; }
      .form-row { grid-template-columns: 1fr; }
      .contact-info { position: static; }
    }
  `]
})
export class ContactComponent {
  form = { firstName: '', lastName: '', email: '', company: '', phone: '',countryCode: '+234', type: '', message: '' };
  submitted = false;
  countries = [
  { name: 'Nigeria', code: 'NG', dial: '+234' },
  { name: 'Ghana', code: 'GH', dial: '+233' },
  { name: 'Kenya', code: 'KE', dial: '+254' },
  { name: 'South Africa', code: 'ZA', dial: '+27' },

  { name: 'Algeria', code: 'DZ', dial: '+213' },
  { name: 'Angola', code: 'AO', dial: '+244' },
  { name: 'Benin', code: 'BJ', dial: '+229' },
  { name: 'Botswana', code: 'BW', dial: '+267' },
  { name: 'Burkina Faso', code: 'BF', dial: '+226' },
  { name: 'Burundi', code: 'BI', dial: '+257' },
  { name: 'Cabo Verde', code: 'CV', dial: '+238' },
  { name: 'Cameroon', code: 'CM', dial: '+237' },
  { name: 'Central African Republic', code: 'CF', dial: '+236' },
  { name: 'Chad', code: 'TD', dial: '+235' },
  { name: 'Comoros', code: 'KM', dial: '+269' },
  { name: 'Congo (Brazzaville)', code: 'CG', dial: '+242' },
  { name: 'Congo (DRC)', code: 'CD', dial: '+243' },
  { name: 'Côte d’Ivoire', code: 'CI', dial: '+225' },
  { name: 'Djibouti', code: 'DJ', dial: '+253' },
  { name: 'Egypt', code: 'EG', dial: '+20' },
  { name: 'Equatorial Guinea', code: 'GQ', dial: '+240' },
  { name: 'Eritrea', code: 'ER', dial: '+291' },
  { name: 'Eswatini', code: 'SZ', dial: '+268' },
  { name: 'Ethiopia', code: 'ET', dial: '+251' },
  { name: 'Gabon', code: 'GA', dial: '+241' },
  { name: 'Gambia', code: 'GM', dial: '+220' },
  { name: 'Guinea', code: 'GN', dial: '+224' },
  { name: 'Guinea-Bissau', code: 'GW', dial: '+245' },
  { name: 'Lesotho', code: 'LS', dial: '+266' },
  { name: 'Liberia', code: 'LR', dial: '+231' },
  { name: 'Libya', code: 'LY', dial: '+218' },
  { name: 'Madagascar', code: 'MG', dial: '+261' },
  { name: 'Malawi', code: 'MW', dial: '+265' },
  { name: 'Mali', code: 'ML', dial: '+223' },
  { name: 'Mauritania', code: 'MR', dial: '+222' },
  { name: 'Mauritius', code: 'MU', dial: '+230' },
  { name: 'Morocco', code: 'MA', dial: '+212' },
  { name: 'Mozambique', code: 'MZ', dial: '+258' },
  { name: 'Namibia', code: 'NA', dial: '+264' },
  { name: 'Niger', code: 'NE', dial: '+227' },
  { name: 'Rwanda', code: 'RW', dial: '+250' },
  { name: 'Sao Tome & Principe', code: 'ST', dial: '+239' },
  { name: 'Senegal', code: 'SN', dial: '+221' },
  { name: 'Seychelles', code: 'SC', dial: '+248' },
  { name: 'Sierra Leone', code: 'SL', dial: '+232' },
  { name: 'Somalia', code: 'SO', dial: '+252' },
  { name: 'South Sudan', code: 'SS', dial: '+211' },
  { name: 'Sudan', code: 'SD', dial: '+249' },
  { name: 'Tanzania', code: 'TZ', dial: '+255' },
  { name: 'Togo', code: 'TG', dial: '+228' },
  { name: 'Tunisia', code: 'TN', dial: '+216' },
  { name: 'Uganda', code: 'UG', dial: '+256' },
  { name: 'Zambia', code: 'ZM', dial: '+260' },
  { name: 'Zimbabwe', code: 'ZW', dial: '+263' }
];

  constructor(
    private titleService: Title, 
    private metaService: Meta
  ) {}

  ngOnInit(): void {
      this.titleService.setTitle('Contact Central LMS | LMS Support Africa');
      this.metaService.updateTag({ name: 'title', content: 'Contact LMS Nigeria | Central LMS Customer Support Africa' });
      this.metaService.updateTag({ name: 'description', content: 'Get in touch with Central LMS for demos, support, and enterprise LMS solutions across Africa. Our team is available 24/7.' });
      this.metaService.updateTag({ name: 'keywords', content: 'contact LMS Africa, LMS support Nigeria, eLearning support Africa, LMS demo request Africa' });
  }


  submitForm() {
    // TODO: POST to /api/contact.php
    this.submitted = true;
  }
}
