// lms-homepage/lms-homepage.component.ts

import { Component, OnInit, OnDestroy, HostListener, ElementRef, ViewChild, AfterViewInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterModule, Router } from '@angular/router';

// ═══════════════════════════════════════════
//  INTERFACES
// ═══════════════════════════════════════════

interface Feature { icon: string; title: string; description: string; highlight: string; color: string; screenshot: string; }
interface Industry { icon: string; name: string; description: string; }
interface UseCase { title: string; description: string; metric: string; metricLabel: string; emoji: string; }
interface Testimonial { quote: string; author: string; role: string; company: string; avatar: string; }
interface Stat { value: number; suffix: string; label: string; current: number; }
interface PricingPlan {
  plan_id: number; name: string; price: string; yearlyPrice: string; period: string;
  description: string; features: string[]; popular: boolean; cta: string;
  users_limit: number; storage_limit_mb: number;
}
interface ContentPackage {
  id: string; name: string; description: string; icon: string; color: string;
  courses: number; assessments: number; certificates: number;
  price: string; tags: string[]; popular: boolean;
}
interface SignupForm {
  company_name: string; admin_email: string; admin_first_name: string;
  admin_last_name: string; password: string; confirm_password: string;
  company_size: string; industry: string; phone: string;
}
interface WizardStep { key: string; label: string; icon: string; complete: boolean; }

@Component({
  selector: 'app-lms-homepage',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterModule],
  templateUrl: './lms-homepage.component.html',
  styleUrls: ['./lms-homepage.component.scss']
})
export class LmsHomepageComponent implements OnInit, OnDestroy, AfterViewInit {

  // ── Homepage state ──
  isScrolled = false;
  mobileMenuOpen = false;
  activeFeature = 0;
  private featureInterval: any;
  heroVisible = false; featuresVisible = false; industriesVisible = false;
  useCasesVisible = false; testimonialsVisible = false; pricingVisible = false;
  ctaVisible = false; marketplaceVisible = false; platformTourVisible = false;

  stats: Stat[] = [
    { value: 2500, suffix: '+', label: 'Organizations', current: 0 },
    { value: 5, suffix: 'M+', label: 'Active Learners', current: 0 },
    { value: 98, suffix: '%', label: 'Satisfaction Rate', current: 0 },
    { value: 150, suffix: '+', label: 'Countries', current: 0 }
  ];

  // ── Onboarding flow ──
  showOnboarding = false;
  onboardingStep = 1;
  totalOnboardingSteps = 5;
  onboardingLoading = false;
  onboardingError = '';
  onboardingSuccess = false;
  provisioningProgress = 0;
  provisioningMessages: string[] = [];
  showPassword = false;
  passwordStrength = 0;
  emailValid = true;
  emailTouched = false;
  selectedPlanId: number | null = null;
  billingCycle: 'monthly' | 'yearly' = 'monthly';
  selectedPackages: string[] = [];

  signupForm: SignupForm = {
    company_name: '', admin_email: '', admin_first_name: '', admin_last_name: '',
    password: '', confirm_password: '', company_size: '', industry: '', phone: ''
  };

  wizardSteps: WizardStep[] = [
    { key: 'plan', label: 'Choose Plan', icon: 'bi-tag', complete: false },
    { key: 'signup', label: 'Company Info', icon: 'bi-building', complete: false },
    { key: 'content', label: 'Content Packs', icon: 'bi-collection', complete: false },
    { key: 'review', label: 'Review', icon: 'bi-check-circle', complete: false },
    { key: 'setup', label: 'Setup', icon: 'bi-rocket-takeoff', complete: false },
  ];

  companySizes = [
    { value: '1-10', label: '1–10 employees' }, { value: '11-50', label: '11–50 employees' },
    { value: '51-200', label: '51–200 employees' }, { value: '201-500', label: '201–500 employees' },
    { value: '501-1000', label: '501–1,000 employees' }, { value: '1001+', label: '1,000+ employees' }
  ];

  industryOptions = [
    'Corporate', 'Healthcare', 'Manufacturing', 'Finance & Banking', 'Retail & E-Commerce',
    'Education', 'Professional Services', 'Logistics & Transportation', 'Oil & Gas',
    'Technology', 'Hospitality', 'Government', 'Non-Profit', 'Other'
  ];

  // ── Homepage data ──

  // Active screenshot tab for platform tour
  activeScreenshotTab = 0;

