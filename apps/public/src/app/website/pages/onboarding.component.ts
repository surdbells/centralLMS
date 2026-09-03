// website/pages/onboarding.component.ts
// Self-service tenant onboarding — a standalone wizard against the new API.
//
// Gated by an operator-issued code, so sign-up is Code → Plan → Company →
// Review → Setup. The legacy's content-package step is gone (the new API has
// no such feature yet), and the workspace code is derived and made unique
// server-side rather than checked here.

import { Component, OnDestroy, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink, ActivatedRoute } from '@angular/router';
import { Subscription } from 'rxjs';
import { OnboardingService, Plan, ProvisionRequest, ProvisionedTenant } from '../services/onboarding.service';
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
            <p class="text-muted">Creating a workspace needs a code from our team. Enter yours to begin.</p>
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
        </div>
      </div>

      <!-- ═══ STEP 2: Choose Plan ═══ -->
      <div class="wizard-content" *ngIf="step === 2">
        <div class="wizard-card wide">
          <div class="text-center mb-4">
            <h2>Choose Your Plan</h2>
            <p class="text-muted">Every plan starts with a free trial. No card required to begin.</p>
            <div class="billing-toggle mt-3">
              <span [class.active]="billingCycle === 'monthly'" (click)="billingCycle = 'monthly'">Monthly</span>
              <div class="toggle-pill" [class.yearly]="billingCycle === 'yearly'"
                   (click)="billingCycle = billingCycle === 'monthly' ? 'yearly' : 'monthly'">
                <div class="toggle-dot"></div>
              </div>
              <span [class.active]="billingCycle === 'yearly'" (click)="billingCycle = 'yearly'">Yearly</span>
            </div>
          </div>

          <div class="plans-grid" *ngIf="visiblePlans().length; else plansLoading">
            <div class="plan-card" *ngFor="let plan of visiblePlans()"
                 [class.selected]="selectedPlanId === plan.id"
                 (click)="selectPlan(plan.id)">
              <div class="plan-check">
                <i class="bi" [class]="selectedPlanId === plan.id ? 'bi-check-circle-fill' : 'bi-circle'"></i>
              </div>
              <h4>{{ plan.name }}</h4>
              <div class="plan-price-row">
                <span class="plan-price">{{ planPrice(plan) }}</span>
                <span class="plan-per" *ngIf="planPer(plan)">/{{ planPer(plan) }}</span>
              </div>
              <p class="text-muted small mb-2" *ngIf="plan.description">{{ plan.description }}</p>
              <div class="plan-limits">
                <span><i class="bi bi-people me-1"></i>{{ plan.usersLimit === null ? 'Unlimited' : (plan.usersLimit | number) }} users</span>
                <span><i class="bi bi-hdd me-1"></i>{{ storageLabel(plan) }} storage</span>
                <span><i class="bi bi-calendar-check me-1"></i>{{ plan.trialDays }}-day trial</span>
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
            <p class="text-muted">This sets up your workspace and your administrator account.</p>
          </div>
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-semibold">Company Name <span class="text-danger">*</span></label>
              <div class="input-group">
                <span class="input-group-text"><i class="bi bi-building"></i></span>
                <input type="text" class="form-control" [(ngModel)]="form.company_name" placeholder="Acme Corporation">
              </div>
              <div class="form-text" *ngIf="form.company_name.trim()">
                Your workspace: <strong class="text-primary">{{ generateSubdomain() }}</strong>
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
                <input type="email" class="form-control" [(ngModel)]="form.admin_email" placeholder="admin@acme.com">
              </div>
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

      <!-- ═══ STEP 4: Review ═══ -->
      <div class="wizard-content" *ngIf="step === 4">
        <div class="wizard-card">
          <div class="text-center mb-4">
            <h2>Review & Confirm</h2>
            <p class="text-muted">Everything look good? Click "Create My LMS" to provision your workspace.</p>
          </div>
          <div class="review-grid">
            <div class="review-block">
              <h6><i class="bi bi-tag me-2"></i>Plan</h6>
              <div *ngIf="getSelectedPlan() as p">
                <strong>{{ p.name }}</strong> — {{ planPrice(p) }}<span *ngIf="planPer(p)">/{{ planPer(p) }}</span>
                <div class="small text-muted mt-1">{{ p.trialDays }}-day free trial</div>
              </div>
            </div>
            <div class="review-block">
              <h6><i class="bi bi-building me-2"></i>Organization</h6>
              <strong>{{ form.company_name }}</strong>
              <div class="small text-muted">{{ form.company_size }} &bull; {{ form.industry }}</div>
              <div class="small text-muted mt-1">Workspace: <code>{{ generateSubdomain() }}</code></div>
            </div>
            <div class="review-block">
              <h6><i class="bi bi-person-badge me-2"></i>Admin</h6>
              <strong>{{ form.admin_first_name }} {{ form.admin_last_name }}</strong>
              <div class="small text-muted">{{ form.admin_email }}</div>
            </div>
            <div class="review-block">
              <h6><i class="bi bi-key me-2"></i>Code</h6>
              <strong class="text-uppercase">{{ onboardingCode }}</strong>
              <div class="small text-muted mt-1">Verified</div>
            </div>
          </div>
          <div class="review-info mt-3">
            <i class="bi bi-info-circle me-2"></i>
            After setup you can sign in straight away to upload your logo, add employees, and create your first course.
          </div>
        </div>
      </div>

      <!-- ═══ STEP 5: Provisioning ═══ -->
      <div class="wizard-content" *ngIf="step === 5">
        <div class="wizard-card narrow text-center">
          <!-- In progress -->
          <div *ngIf="provisioning && !provisioningSuccess">
            <div class="prov-spinner mb-4"><div class="prov-ring"></div><i class="bi bi-gear-fill prov-icon"></i></div>
            <h2>Setting Up Your LMS</h2>
            <p class="text-muted mb-4">Please wait — this only takes a moment.</p>
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
            <p class="text-muted">Welcome to <strong>{{ provResult.name }}</strong>'s learning platform.</p>
            <div class="success-info my-4">
              <div class="info-row"><span>Workspace</span><strong>{{ provResult.code }}</strong></div>
              <div class="info-row"><span>Admin</span><strong>{{ provResult.adminEmail }}</strong></div>
              <div class="info-row"><span>Plan</span><strong>{{ provResult.plan.name }}</strong></div>
            </div>
            <div class="next-steps-grid text-start mx-auto" style="max-width:440px">
              <h6 class="text-center mb-3">What's Next?</h6>
              <div class="ns" *ngFor="let ns of nextSteps; let i = index">
                <span class="ns-num">{{ i + 1 }}</span><span>{{ ns }}</span>
              </div>
            </div>
            <button class="btn btn-primary btn-lg mt-4" (click)="goToLMS()">
              Sign in to My LMS <i class="bi bi-arrow-right ms-2"></i>
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
          <button class="btn btn-primary" *ngIf="step >= 2 && step <= 3" (click)="next()">
            Continue <i class="bi bi-chevron-right ms-1"></i>
          </button>
          <button class="btn btn-primary btn-lg" *ngIf="step === 4" (click)="startProvisioning()" [disabled]="provisioning">
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
    .plan-check { position: absolute; top: 0.75rem; right: 0.75rem; font-size: 1.2rem; color: #0E630E; }
    .plan-card h4 { font-size: 1.1rem; font-weight: 700; margin-bottom: 0.5rem; }
    .plan-price-row { margin-bottom: 0.75rem; }
    .plan-price { font-size: 1.75rem; font-weight: 800; }
    .plan-per { font-size: 0.85rem; color: #6c757d; }
    .plan-limits { display: flex; flex-direction: column; gap: 0.25rem; }
    .plan-limits span { font-size: 0.8rem; color: #6c757d; }

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
    .prov-msg { display: flex; align-items: center; gap: 0.5rem; padding: 0.3rem 0; font-size: 0.82rem; color: #6c757d; animation: fadeUp 0.3s ease; }
    .prov-msg.current { font-weight: 600; color: #212529; }

    /* Success */
    .success-burst { font-size: 4rem; color: #28a745; animation: popIn 0.4s cubic-bezier(0.16,1,0.3,1); }
    .success-info { display: flex; justify-content: center; gap: 2rem; flex-wrap: wrap; }
    .info-row { text-align: center; }
    .info-row span { display: block; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.03em; color: #6c757d; margin-bottom: 0.2rem; }
    .info-row strong { font-size: 0.9rem; }
    .ns { display: flex; align-items: center; gap: 0.75rem; padding: 0.6rem 0.875rem; background: #f8f9fa; border-radius: 0.5rem; margin-bottom: 0.5rem; font-size: 0.85rem; }
    .ns-num { width: 24px; height: 24px; border-radius: 50%; background: #0E630E; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 0.7rem; font-weight: 700; flex-shrink: 0; }

    /* Password strength */
    .pw-strength { display: flex; align-items: center; gap: 0.5rem; }
    .pw-bars { display: flex; gap: 3px; }
    .pw-bar { width: 28px; height: 4px; background: #dee2e6; border-radius: 2px; transition: background 0.3s; }

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
    }
  `]
})
export class OnboardingComponent implements OnInit, OnDestroy {
  private svc = inject(OnboardingService);
  private router = inject(Router);
  private route = inject(ActivatedRoute);

  // Steps
  step = 1;
  totalSteps = 5;
  steps = [
    { key: 'code', label: 'Verify Code', icon: 'bi-key', complete: false },
    { key: 'plan', label: 'Choose Plan', icon: 'bi-tag', complete: false },
    { key: 'company', label: 'Company Info', icon: 'bi-building', complete: false },
    { key: 'review', label: 'Review', icon: 'bi-check-circle', complete: false },
    { key: 'setup', label: 'Setup', icon: 'bi-rocket-takeoff', complete: false },
  ];

  // Code validation
  onboardingCode = '';
  codeValidated = false;
  codeValid = false;
  codeError = '';
  validatingCode = false;
  /** null = all plans; otherwise the plan ids the code unlocks. */
  allowedPlanIds: string[] | null = null;

  // Plans
  plans: Plan[] = [];
  selectedPlanId: string | null = null;
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
  error = '';

  // Provisioning
  provisioning = false;
  provisioningSuccess = false;
  provProgress = 0;
  provMessages: string[] = [];
  provResult: ProvisionedTenant | null = null;
  private provAnim: ReturnType<typeof setInterval> | null = null;
  private provSub: Subscription | null = null;

  nextSteps = [
    'Upload your logo & brand colors',
    'Add employees (CSV, manual, or HR integration)',
    'Create your first course',
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

  ngOnInit(): void {
    const planParam = this.route.snapshot.paramMap.get('planId');
    if (planParam) {
      this.selectedPlanId = planParam;
    }
    this.svc.getPlans().subscribe(p => this.plans = p);
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
    // Not while the workspace is being created — the progress bar stays on
    // screen, but its circles must not navigate away from an in-flight setup.
    if (this.provisioning) return;
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
      case 3: {
        const f = this.form;
        if (!f.company_name.trim()) { this.error = 'Company name is required.'; return false; }
        if (!f.admin_email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(f.admin_email)) { this.error = 'Valid email required.'; return false; }
        if (!f.admin_first_name.trim() || !f.admin_last_name.trim()) { this.error = 'Name is required.'; return false; }
        if (!f.password || f.password.length < 8) { this.error = 'Password must be 8+ characters.'; return false; }
        if (f.password !== f.confirm_password) { this.error = 'Passwords do not match.'; return false; }
        if (!f.company_size) { this.error = 'Company size is required.'; return false; }
        if (!f.industry) { this.error = 'Industry is required.'; return false; }
        return true;
      }
      default: return true;
    }
  }

  // ── Code validation ──
  validateCode(): void {
    if (!this.onboardingCode.trim()) return;
    this.validatingCode = true;
    this.codeValidated = false;
    this.error = '';
    this.svc.validateCode(this.onboardingCode.trim()).subscribe(res => {
      this.validatingCode = false;
      this.codeValidated = true;
      this.codeValid = res.valid;
      this.codeError = res.error || 'That code is not valid.';
      this.allowedPlanIds = res.allowedPlanIds ?? null;
      if (res.valid) {
        // If the code narrows the plans and the pre-selected one is not among
        // them, drop it so the customer picks an allowed plan.
        if (this.allowedPlanIds && this.selectedPlanId && !this.allowedPlanIds.includes(this.selectedPlanId)) {
          this.selectedPlanId = null;
        }
        setTimeout(() => this.next(), 600);
      }
    });
  }

  // ── Plans ──
  visiblePlans(): Plan[] {
    if (!this.allowedPlanIds) return this.plans;
    return this.plans.filter(p => this.allowedPlanIds!.includes(p.id));
  }
  selectPlan(id: string): void { this.selectedPlanId = id; }
  getSelectedPlan(): Plan | null { return this.plans.find(p => p.id === this.selectedPlanId) || null; }

  /** The headline price for the chosen billing cycle, formatted, or "Custom". */
  planPrice(p: Plan): string {
    const minor = this.billingCycle === 'yearly' ? p.priceYearly : p.priceMonthly;
    if (minor <= 0) return 'Custom';
    return this.money(minor, p.currency);
  }
  planPer(p: Plan): string {
    const minor = this.billingCycle === 'yearly' ? p.priceYearly : p.priceMonthly;
    if (minor <= 0) return '';
    return this.billingCycle === 'yearly' ? 'yr' : 'mo';
  }
  storageLabel(p: Plan): string {
    if (p.storageLimitMb === null) return 'Unlimited';
    if (p.storageLimitMb >= 1024) return `${Math.round(p.storageLimitMb / 1024)} GB`;
    return `${p.storageLimitMb} MB`;
  }
  private money(minor: number, currency: string): string {
    const symbol = currency === 'NGN' ? '₦' : currency === 'USD' ? '$' : currency + ' ';
    return symbol + Math.round(minor / 100).toLocaleString('en-US');
  }

  // ── Form helpers ──
  generateSubdomain(): string {
    return this.form.company_name.trim().toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').substring(0, 40) || 'your-workspace';
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
    if (this.provisioning) return;
    this.steps[3].complete = true;
    this.step = 5;
    this.provisioning = true;
    this.provProgress = 0;
    this.provMessages = [];

    const req: ProvisionRequest = {
      onboardingCode: this.onboardingCode.trim(),
      companyName: this.form.company_name.trim(),
      adminEmail: this.form.admin_email.trim(),
      adminFirstName: this.form.admin_first_name.trim(),
      adminLastName: this.form.admin_last_name.trim(),
      password: this.form.password,
      planId: this.selectedPlanId!,
      industry: this.form.industry || undefined,
      companySize: this.form.company_size || undefined,
      phone: this.form.phone || undefined,
    };

    const msgs = [
      'Creating your organization...', 'Configuring your administrator...',
      'Applying your plan...', 'Preparing your workspace...', 'Finalizing...'
    ];
    let i = 0;
    this.provAnim = setInterval(() => {
      if (i < msgs.length) {
        this.provMessages.push(msgs[i]);
        this.provProgress = Math.min(90, Math.round(((i + 1) / msgs.length) * 90));
        i++;
      }
    }, 500);

    this.provSub = this.svc.provision(req).subscribe(res => {
      this.stopAnim();
      if (res.success && res.tenant) {
        this.provProgress = 100;
        this.provMessages.push('Your LMS is ready!');
        setTimeout(() => {
          this.provisioning = false;
          this.provisioningSuccess = true;
          this.provResult = res.tenant!;
          this.steps[4].complete = true;
        }, 600);
      } else {
        this.provisioning = false;
        // Send them back to the step that owns the problem.
        this.step = this.stepForFields(res.fields) ?? 4;
        this.error = res.error || 'Provisioning failed. Please try again.';
      }
    });
  }

  goToLMS(): void {
    window.location.href = environment.appUrl + '/login';
  }

  ngOnDestroy(): void {
    // Navigating away mid-provision must not leave the progress timer firing or
    // the request's subscription open against a destroyed component.
    this.stopAnim();
    this.provSub?.unsubscribe();
  }

  private stopAnim(): void {
    if (this.provAnim !== null) { clearInterval(this.provAnim); this.provAnim = null; }
  }

  /** Which step a set of field errors belongs to, so we return there. */
  private stepForFields(fields?: Record<string, string>): number | null {
    if (!fields) return null;
    const keys = Object.keys(fields);
    if (keys.includes('onboardingCode')) return 1;
    if (keys.includes('planId')) return 2;
    if (keys.some(k => ['companyName', 'adminEmail', 'adminFirstName', 'adminLastName', 'password'].includes(k))) return 3;
    return null;
  }
}
