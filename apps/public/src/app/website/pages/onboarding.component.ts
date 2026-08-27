// homepage/pages/onboarding/onboarding.component.ts
// Self-service tenant onboarding - multipage standalone wizard

import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink, ActivatedRoute } from '@angular/router';
import { OnboardingService, Plan, ContentPackage, ProvisionRequest, ProvisionResponse } from '../services/onboarding.service';
import { Subject, debounceTime, switchMap } from 'rxjs';
import { environment } from '../../../environments/environment';

@Component({
  selector: 'app-onboarding',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink],
  template: `
    <div class="onboarding-page">
      <!-- Top bar -->
      <div class="onboarding-topbar">
        <a routerLink="/" class="brand">
          <img src="/assets/img/logo.png" alt="Central LMS" class="rounded-2 bg-white" />
        </a>
        <span class="topbar-title d-none d-md-inline">Create Your LMS</span>
        <a routerLink="/" class="btn btn-sm btn-outline-secondary">
          <i class="bi bi-arrow-left me-1"></i>Back to Home
        </a>
      </div>

      <!-- Wizard progress -->
      <div class="wizard-progress-bar" *ngIf="!provisioningSuccess">
        <div class="progress-track">
          <div class="progress-fill" [style.width.%]="((step - 1) / (totalSteps - 1)) * 100"></div>
        </div>
        <div class="steps-row">
          <div class="wiz-step" *ngFor="let s of steps; let i = index"
               [class.active]="step === i + 1" [class.done]="s.complete"
               (click)="goToStep(i + 1)">
            <div class="wiz-circle">
              <i class="bi" [class]="s.complete ? 'bi-check' : s.icon"></i>
            </div>
            <span class="wiz-label d-none d-md-block">{{ s.label }}</span>
          </div>
        </div>
      </div>

      <!-- Error banner -->
      <div class="container" *ngIf="error">
        <div class="alert alert-danger mt-3 d-flex align-items-center gap-2">
          <i class="bi bi-exclamation-triangle-fill"></i>{{ error }}
          <button class="btn-close ms-auto" (click)="error = ''"></button>
        </div>
      </div>

      <!-- ═══ STEP 1: Onboarding Code ═══ -->
      <div class="wizard-content" *ngIf="step === 1">
        <div class="wizard-card narrow">
          <div class="text-center mb-4">
            <div class="step-icon"><i class="bi bi-key"></i></div>
            <h2>Enter Your Onboarding Code</h2>
            <p class="text-muted">To create a new LMS workspace, please enter the onboarding code provided to you.</p>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Onboarding Code <span class="text-danger">*</span></label>
            <input type="text" class="form-control form-control-lg text-center text-uppercase tracking-wide"
                   [(ngModel)]="onboardingCode" placeholder="e.g. WELCOME2026"
                   maxlength="50" (keyup.enter)="validateCode()"
                   [class.is-valid]="codeValidated && codeValid"
                   [class.is-invalid]="codeValidated && !codeValid">
            <div class="valid-feedback" *ngIf="codeValidated && codeValid">
              <i class="bi bi-check-circle me-1"></i>Code verified successfully!
            </div>
            <div class="invalid-feedback" *ngIf="codeValidated && !codeValid">
              {{ codeError }}
            </div>
          </div>
          <div class="form-text text-center mb-3">
            Don't have a code? <a href="javascript:void(0)" class="text-primary fw-semibold">Contact our sales team</a>
          </div>
          <button class="btn btn-primary btn-lg w-100" (click)="validateCode()" [disabled]="validatingCode || !onboardingCode.trim()">
            <span *ngIf="validatingCode" class="spinner-border spinner-border-sm me-2"></span>
            {{ validatingCode ? 'Verifying...' : 'Verify Code' }}
          </button>

          <hr>
            <h6>Or, start without a code</h6>
           <button class="btn btn-primary btn-lg w-100" (click)="step = 2">
            CONTINUE AS GUEST
          </button>
        </div>
      </div>

      <!-- ═══ STEP 2: Choose Plan ═══ -->
      <div class="wizard-content" *ngIf="step === 2">
        <div class="wizard-card wide">
          <div class="text-center mb-4">
            <h2>Choose Your Plan</h2>
            <p class="text-muted">All plans include a 14-day free trial. No credit card required.</p>
            <div class="billing-toggle mt-3">
              <span [class.active]="billingCycle === 'monthly'" (click)="billingCycle = 'monthly'">Monthly</span>
              <div class="toggle-pill" [class.yearly]="billingCycle === 'yearly'"
                   (click)="billingCycle = billingCycle === 'monthly' ? 'yearly' : 'monthly'">
                <div class="toggle-dot"></div>
              </div>
              <span [class.active]="billingCycle === 'yearly'" (click)="billingCycle = 'yearly'">
                Yearly <span class="save-tag">Save 20%</span>
              </span>
            </div>
          </div>

          <div class="plans-grid" *ngIf="plans.length; else plansLoading">
            <div class="plan-card" *ngFor="let plan of plans"
                 [class.selected]="selectedPlanId === plan.plan_id"
                 [class.popular]="plan.name === 'Professional'"
                 (click)="selectPlan(plan.plan_id)">
              <div class="plan-badge" *ngIf="plan.name === 'Professional'">Most Popular</div>
              <div class="plan-check">
                <i class="bi" [class]="selectedPlanId === plan.plan_id ? 'bi-check-circle-fill' : 'bi-circle'"></i>
              </div>
              <h4>{{ plan.name }}</h4>
              <div class="plan-price-row">
                <span class="plan-price" *ngIf="plan.price > 0">
                  {{ billingCycle === 'yearly' ? '₦' + (plan.price * 0.8 | number:'1.0-0') : '₦' + (plan.price | number:'1.0-0') }}
                </span>
                <span class="plan-price" *ngIf="plan.price === 0">Custom</span>
                <span class="plan-per" *ngIf="plan.price > 0">/mo</span>
              </div>
              <div class="plan-limits">
                <span><i class="bi bi-people me-1"></i>{{ plan.users_limit >= 999999 ? 'Unlimited' : (plan.users_limit | number) }} users</span>
                <span><i class="bi bi-hdd me-1"></i>{{ plan.storage_limit_mb >= 999999 ? 'Unlimited' : (plan.storage_limit_mb / 1024 | number:'1.0-0') + ' GB' }} storage</span>
              </div>
            </div>
          </div>
          <ng-template #plansLoading>
            <div class="text-center py-5"><div class="spinner-border text-primary"></div></div>
          </ng-template>
        </div>
      </div>

      <!-- ═══ STEP 3: Company Info ═══ -->
      <div class="wizard-content" *ngIf="step === 3">
        <div class="wizard-card">
          <div class="text-center mb-4">
            <h2>About Your Organization</h2>
            <p class="text-muted">This information sets up your LMS workspace and admin account.</p>
          </div>
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-semibold">Company Name <span class="text-danger">*</span></label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-building"></i></span>
                <input type="text" class="form-control" [(ngModel)]="form.company_name" placeholder="Acme Corporation">
              </div>
              <div class="form-text" *ngIf="form.company_name.trim()">
                Your LMS: <strong class="text-primary">{{ generateSubdomain() }}.lmsplatform.com</strong>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">First Name <span class="text-danger">*</span></label>
              <input type="text" class="form-control" [(ngModel)]="form.admin_first_name" placeholder="John">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Last Name <span class="text-danger">*</span></label>
              <input type="text" class="form-control" [(ngModel)]="form.admin_last_name" placeholder="Doe">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Admin Email <span class="text-danger">*</span></label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                <input type="email" class="form-control" [(ngModel)]="form.admin_email"
                       (blur)="onEmailBlur()" placeholder="admin@acme.com"
                       [class.is-invalid]="emailTouched && !emailAvailable">
              </div>
              <div class="invalid-feedback" *ngIf="emailTouched && !emailAvailable">This email is already registered.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Password <span class="text-danger">*</span></label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-lock"></i></span>
                <input [type]="showPw ? 'text' : 'password'" class="form-control"
                       [(ngModel)]="form.password" (input)="calcPwStrength()" placeholder="Min 8 characters">
                <button class="btn btn-outline-secondary" type="button" (click)="showPw = !showPw">
                  <i class="bi" [class]="showPw ? 'bi-eye-slash' : 'bi-eye'"></i>
                </button>
              </div>
              <div class="pw-strength mt-1" *ngIf="form.password">
                <div class="pw-bars"><div class="pw-bar" *ngFor="let b of [1,2,3,4]" [class.on]="pwStrength >= b" [style.background]="pwStrength >= b ? pwColor : ''"></div></div>
                <small [style.color]="pwColor">{{ pwLabel }}</small>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Confirm Password <span class="text-danger">*</span></label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                <input [type]="showPw ? 'text' : 'password'" class="form-control"
                       [(ngModel)]="form.confirm_password" placeholder="Re-enter password"
                       [class.is-invalid]="form.confirm_password && form.password !== form.confirm_password">
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Company Size <span class="text-danger">*</span></label>
              <select class="form-select" [(ngModel)]="form.company_size">
                <option value="" disabled>Select size</option>
                <option *ngFor="let s of companySizes" [value]="s.value">{{ s.label }}</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Industry <span class="text-danger">*</span></label>
              <select class="form-select" [(ngModel)]="form.industry">
                <option value="" disabled>Select industry</option>
                <option *ngFor="let i of industries" [value]="i">{{ i }}</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Phone <span class="text-muted fw-normal">(optional)</span></label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-phone"></i></span>
                <input type="tel" class="form-control" [(ngModel)]="form.phone" placeholder="+234 800 000 0000">
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ═══ STEP 4: Content Packages ═══ -->
      <div class="wizard-content" *ngIf="step === 4">
        <div class="wizard-card wide">
          <div class="text-center mb-4">
            <h2>Choose Content Packages</h2>
            <p class="text-muted">Optional - Select course bundles to launch your training instantly, or start blank.</p>
          </div>
          <div class="content-list" *ngIf="contentPackages.length; else pkgLoading">
            <div class="content-row" *ngFor="let pkg of contentPackages"
                 [class.selected]="isSelected(pkg.slug)" (click)="togglePkg(pkg.slug)">
              <div class="content-check">
                <i class="bi" [class]="isSelected(pkg.slug) ? 'bi-check-square-fill' : 'bi-square'"></i>
              </div>
              <div class="content-icon" [style.background]="pkg.color + '12'" [style.color]="pkg.color">
                <i class="bi" [class]="pkg.icon"></i>
              </div>
              <div class="content-info">
                <h5>{{ pkg.name }} <span class="pop-badge" *ngIf="pkg.is_popular">Popular</span></h5>
                <p>{{ pkg.description }}</p>
                <div class="content-stats">
                  <span>{{ pkg.courses_count }} courses</span>
                  <span>{{ pkg.assessments_count }} assessments</span>
                  <span>{{ pkg.certificates_count }} certs</span>
                </div>
              </div>
              <div class="content-price">\${{ pkg.price | number:'1.0-0' }}</div>
            </div>
          </div>
          <ng-template #pkgLoading>
            <div class="text-center py-4"><div class="spinner-border text-primary"></div></div>
          </ng-template>
          <div class="content-total mt-3" *ngIf="selectedPkgs.length">
            {{ selectedPkgs.length }} package(s) - Total: <strong>{{ getPackagesTotal() }}</strong>
          </div>
        </div>
      </div>

      <!-- ═══ STEP 5: Review ═══ -->
      <div class="wizard-content" *ngIf="step === 5">
        <div class="wizard-card">
          <div class="text-center mb-4">
            <h2>Review & Confirm</h2>
            <p class="text-muted">Everything look good? Click "Create My LMS" to provision your workspace.</p>
          </div>
          <div class="review-grid">
            <div class="review-block">
              <h6><i class="bi bi-tag me-2"></i>Plan</h6>
              <div *ngIf="getSelectedPlan() as p">
                <strong>{{ p.name }}</strong> - {{ p.price > 0 ? ('₦' + (billingCycle === 'yearly' ? (p.price * 0.8 | number:'1.0-0') : (p.price | number:'1.0-0')) + '/mo') : 'Custom' }}
              </div>
            </div>
            <div class="review-block">
              <h6><i class="bi bi-building me-2"></i>Organization</h6>
              <strong>{{ form.company_name }}</strong>
              <div class="small text-muted">{{ form.company_size }} &bull; {{ form.industry }}</div>
              <div class="small text-muted mt-1">URL: <code>{{ generateSubdomain() }}.lmsplatform.com</code></div>
            </div>
            <div class="review-block">
              <h6><i class="bi bi-person-badge me-2"></i>Admin</h6>
              <strong>{{ form.admin_first_name }} {{ form.admin_last_name }}</strong>
              <div class="small text-muted">{{ form.admin_email }}</div>
            </div>
            <div class="review-block">
              <h6><i class="bi bi-collection me-2"></i>Content</h6>
              <div *ngIf="selectedPkgs.length; else noPkgs">
                <span class="badge bg-light text-dark border me-1 mb-1"
                      *ngFor="let slug of selectedPkgs">{{ getPkgName(slug) }}</span>
                <div class="small text-muted mt-1">Total: {{ getPackagesTotal() }}</div>
              </div>
              <ng-template #noPkgs><span class="text-muted">Starting with blank LMS</span></ng-template>
            </div>
          </div>
          <div class="review-info mt-3">
            <i class="bi bi-info-circle me-2"></i>
            After setup you'll get a guided wizard to upload your logo, add employees, and create your first course.
          </div>
        </div>
      </div>

      <!-- ═══ STEP 6: Provisioning ═══ -->
      <div class="wizard-content" *ngIf="step === 6">
        <div class="wizard-card narrow text-center">
          <!-- In progress -->
          <div *ngIf="provisioning && !provisioningSuccess">
            <div class="prov-spinner mb-4"><div class="prov-ring"></div><i class="bi bi-gear-fill prov-icon"></i></div>
            <h2>Setting Up Your LMS</h2>
            <p class="text-muted mb-4">Please wait - this takes about 15 seconds.</p>
            <div class="prov-progress mb-2">
              <div class="prov-bar" [style.width.%]="provProgress"></div>
            </div>
            <div class="small text-primary fw-semibold mb-3">{{ provProgress }}%</div>
            <div class="prov-log text-start mx-auto" style="max-width:380px">
              <div class="prov-msg" *ngFor="let m of provMessages; let last = last" [class.current]="last">
                <i class="bi" [class]="last ? 'bi-arrow-right-circle-fill text-primary' : 'bi-check-circle-fill text-success'"></i>{{ m }}
              </div>
            </div>
          </div>

          <!-- Success -->
          <div *ngIf="provisioningSuccess && provResult">
            <div class="success-burst mb-3"><i class="bi bi-check-circle-fill"></i></div>
            <h2>Your LMS is Ready!</h2>
            <p class="text-muted">Welcome to <strong>{{ form.company_name }}</strong>'s learning platform.</p>
            <div class="success-info my-4">
              <div class="info-row"><span>URL</span><strong>{{ provResult.domain }}</strong></div>
              <div class="info-row"><span>Admin</span><strong>{{ provResult.admin_email }}</strong></div>
              <div class="info-row"><span>Code</span><strong>{{ provResult.tenant_code }}</strong></div>
            </div>
            <div class="next-steps-grid text-start mx-auto" style="max-width:440px">
              <h6 class="text-center mb-3">What's Next?</h6>
              <div class="ns" *ngFor="let ns of nextSteps; let i = index">
                <span class="ns-num">{{ i + 1 }}</span><span>{{ ns }}</span>
              </div>
            </div>
            <button class="btn btn-primary btn-lg mt-4" (click)="goToLMS()">
              Go to My LMS <i class="bi bi-arrow-right ms-2"></i>
            </button>
          </div>
        </div>
      </div>

      <!-- Wizard footer -->
      <div class="wizard-footer" *ngIf="!provisioning && !provisioningSuccess">
        <div class="container d-flex align-items-center gap-3">
          <button class="btn btn-outline-secondary" *ngIf="step > 1" (click)="prev()">
            <i class="bi bi-chevron-left me-1"></i>Back
          </button>
          <div class="flex-grow-1"></div>
          <span class="text-muted small">Step {{ step }} of {{ totalSteps }}</span>
          <button class="btn btn-primary" *ngIf="step >= 2 && step <= 4" (click)="next()">
            {{ step === 4 ? (selectedPkgs.length ? 'Continue' : 'Skip & Continue') : 'Continue' }}
            <i class="bi bi-chevron-right ms-1"></i>
          </button>
          <button class="btn btn-primary btn-lg" *ngIf="step === 5" (click)="startProvisioning()" [disabled]="provisioning">
            <i class="bi bi-rocket-takeoff me-2"></i>Create My LMS
          </button>
        </div>
      </div>
    </div>
  `,
  styles: [`
    .onboarding-page { min-height: 100vh; background: #f8f9fa; display: flex; flex-direction: column; }
    .onboarding-topbar { display: flex; align-items: center; justify-content: space-between; padding: 0.75rem 1.5rem; background: #fff; border-bottom: 1px solid #e9ecef; }
    .onboarding-topbar .brand img { height: 36px; }
    .topbar-title { font-weight: 600; font-size: 0.95rem; }

    /* Progress */
    .wizard-progress-bar { padding: 1.5rem 2rem 0; max-width: 800px; margin: 0 auto; width: 100%; position: relative; }
    .progress-track { height: 3px; background: #e9ecef; border-radius: 3px; margin-bottom: 0.75rem; }
    .progress-fill { height: 100%; background: #0E630E; border-radius: 3px; transition: width 0.4s ease; }
    .steps-row { display: flex; justify-content: space-between; }
    .wiz-step { display: flex; flex-direction: column; align-items: center; gap: 0.375rem; cursor: pointer; opacity: 0.35; transition: opacity 0.3s; }
    .wiz-step.active, .wiz-step.done { opacity: 1; }
    .wiz-circle { width: 34px; height: 34px; border-radius: 50%; background: #e9ecef; border: 2px solid #dee2e6; display: flex; align-items: center; justify-content: center; font-size: 0.85rem; color: #6c757d; transition: all 0.3s; }
    .wiz-step.active .wiz-circle { background: #0E630E; border-color: #0E630E; color: #fff; }
    .wiz-step.done .wiz-circle { background: #28a745; border-color: #28a745; color: #fff; }
    .wiz-label { font-size: 0.72rem; font-weight: 600; color: #6c757d; }
    .wiz-step.active .wiz-label { color: #212529; }

    /* Content area */
    .wizard-content { flex: 1; display: flex; align-items: flex-start; justify-content: center; padding: 2rem 1rem 6rem; }
    .wizard-card { background: #fff; border-radius: 1rem; padding: 2.5rem; box-shadow: 0 2px 12px rgba(0,0,0,0.06); width: 100%; max-width: 640px; }
    .wizard-card.narrow { max-width: 480px; }
    .wizard-card.wide { max-width: 860px; }
    .step-icon { width: 56px; height: 56px; border-radius: 16px; background: rgba(14,99,14,0.08); color: #0E630E; display: inline-flex; align-items: center; justify-content: center; font-size: 1.5rem; margin-bottom: 1rem; }
    .tracking-wide { letter-spacing: 0.15em; }
    h2 { font-size: 1.5rem; font-weight: 700; margin-bottom: 0.25rem; }

    /* Billing toggle */
    .billing-toggle { display: flex; align-items: center; justify-content: center; gap: 0.625rem; }
    .billing-toggle span { font-size: 0.9rem; color: #6c757d; cursor: pointer; }
    .billing-toggle span.active { color: #212529; font-weight: 600; }
    .toggle-pill { width: 42px; height: 24px; background: #dee2e6; border-radius: 100px; position: relative; cursor: pointer; transition: background 0.3s; }
    .toggle-pill.yearly { background: #0E630E; }
    .toggle-dot { width: 18px; height: 18px; background: #fff; border-radius: 50%; position: absolute; top: 3px; left: 3px; transition: transform 0.3s; box-shadow: 0 1px 3px rgba(0,0,0,0.15); }
    .toggle-pill.yearly .toggle-dot { transform: translateX(18px); }
    .save-tag { background: rgba(40,167,69,0.12); color: #28a745; padding: 0.1rem 0.4rem; border-radius: 100px; font-size: 0.7rem; font-weight: 700; }

    /* Plans grid */
    .plans-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; }
    .plan-card { border: 2px solid #e9ecef; border-radius: 0.75rem; padding: 1.5rem; cursor: pointer; position: relative; transition: all 0.25s; }
    .plan-card:hover { border-color: rgba(14,99,14,0.3); }
    .plan-card.selected { border-color: #0E630E; background: rgba(14,99,14,0.03); box-shadow: 0 0 0 1px #0E630E; }
    .plan-card.popular { border-color: rgba(14,99,14,0.25); }
    .plan-badge { position: absolute; top: -10px; left: 50%; transform: translateX(-50%); padding: 0.15rem 0.75rem; background: linear-gradient(135deg, #0E630E, #024502); color: #fff; font-size: 0.65rem; font-weight: 700; text-transform: uppercase; border-radius: 100px; white-space: nowrap; }
    .plan-check { position: absolute; top: 0.75rem; right: 0.75rem; font-size: 1.2rem; color: #0E630E; }
    .plan-card h4 { font-size: 1.1rem; font-weight: 700; margin-bottom: 0.5rem; }
    .plan-price-row { margin-bottom: 0.75rem; }
    .plan-price { font-size: 1.75rem; font-weight: 800; }
    .plan-per { font-size: 0.85rem; color: #6c757d; }
    .plan-limits { display: flex; flex-direction: column; gap: 0.25rem; }
    .plan-limits span { font-size: 0.8rem; color: #6c757d; }

    /* Content packages */
    .content-list { display: flex; flex-direction: column; gap: 0.625rem; }
    .content-row { display: flex; align-items: flex-start; gap: 0.875rem; padding: 1rem; border: 2px solid #e9ecef; border-radius: 0.75rem; cursor: pointer; transition: all 0.2s; }
    .content-row:hover { border-color: rgba(14,99,14,0.2); background: #fafbfc; }
    .content-row.selected { border-color: #0E630E; background: rgba(14,99,14,0.03); }
    .content-check { font-size: 1.2rem; color: #0E630E; flex-shrink: 0; margin-top: 2px; }
    .content-icon { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0; }
    .content-info { flex: 1; min-width: 0; }
    .content-info h5 { font-size: 0.95rem; font-weight: 700; margin-bottom: 0.2rem; }
    .content-info p { font-size: 0.8rem; color: #6c757d; margin-bottom: 0.375rem; line-height: 1.5; }
    .content-stats { display: flex; gap: 0.75rem; }
    .content-stats span { font-size: 0.72rem; color: #adb5bd; }
    .content-price { font-size: 1rem; font-weight: 700; flex-shrink: 0; white-space: nowrap; }
    .content-total { text-align: center; padding: 0.625rem; background: #f8f9fa; border-radius: 0.5rem; font-size: 0.9rem; }
    .pop-badge { background: rgba(201,162,39,0.15); color: #b38a1d; padding: 0.05rem 0.4rem; border-radius: 100px; font-size: 0.6rem; font-weight: 700; text-transform: uppercase; vertical-align: middle; margin-left: 0.25rem; }

    /* Review */
    .review-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.875rem; }
    .review-block { padding: 1rem; background: #f8f9fa; border-radius: 0.75rem; }
    .review-block h6 { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: #6c757d; margin-bottom: 0.5rem; }
    .review-block code { background: rgba(14,99,14,0.08); color: #0E630E; padding: 0.1rem 0.35rem; border-radius: 4px; font-size: 0.8rem; }
    .review-info { padding: 0.75rem 1rem; background: rgba(23,162,184,0.06); border-radius: 0.5rem; font-size: 0.82rem; color: #17a2b8; }

    /* Provisioning */
    .prov-spinner { position: relative; width: 72px; height: 72px; margin: 0 auto; }
    .prov-ring { position: absolute; inset: 0; border: 3px solid #e9ecef; border-top-color: #0E630E; border-radius: 50%; animation: spin 1s linear infinite; }
    .prov-icon { position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%); font-size: 1.5rem; color: #0E630E; }
    .prov-progress { width: 100%; max-width: 380px; height: 6px; background: #e9ecef; border-radius: 100px; overflow: hidden; margin: 0 auto; }
    .prov-bar { height: 100%; background: linear-gradient(90deg, #0E630E, #28a745); border-radius: 100px; transition: width 0.5s; }
    .prov-log { }
    .prov-msg { display: flex; align-items: center; gap: 0.5rem; padding: 0.3rem 0; font-size: 0.82rem; color: #6c757d; animation: fadeUp 0.3s ease; }
    .prov-msg.current { font-weight: 600; color: #212529; }

    /* Success */
    .success-burst { font-size: 4rem; color: #28a745; animation: popIn 0.4s cubic-bezier(0.16,1,0.3,1); }
    .success-info { display: flex; justify-content: center; gap: 2rem; flex-wrap: wrap; }
    .info-row { text-align: center; }
    .info-row span { display: block; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.03em; color: #6c757d; margin-bottom: 0.2rem; }
    .info-row strong { font-size: 0.9rem; }
    .next-steps-grid { }
    .ns { display: flex; align-items: center; gap: 0.75rem; padding: 0.6rem 0.875rem; background: #f8f9fa; border-radius: 0.5rem; margin-bottom: 0.5rem; font-size: 0.85rem; }
    .ns-num { width: 24px; height: 24px; border-radius: 50%; background: #0E630E; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 0.7rem; font-weight: 700; flex-shrink: 0; }

    /* Password strength */
    .pw-strength { display: flex; align-items: center; gap: 0.5rem; }
    .pw-bars { display: flex; gap: 3px; }
    .pw-bar { width: 28px; height: 4px; background: #dee2e6; border-radius: 2px; transition: background 0.3s; }
    .pw-bar.on { }

    /* Footer */
    .wizard-footer { position: fixed; bottom: 0; left: 0; right: 0; padding: 0.875rem 0; background: #fff; border-top: 1px solid #e9ecef; z-index: 100; }

    /* Form overrides */
    .form-label { font-size: 0.85rem; margin-bottom: 0.3rem; }
    .form-control:focus, .form-select:focus { border-color: #0E630E; box-shadow: 0 0 0 3px rgba(14,99,14,0.1); }
    .input-group-text { background: #f8f9fa; border-color: #dee2e6; color: #6c757d; }
    .btn-primary { background: linear-gradient(135deg, #0E630E, #024502); border: none; }
    .btn-primary:hover { background: linear-gradient(135deg, #024502, #013301); }

    @keyframes spin { to { transform: rotate(360deg); } }
    @keyframes popIn { from { transform: scale(0); opacity: 0; } to { transform: scale(1); opacity: 1; } }
    @keyframes fadeUp { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }

    @media (max-width: 767px) {
      .plans-grid { grid-template-columns: 1fr; }
      .review-grid { grid-template-columns: 1fr; }
      .wizard-card { padding: 1.5rem; }
      .content-row { flex-wrap: wrap; }
    }
  `]
})
export class OnboardingComponent implements OnInit {
  // Steps
  step = 1;
  totalSteps = 6;
  steps = [
    { key: 'code', label: 'Verify Code', icon: 'bi-key', complete: false },
    { key: 'plan', label: 'Choose Plan', icon: 'bi-tag', complete: false },
    { key: 'company', label: 'Company Info', icon: 'bi-building', complete: false },
    { key: 'content', label: 'Content Packs', icon: 'bi-collection', complete: false },
    { key: 'review', label: 'Review', icon: 'bi-check-circle', complete: false },
    { key: 'setup', label: 'Setup', icon: 'bi-rocket-takeoff', complete: false },
  ];