  // Platform tour screenshots (shown between features and industries)
  platformScreenshots: { title: string; description: string; image: string; icon: string; }[] = [
    { title: 'Admin Dashboard', description: 'Complete overview of enrollments, completions, compliance status, and learner activity at a glance.', image: 'assets/img/screenshots/admin-dashboard.png', icon: 'bi-speedometer2' },
    { title: 'Course Builder', description: 'Drag-and-drop course creation with modules, lessons, quizzes, SCORM packages, and multimedia.', image: 'assets/img/screenshots/course-builder.png', icon: 'bi-pencil-ruler' },
    { title: 'Learner Portal', description: 'Clean, intuitive interface where learners browse courses, track progress, and earn certificates.', image: 'assets/img/screenshots/learner-portal.png', icon: 'bi-mortarboard' },
    { title: 'Reports & Analytics', description: '77+ reports with charts, filters, exports, and scheduled email delivery.', image: 'assets/img/screenshots/reports.png', icon: 'bi-graph-up-arrow' },
    { title: 'Live Training', description: 'Schedule sessions, manage attendance with QR codes, and integrate with Zoom, Teams, or Meet.', image: 'assets/img/screenshots/live-training.png', icon: 'bi-camera-video' },
    { title: 'Gamification', description: 'Points, badges, leaderboards, streaks, and achievement milestones that drive engagement.', image: 'assets/img/screenshots/gamification.png', icon: 'bi-trophy' },
  ];

  features: Feature[] = [
    { icon: 'bi-collection-play', title: 'Course Management', description: 'Create rich courses with modules, lessons, quizzes, SCORM packages, video, audio, and PDF content with drag-and-drop organization.', highlight: 'Content Builder', color: '#c9a227', screenshot: 'assets/img/screenshots/course-builder.png' },
    { icon: 'bi-signpost-split', title: 'Learning Paths', description: 'Design structured learning journeys with sequential courses, prerequisites, milestones, and automated assignments by department, team, or branch.', highlight: 'Guided Learning', color: '#6f42c1', screenshot: 'assets/img/screenshots/learning-paths.png' },
    { icon: 'bi-camera-video', title: 'Live Training', description: 'Schedule physical, virtual, or hybrid sessions with Zoom, Teams, and Meet integration, QR attendance tracking, and waitlist management.', highlight: 'ILT Sessions', color: '#17a2b8', screenshot: 'assets/img/screenshots/live-training.png' },
    { icon: 'bi-chat-dots', title: 'Messaging & Collaboration', description: 'Direct messages, group chats, channels, broadcasts with file sharing, reactions, mentions, and notification preferences.', highlight: 'Communication', color: '#28a745', screenshot: 'assets/img/screenshots/messaging.png' },
    { icon: 'bi-graph-up-arrow', title: 'Reports & Analytics', description: '77+ comprehensive reports covering enrollment, completion, compliance, assessments, gamification, certificates, and executive dashboards.', highlight: '77+ Reports', color: '#e83e8c', screenshot: 'assets/img/screenshots/reports.png' },
    { icon: 'bi-trophy', title: 'Gamification & Certificates', description: 'Points, badges, leaderboards, streaks, and automated certificate generation with custom templates and validity tracking.', highlight: 'Engagement', color: '#fd7e14', screenshot: 'assets/img/screenshots/gamification.png' }
  ];

  industries: Industry[] = [
    { icon: 'bi-building', name: 'Corporate', description: 'Employee onboarding, skills development, and leadership training' },
    { icon: 'bi-heart-pulse', name: 'Healthcare', description: 'Medical certifications, compliance training, and continuing education' },
    { icon: 'bi-gear', name: 'Manufacturing', description: 'Safety protocols, equipment training, and quality standards' },
    { icon: 'bi-currency-dollar', name: 'Finance', description: 'Regulatory compliance, risk management, and professional development' },
    { icon: 'bi-cart', name: 'Retail', description: 'Product knowledge, customer service, and sales training' },
    { icon: 'bi-mortarboard', name: 'Education', description: 'Course management, student progress, and academic programs' },
    { icon: 'bi-briefcase', name: 'Professional Services', description: 'Consulting skills, client management, and industry certifications' },
    { icon: 'bi-truck', name: 'Logistics', description: 'Safety compliance, operator certification, and regulatory training' }
  ];

  useCases: UseCase[] = [
    { title: 'Streamlined Onboarding', description: 'Reduce new hire ramp-up time with automated learning path assignments by department, team, or branch with progress tracking.', metric: '73%', metricLabel: 'Faster Onboarding', emoji: '🚀' },
    { title: 'Compliance Assurance', description: 'Automated mandatory assignments, deadline reminders, audit trails, and 100% completion tracking with certificate verification.', metric: '100%', metricLabel: 'Compliance Rate', emoji: '✓' },
    { title: 'Blended Learning', description: 'Combine self-paced courses, live training sessions, and learning paths with seamless tracking across all modalities.', metric: '340%', metricLabel: 'Engagement Increase', emoji: '📈' }
  ];