  // Code validation
  onboardingCode = '';
  codeValidated = false;
  codeValid = false;
  codeError = '';
  validatingCode = false;
  allowedPlanIds: number[] | null = null;

  // Plans
  plans: Plan[] = [];
  selectedPlanId: number | null = null;
  billingCycle: 'monthly' | 'yearly' = 'monthly';

  // Form
  form = {
    company_name: '', admin_email: '', admin_first_name: '', admin_last_name: '',
    password: '', confirm_password: '', company_size: '', industry: '', phone: ''
  };
  showPw = false;
  pwStrength = 0;
  pwLabel = '';
  pwColor = '';
  emailTouched = false;
  emailAvailable = true;
  error = '';

  // Content
  contentPackages: ContentPackage[] = [];
  selectedPkgs: string[] = [];

  // Provisioning
  provisioning = false;
  provisioningSuccess = false;
  provProgress = 0;
  provMessages: string[] = [];
  provResult: ProvisionResponse | null = null;

  nextSteps = [
    'Upload your logo & brand colors',
    'Add employees (CSV, manual, or HR integration)',
    'Create your first course or use content packs',
    'Invite learners and start training'
  ];

  companySizes = [
    { value: '1-10', label: '1–10 employees' }, { value: '11-50', label: '11–50' },
    { value: '51-200', label: '51–200' }, { value: '201-500', label: '201–500' },
    { value: '501-1000', label: '501–1,000' }, { value: '1001+', label: '1,000+' }
  ];
  industries = [
    'Corporate', 'Healthcare', 'Manufacturing', 'Finance & Banking', 'Retail & E-Commerce',
    'Education', 'Professional Services', 'Logistics & Transportation', 'Oil & Gas',
    'Technology', 'Hospitality', 'Government', 'Non-Profit', 'Other'
  ];

  constructor(
    private svc: OnboardingService,
    private router: Router,
    private route: ActivatedRoute
  ) {}

  ngOnInit(): void {
    // Pre-select plan from route param
    const planParam = this.route.snapshot.paramMap.get('planId');
    if (planParam) {
      this.selectedPlanId = +planParam;
    }

    // Load plans and content packages
    this.svc.getPlans().subscribe(p => this.plans = p);
    this.svc.getContentPackages().subscribe(p => this.contentPackages = p);
  }

  // ── Navigation ──
  next(): void {
    if (!this.validateStep()) return;
    this.steps[this.step - 1].complete = true;
    if (this.step < this.totalSteps) this.step++;
    window.scrollTo(0, 0);
  }

  prev(): void {
    if (this.step > 1) { this.step--; window.scrollTo(0, 0); }
  }

  goToStep(n: number): void {
    if (n <= this.step || (n > 1 && this.steps[n - 2]?.complete)) {
      this.step = n;
      window.scrollTo(0, 0);
    }
  }

  // ── Validation ──
  validateStep(): boolean {
    this.error = '';
    switch (this.step) {
      case 1:
        if (!this.codeValid) { this.error = 'Please verify your onboarding code first.'; return false; }
        return true;
      case 2:
        if (!this.selectedPlanId) { this.error = 'Please select a plan.'; return false; }
        if (this.allowedPlanIds && !this.allowedPlanIds.includes(this.selectedPlanId)) {
          this.error = 'Your onboarding code does not allow this plan.'; return false;
        }
        return true;
      case 3:
        const f = this.form;
        if (!f.company_name.trim()) { this.error = 'Company name is required.'; return false; }
        if (!f.admin_email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(f.admin_email)) { this.error = 'Valid email required.'; return false; }
        if (!this.emailAvailable) { this.error = 'This email is already registered.'; return false; }
        if (!f.admin_first_name.trim() || !f.admin_last_name.trim()) { this.error = 'Name is required.'; return false; }
        if (!f.password || f.password.length < 8) { this.error = 'Password must be 8+ characters.'; return false; }
        if (f.password !== f.confirm_password) { this.error = 'Passwords do not match.'; return false; }
        if (!f.company_size) { this.error = 'Company size is required.'; return false; }
        if (!f.industry) { this.error = 'Industry is required.'; return false; }
        return true;
      case 4: return true;
      case 5: return true;
      default: return true;
    }
  }