  testimonials: Testimonial[] = [
    { quote: "This platform transformed how we train our global workforce. The multi-tenant feature lets each region customize while we maintain central oversight.", author: "Sarah Chen", role: "Chief Learning Officer", company: "TechCorp Global", avatar: "SC" },
    { quote: "We achieved 100% compliance training completion for the first time in company history. The automated reminders and tracking are game-changers.", author: "Michael Roberts", role: "VP of HR", company: "FinanceFirst", avatar: "MR" },
    { quote: "The gamification features increased our course completion rates by 340%. Our employees actually enjoy training now.", author: "Amara Okonkwo", role: "Training Director", company: "RetailMax", avatar: "AO" }
  ];

  pricingPlans: PricingPlan[] = [
    { plan_id: 1, name: 'Starter', price: '$99', yearlyPrice: '$79', period: '/mo', description: 'Perfect for small teams getting started', features: ['Up to 50 users', '10 courses', '1 GB storage', 'Basic analytics', 'Email support', 'SSL security'], popular: false, cta: 'Start Free Trial', users_limit: 50, storage_limit_mb: 1024 },
    { plan_id: 2, name: 'Professional', price: '$299', yearlyPrice: '$239', period: '/mo', description: 'For growing organizations', features: ['Up to 500 users', 'Unlimited courses', '25 GB storage', 'Advanced analytics', 'Priority support', 'Custom branding', 'API access', 'SSO integration'], popular: true, cta: 'Start Free Trial', users_limit: 500, storage_limit_mb: 25600 },
    { plan_id: 3, name: 'Enterprise', price: 'Custom', yearlyPrice: 'Custom', period: '', description: 'For large-scale deployments', features: ['Unlimited users', 'Unlimited courses', 'Unlimited storage', 'Enterprise analytics', 'Dedicated support', 'White-label solution', 'Custom integrations', 'SLA guarantee', 'On-premise option'], popular: false, cta: 'Contact Sales', users_limit: 999999, storage_limit_mb: 999999 }
  ];

  contentPackages: ContentPackage[] = [
    { id: 'compliance', name: 'Compliance Essentials', description: 'Workplace safety, anti-harassment, data protection (GDPR), AML, and ethics training with certificates.', icon: 'bi-shield-check', color: '#dc3545', courses: 12, assessments: 24, certificates: 12, price: '$499', tags: ['Safety', 'GDPR', 'AML', 'Ethics'], popular: true },
    { id: 'hr-onboarding', name: 'HR & Onboarding', description: 'Employee onboarding, leadership fundamentals, communication skills, performance management, and team building.', icon: 'bi-people', color: '#6f42c1', courses: 15, assessments: 30, certificates: 10, price: '$599', tags: ['Onboarding', 'Leadership', 'Communication'], popular: true },
    { id: 'it-security', name: 'IT & Cybersecurity', description: 'Cybersecurity awareness, phishing prevention, password management, data handling, and incident response.', icon: 'bi-lock', color: '#17a2b8', courses: 8, assessments: 16, certificates: 8, price: '$399', tags: ['Security', 'Phishing', 'Data Protection'], popular: false },
    { id: 'healthcare', name: 'Healthcare Pack', description: 'HIPAA compliance, patient safety, infection control, medical device training, and clinical protocols.', icon: 'bi-heart-pulse', color: '#28a745', courses: 10, assessments: 20, certificates: 10, price: '$699', tags: ['HIPAA', 'Patient Safety', 'Clinical'], popular: false },
    { id: 'finance', name: 'Finance & Banking', description: 'Regulatory compliance, risk management, KYC/AML, financial reporting, and customer due diligence.', icon: 'bi-bank', color: '#fd7e14', courses: 10, assessments: 20, certificates: 8, price: '$599', tags: ['KYC', 'AML', 'Risk', 'Regulatory'], popular: false },
    { id: 'soft-skills', name: 'Professional Skills', description: 'Time management, presentation skills, conflict resolution, emotional intelligence, and critical thinking.', icon: 'bi-lightbulb', color: '#c9a227', courses: 12, assessments: 12, certificates: 6, price: '$449', tags: ['Soft Skills', 'EQ', 'Productivity'], popular: false }
  ];