  // ── Code validation ──
  validateCode(): void {
    this.validatingCode = true;
    this.codeValidated = false;
    this.error = '';
    this.svc.validateCode(this.onboardingCode.trim()).subscribe(res => {
      this.validatingCode = false;
      this.codeValidated = true;
      this.codeValid = res.valid;
      this.codeError = res.error || '';
      this.allowedPlanIds = (res as any).allowed_plan_ids || null;
      if (res.valid) {
        // Auto-advance after brief delay
        setTimeout(() => this.next(), 600);
      }
    });
  }

  // ── Plan ──
  selectPlan(id: number): void { this.selectedPlanId = id; }
  getSelectedPlan(): Plan | null { return this.plans.find(p => p.plan_id === this.selectedPlanId) || null; }

  // ── Content packages ──
  togglePkg(slug: string): void {
    const i = this.selectedPkgs.indexOf(slug);
    i >= 0 ? this.selectedPkgs.splice(i, 1) : this.selectedPkgs.push(slug);
  }
  isSelected(slug: string): boolean { return this.selectedPkgs.includes(slug); }
  getPkgName(slug: string): string { return this.contentPackages.find(p => p.slug === slug)?.name || slug; }
  getPackagesTotal(): string {
    const t = this.selectedPkgs.reduce((s, slug) => {
      const pkg = this.contentPackages.find(p => p.slug === slug);
      return s + (pkg?.price || 0);
    }, 0);
    return t > 0 ? `$${t.toLocaleString()}` : 'Free';
  }