  // Intersection observer
  private observer!: IntersectionObserver;
  @ViewChild('heroSection') heroSection!: ElementRef;
  @ViewChild('featuresSection') featuresSection!: ElementRef;
  @ViewChild('industriesSection') industriesSection!: ElementRef;
  @ViewChild('useCasesSection') useCasesSection!: ElementRef;
  @ViewChild('testimonialsSection') testimonialsSection!: ElementRef;
  @ViewChild('pricingSection') pricingSection!: ElementRef;
  @ViewChild('ctaSection') ctaSection!: ElementRef;
  @ViewChild('marketplaceSection') marketplaceSection!: ElementRef;
  @ViewChild('platformTourSection') platformTourSection!: ElementRef;

  constructor(private router: Router) {}

  ngOnInit(): void {
    this.featureInterval = setInterval(() => {
      this.activeFeature = (this.activeFeature + 1) % this.features.length;
    }, 4000);
  }

  ngAfterViewInit(): void { this.setupIntersectionObserver(); }

  ngOnDestroy(): void {
    if (this.featureInterval) clearInterval(this.featureInterval);
    if (this.observer) this.observer.disconnect();
  }

  @HostListener('window:scroll', [])
  onWindowScroll(): void { this.isScrolled = window.scrollY > 50; }

  // ═══════════════════════════════════════════
  //  INTERSECTION OBSERVER
  // ═══════════════════════════════════════════

  setupIntersectionObserver(): void {
    this.observer = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          const id = entry.target.getAttribute('data-section');
          switch (id) {
            case 'hero': this.heroVisible = true; this.animateStats(); break;
            case 'features': this.featuresVisible = true; break;
            case 'industries': this.industriesVisible = true; break;
            case 'useCases': this.useCasesVisible = true; break;
            case 'testimonials': this.testimonialsVisible = true; break;
            case 'pricing': this.pricingVisible = true; break;
            case 'cta': this.ctaVisible = true; break;
            case 'marketplace': this.marketplaceVisible = true; break;
            case 'platformTour': this.platformTourVisible = true; break;
          }
        }
      });
    }, { threshold: 0.1 });
    setTimeout(() => {
      [this.heroSection, this.featuresSection, this.industriesSection,
        this.useCasesSection, this.testimonialsSection, this.pricingSection,
        this.ctaSection, this.marketplaceSection, this.platformTourSection
      ].forEach(s => { if (s?.nativeElement) this.observer.observe(s.nativeElement); });
    }, 100);
  }

  animateStats(): void {
    const steps = 60, interval = 2000 / steps;
    this.stats.forEach(stat => {
      let step = 0;
      const inc = stat.value / steps;
      const t = setInterval(() => {
        step++; stat.current = Math.min(Math.floor(inc * step), stat.value);
        if (step >= steps) { clearInterval(t); stat.current = stat.value; }
      }, interval);
    });
  }

  setActiveFeature(i: number): void {
    this.activeFeature = i;
    if (this.featureInterval) clearInterval(this.featureInterval);
    this.featureInterval = setInterval(() => { this.activeFeature = (this.activeFeature + 1) % this.features.length; }, 4000);
  }

  toggleMobileMenu(): void { this.mobileMenuOpen = !this.mobileMenuOpen; }

  scrollToSection(id: string): void {
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth' });
    this.mobileMenuOpen = false;
  }

  // ═══════════════════════════════════════════
  //  ONBOARDING FLOW
  // ═══════════════════════════════════════════

  openOnboarding(planId?: number): void {
    this.showOnboarding = true;
    this.onboardingStep = 1;
    this.onboardingError = '';
    this.onboardingSuccess = false;
    this.provisioningProgress = 0;
    this.provisioningMessages = [];
    if (planId) { this.selectedPlanId = planId; this.wizardSteps[0].complete = true; this.onboardingStep = 2; }
    document.body.style.overflow = 'hidden';
  }

  closeOnboarding(): void { this.showOnboarding = false; document.body.style.overflow = ''; }

  nextStep(): void {
    if (!this.validateCurrentStep()) return;
    this.wizardSteps[this.onboardingStep - 1].complete = true;
    if (this.onboardingStep < this.totalOnboardingSteps) this.onboardingStep++;
  }

  prevStep(): void { if (this.onboardingStep > 1) this.onboardingStep--; }

  goToStep(step: number): void {
    if (step <= this.onboardingStep || this.wizardSteps[step - 2]?.complete) this.onboardingStep = step;
  }

  validateCurrentStep(): boolean {
    this.onboardingError = '';
    switch (this.onboardingStep) {
      case 1:
        if (!this.selectedPlanId) { this.onboardingError = 'Please select a plan to continue.'; return false; }
        return true;
      case 2:
        const f = this.signupForm;
        if (!f.company_name.trim()) { this.onboardingError = 'Company name is required.'; return false; }
        if (!f.admin_email.trim() || !this.isValidEmail(f.admin_email)) { this.onboardingError = 'A valid email address is required.'; return false; }
        if (!f.admin_first_name.trim() || !f.admin_last_name.trim()) { this.onboardingError = 'First and last name are required.'; return false; }
        if (!f.password || f.password.length < 8) { this.onboardingError = 'Password must be at least 8 characters.'; return false; }
        if (f.password !== f.confirm_password) { this.onboardingError = 'Passwords do not match.'; return false; }
        if (!f.company_size) { this.onboardingError = 'Please select your company size.'; return false; }
        if (!f.industry) { this.onboardingError = 'Please select your industry.'; return false; }
        return true;
      case 3: return true; // Content packs optional
      case 4: return true;
      default: return true;
    }
  }

  selectPlan(planId: number): void { this.selectedPlanId = planId; }
  getSelectedPlan(): PricingPlan | null { return this.pricingPlans.find(p => p.plan_id === this.selectedPlanId) || null; }

  togglePackage(id: string): void {
    const idx = this.selectedPackages.indexOf(id);
    idx >= 0 ? this.selectedPackages.splice(idx, 1) : this.selectedPackages.push(id);
  }

  isPackageSelected(id: string): boolean { return this.selectedPackages.includes(id); }
  getSelectedPackages(): ContentPackage[] { return this.contentPackages.filter(p => this.selectedPackages.includes(p.id)); }

  getContentPackagesTotal(): string {
    const total = this.getSelectedPackages().reduce((s, p) => s + (parseInt(p.price.replace(/\D/g, ''), 10) || 0), 0);
    return total > 0 ? `$${total}` : 'Included';
  }

  generateSubdomain(): string {
    return this.signupForm.company_name.trim().toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').substring(0, 30);
  }

  // ── Provisioning ──

  startProvisioning(): void {
    this.wizardSteps[3].complete = true;
    this.onboardingStep = 5;
    this.onboardingLoading = true;
    this.provisioningProgress = 0;
    this.provisioningMessages = [];

    const steps = [
      { msg: 'Creating your organization...', pct: 10 },
      { msg: 'Setting up tenant database...', pct: 25 },
      { msg: 'Configuring admin account...', pct: 40 },
      { msg: 'Creating default departments & roles...', pct: 55 },
      { msg: 'Setting up course categories...', pct: 65 },
      { msg: 'Preparing branding placeholders...', pct: 75 },
      { msg: 'Installing content packages...', pct: 85 },
      { msg: 'Finalizing setup...', pct: 95 },
      { msg: 'Your LMS is ready!', pct: 100 },
    ];

    let i = 0;
    const interval = setInterval(() => {
      if (i < steps.length) {
        this.provisioningMessages.push(steps[i].msg);
        this.provisioningProgress = steps[i].pct;
        i++;
      } else {
        clearInterval(interval);
        this.onboardingLoading = false;
        this.onboardingSuccess = true;
        this.wizardSteps[4].complete = true;
      }
    }, 800);

    // TODO: Replace with actual HTTP call to provisioning API
    // POST /api/tenants.php { action: 'provision', ...this.signupForm, plan_id, content_packages }
  }

  goToNewLMS(): void {
    this.closeOnboarding();
    this.router.navigate(['/login']);
  }

  // ── Validation helpers ──

  isValidEmail(email: string): boolean { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email); }

  onEmailBlur(): void {
    this.emailTouched = true;
    this.emailValid = this.isValidEmail(this.signupForm.admin_email);
  }

  onPasswordInput(): void {
    const pw = this.signupForm.password;
    let s = 0;
    if (pw.length >= 8) s++;
    if (/[A-Z]/.test(pw)) s++;
    if (/[0-9]/.test(pw)) s++;
    if (/[^A-Za-z0-9]/.test(pw)) s++;
    this.passwordStrength = s;
  }

  getPasswordStrengthLabel(): string { return ['', 'Weak', 'Fair', 'Good', 'Strong'][this.passwordStrength] || ''; }
  getPasswordStrengthColor(): string { return ['', '#dc3545', '#fd7e14', '#ffc107', '#28a745'][this.passwordStrength] || ''; }

  getPlanPrice(plan: PricingPlan): string {
    if (plan.price === 'Custom') return 'Custom';
    return this.billingCycle === 'yearly' ? plan.yearlyPrice : plan.price;
  }
}