  // ── Form helpers ──
  generateSubdomain(): string {
    return this.form.company_name.trim().toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').substring(0, 30);
  }

  onEmailBlur(): void {
    this.emailTouched = true;
    if (!this.form.admin_email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(this.form.admin_email)) {
      this.emailAvailable = false; return;
    }
    this.svc.checkEmail(this.form.admin_email).subscribe(r => this.emailAvailable = r.available);
  }

  calcPwStrength(): void {
    const pw = this.form.password;
    let s = 0;
    if (pw.length >= 8) s++;
    if (/[A-Z]/.test(pw)) s++;
    if (/[0-9]/.test(pw)) s++;
    if (/[^A-Za-z0-9]/.test(pw)) s++;
    this.pwStrength = s;
    this.pwLabel = ['', 'Weak', 'Fair', 'Good', 'Strong'][s] || '';
    this.pwColor = ['', '#dc3545', '#fd7e14', '#ffc107', '#28a745'][s] || '';
  }

  // ── Provisioning ──
  startProvisioning(): void {
    if (!this.validateStep()) return;
    this.steps[4].complete = true;
    this.step = 6;
    this.provisioning = true;
    this.provProgress = 0;
    this.provMessages = [];

    const req: ProvisionRequest = {
      onboarding_code: this.onboardingCode.trim(),
      company_name: this.form.company_name.trim(),
      admin_email: this.form.admin_email.trim(),
      admin_first_name: this.form.admin_first_name.trim(),
      admin_last_name: this.form.admin_last_name.trim(),
      password: this.form.password,
      company_size: this.form.company_size,
      industry: this.form.industry,
      phone: this.form.phone || undefined,
      plan_id: this.selectedPlanId!,
      content_packages: this.selectedPkgs
    };

    // Show animated progress while API call runs
    const msgs = [
      'Creating your organization...', 'Setting up database...', 'Configuring admin account...',
      'Creating departments & roles...', 'Setting up categories...', 'Preparing branding...',
      'Installing content packages...', 'Finalizing...'
    ];
    let i = 0;
    const anim = setInterval(() => {
      if (i < msgs.length) {
        this.provMessages.push(msgs[i]);
        this.provProgress = Math.min(90, Math.round(((i + 1) / msgs.length) * 90));
        i++;
      }
    }, 500);

    // Actual API call
    this.svc.provision(req).subscribe(res => {
      clearInterval(anim);
      if (res.success && res.data) {
        this.provProgress = 100;
        this.provMessages.push('Your LMS is ready!');
        setTimeout(() => {
          this.provisioning = false;
          this.provisioningSuccess = true;
          this.provResult = res.data!;
          this.steps[5].complete = true;
        }, 600);
      } else {
        this.provisioning = false;
        this.step = 5; // Go back to review
        this.error = res.error || 'Provisioning failed. Please try again.';
      }
    });
  }

  goToLMS(): void {
    if (this.provResult?.domain) {
      // In production: redirect to the new tenant subdomain
      window.location.href = environment.appUrl + '/login';
    }
  }
}
