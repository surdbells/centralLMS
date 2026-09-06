// website/pages/home/home.component.ts
import { Component, OnInit, OnDestroy, AfterViewInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterLink } from '@angular/router';
import { Title, Meta } from '@angular/platform-browser';
import { NavbarComponent } from '../../shared/navbar/navbar.component';
import { FooterComponent } from '../../shared/footer/footer.component';

@Component({
  selector: 'ws-home', standalone: true,
  imports: [CommonModule, RouterLink, NavbarComponent, FooterComponent],
  styleUrls: ['../../website.scss', './home.component.scss'],
  templateUrl: './home.component.html',
})
export class HomeComponent implements OnInit, AfterViewInit, OnDestroy {
  heroVisible = false;
  sv: Record<string, boolean> = {};
  private observer!: IntersectionObserver;
  constructor(private titleService: Title, private metaService: Meta) {}


  openFaq: number | null = null;

  heroStats = [
    { display: '2,500+', label: 'Organizations' }, { display: '5M+', label: 'Active Learners' },
    { display: '98%', label: 'Satisfaction Rate' }, { display: '150+', label: 'Countries' },
  ];
  mockKpis = [
    { val: '1,284', label: 'Active Learners', color: '#0A4F2A' },
    { val: '87%', label: 'Completion Rate', color: '#059669' },
    { val: '42', label: 'Live Courses', color: '#0284C7' },
    { val: '96%', label: 'Compliance', color: '#C9A227' },
  ];
  trustLogos = [
  { name: 'FMDQ', logo: 'assets/logos/fmdq.png' },
  { name: 'Unity Bank', logo: 'assets/logos/unity-bank.png' },
  { name: 'I-Fitness', logo: 'assets/logos/i-fitness.png' },
  { name: 'Cellulant', logo: 'assets/logos/cellulant.png' },
  { name: 'NESG', logo: 'assets/logos/nesg.png' },
  { name: 'E-Health Africa', logo: 'assets/logos/ehealth-africa.png' },
  { name: 'FCMB', logo: 'assets/logos/fcmb.png' },
  { name: 'NILE University', logo: 'assets/logos/nile-university.png' },
  { name: 'BOI', logo: 'assets/logos/boi.png' }
];
  capabilities = [
    { icon: 'bi-display', title: 'Centralised Learning Management', desc: 'Manage all content, automate administration, and track compliance from a single platform.', color: '#0A4F2A' },
    { icon: 'bi-person-lines-fill', title: 'Personalised Learning Experiences', desc: 'Tailored journeys ensuring relevance, engagement, and better retention for every learner.', color: '#6F42C1' },
    { icon: 'bi-graph-up-arrow', title: 'Real-Time Analytics & Insights', desc: 'Instant visibility into performance, trends, and skills gaps with 127+ built-in reports.', color: '#0284C7' },
    { icon: 'bi-plug', title: 'Seamless HR Integration', desc: 'Integrate with HRIS, talent systems, and enterprise apps for smooth workflows.', color: '#D97706' },
  ];
  features = [
    { title: 'Learning Management System', desc: 'Streamlines delivery, automates reporting, supports compliance. Full SCORM 1.2/2004 and xAPI.', badge: 'Core LMS', color: '#0A4F2A', size: 'lg', stat: '127+', statLabel: 'Reports' },
    { title: 'Proctored Assessments', desc: 'AI-powered proctoring with webcam monitoring, trust scoring, and automated grading.', badge: 'Assessments', color: '#DC2626', size: 'md', stat: null, statLabel: null },
    { title: 'Live Classes & ILT', desc: 'Run classes in-app with built-in HD video, screen-share, chat & raise-hand — or connect Zoom, Teams & Google Meet. Physical, virtual & hybrid, with QR attendance.', badge: 'Live Video', color: '#0891B2', size: 'md', stat: null, statLabel: null },
    { title: 'Gamification & Certificates', desc: 'Points, badges, leaderboards, streaks, and auto-generated certificates with QR.', badge: 'Engagement', color: '#C9A227', size: 'md', stat: null, statLabel: null },
    { title: 'Advanced Reporting', desc: '77 admin + 43 learner + 7 assessment reports, plus custom report builder.', badge: '127+ Reports', color: '#BE185D', size: 'lg', stat: '23', statLabel: 'Categories' },
    { title: 'Messaging & Collaboration', desc: 'DMs, group chats, channels, broadcasts with reactions, mentions, and moderation.', badge: 'Communication', color: '#7C3AED', size: 'md', stat: null, statLabel: null },
    { title: 'Competency & Skills', desc: 'Competency dictionaries, role profiles, skills-gap analysis, and cycle-based assessment outcomes.', badge: 'Competency', color: '#6F42C1', size: 'md', stat: null, statLabel: null },
    { title: 'Learning Paths', desc: 'Sequenced, role-based journeys that guide every learner from onboarding to mastery.', badge: 'Journeys', color: '#D97706', size: 'md', stat: null, statLabel: null },
    { title: 'Digital Library', desc: 'Audience-targeted, view-only resource sharing with on-the-fly document conversion.', badge: 'Content', color: '#059669', size: 'md', stat: null, statLabel: null },
  ];
  integrations = [
    { icon: 'bi-camera-video-fill', title: 'In-App HD Video', desc: 'Built-in live classrooms — video, screen-share, chat & raise-hand, right inside Central LMS. No external link, no extra app. The default for every live class.', color: '#0A4F2A' },
    { icon: 'bi-camera-reels-fill', title: 'Zoom', desc: 'Schedule and launch Zoom meetings from a session, with attendance pulled back automatically.', color: '#2D8CFF' },
    { icon: 'bi-people-fill', title: 'Microsoft Teams', desc: 'Host sessions in Teams for organisations standardised on Microsoft 365, with attendance sync.', color: '#4B53BC' },
    { icon: 'bi-camera-video', title: 'Google Meet', desc: 'Run classes over Google Meet and keep scheduling, joining and attendance in one place.', color: '#00897B' },
    { icon: 'bi-file-earmark-code-fill', title: 'SCORM & xAPI', desc: 'Import and play SCORM 1.2 / 2004 and xAPI content — bring your existing courseware with you.', color: '#0284C7' },
    { icon: 'bi-shield-lock-fill', title: 'Single Sign-On', desc: 'SSO so your people sign in with the identity they already have, with role-based access control.', color: '#BE185D' },
    { icon: 'bi-plug-fill', title: 'HRIS & Enterprise Apps', desc: 'Sync users, roles and structure with your HRIS and enterprise systems for hands-off administration.', color: '#D97706' },
    { icon: 'bi-credit-card-2-front-fill', title: 'Payments', desc: 'Sell courses and take payments in multiple currencies with built-in checkout (Paystack).', color: '#C9A227' },
  ];
  approach = [
    { title: 'Needs Assessment', desc: 'Understand your goals and requirements.' },
    { title: 'Solution Design', desc: 'Configure platform to your workflows.' },
    { title: 'Deployment', desc: 'Managed rollout with smooth onboarding.' },
    { title: 'Training', desc: 'Hands-on enablement for your teams.' },
    { title: 'Ongoing Support', desc: 'Continuous optimisation and enhancements.' },
  ];
  results = [
    { metric: '73%', label: 'Faster Onboarding', desc: 'Automated learning paths and progress tracking reduce ramp-up time.' },
    { metric: '100%', label: 'Compliance Rate', desc: 'Automated assignments, reminders, and audit trails with certificates.' },
    { metric: '340%', label: 'Engagement Increase', desc: 'Gamification transforms training from obligation to achievement.' },
  ];
  testimonials = [
    { quote: 'My experience with Central LMS has been generally positive. I particularly appreciate the user interface—the choice of colours and overall visual appeal make the platform engaging and easy to navigate.', name: 'Mofeyifoluwa Adepitan', role: 'Academy', company: 'FMDQ', initials: 'MA' },
    { quote: 'Central LMS has provided a smooth and reliable learning experience for i-Fitness, making course management and learner engagement much easier to handle.', name: 'Marvel Emiohe', role: 'Instructional Design /e-learning specialist', company: 'I-Fitness', initials: 'ME' },
    { quote: 'We have found it helpful in centralizing our learning activities, improving access to training resources, and enhancing the overall coordination of training across the Bank.', name: 'Saidu Haruna', role: 'Head, Learning & Development Department', company: 'Unity Bank', initials: 'SA' },
  ];
  pricingPreview = [
    { name: 'Starter', price: '$99/mo', desc: 'Up to 100 users' },
    { name: 'Professional', price: '$249/mo', desc: 'Up to 500 users' },
    { name: 'Enterprise', price: 'Custom', desc: 'Unlimited' },
  ];
  marketplacePreview = [
    { name: 'Compliance', icon: 'bi-shield-check', color: '#DC2626', courses: 12 },
    { name: 'HR & Onboarding', icon: 'bi-people', color: '#6F42C1', courses: 15 },
    { name: 'IT Security', icon: 'bi-lock', color: '#0891B2', courses: 8 },
    { name: 'Healthcare', icon: 'bi-heart-pulse', color: '#059669', courses: 10 },
    { name: 'Finance', icon: 'bi-bank', color: '#D97706', courses: 10 },
    { name: 'Soft Skills', icon: 'bi-lightbulb', color: '#C9A227', courses: 12 },
  ];
  blogPosts = [
    { slug: 'scalable-learning-tech-africa', title: 'Why Scalable Learning Technology is Essential for African Enterprises', excerpt: 'Traditional methods no longer meet modern workforce demands.', tag: 'Learning Tech', tagColor: '#0A4F2A', date: 'Mar 28, 2026', readTime: '6 min', gradient: 'linear-gradient(135deg,#0A4F2A,#0D9488)' },
    { slug: 'scorm-compliance-guide', title: 'The Complete Guide to SCORM Compliance in Enterprise LMS', excerpt: 'Everything about SCORM 1.2, 2004, and xAPI standards.', tag: 'Technical', tagColor: '#0284C7', date: 'Mar 15, 2026', readTime: '8 min', gradient: 'linear-gradient(135deg,#0284C7,#7C3AED)' },
    { slug: 'gamification-strategies', title: '5 Gamification Strategies That Boost Training Completion', excerpt: 'Data-driven approaches that transform learner engagement.', tag: 'Engagement', tagColor: '#C9A227', date: 'Mar 5, 2026', readTime: '5 min', gradient: 'linear-gradient(135deg,#C9A227,#F59E0B)' },
  ];
  // Mobile app bullet points
  mobileFeatures = [
    'Receive real-time notifications, reminders, and updates directly on your device.',
    'From onboarding to compliance training and skill development, everything is within reach.',
    'Progress syncs seamlessly across all devices for a unified learning experience.',
    'Download courses for offline access - learn anytime, anywhere, even without internet.',
  ];

  // From document: Boost Business Productivity
  productivityItems = [
    { icon: 'bi-rocket-takeoff', title: 'Faster Employee Onboarding', desc: 'Get new hires up to speed quickly with structured learning paths.' },
    { icon: 'bi-arrow-up-circle', title: 'Continuous Upskilling', desc: 'Equip your workforce with relevant skills to adapt and grow.' },
    { icon: 'bi-share', title: 'Standardized Knowledge Sharing', desc: 'Ensure everyone follows the same processes and best practices.' },
    { icon: 'bi-piggy-bank', title: 'Reduce Training Costs', desc: 'Eliminate repetitive in-person training with scalable digital learning.' },
  ];

  // Customer Training
  customerItems = [
    { icon: 'bi-signpost-split', title: 'Structured Customer Onboarding', desc: 'Guide users through your product with step-by-step training.' },
    { icon: 'bi-globe2', title: 'Self-Service Learning Portals', desc: 'Give customers 24/7 access to learning resources.' },
    { icon: 'bi-headset', title: 'Reduce Support Costs', desc: 'Answer common questions through training instead of support tickets.' },
    { icon: 'bi-currency-dollar', title: 'Monetize Training Programs', desc: 'Create and sell premium courses or certifications.' },
  ];

  // Compliance
  complianceItems = [
    { icon: 'bi-clipboard-check', title: 'Automated Compliance Training', desc: 'Assign mandatory courses based on roles, departments, or locations.' },
    { icon: 'bi-graph-up', title: 'Track & Prove Compliance', desc: 'Monitor completion rates, generate audit-ready reports.' },
    { icon: 'bi-award', title: 'Certification Management', desc: 'Set expiry dates, automate renewal reminders.' },
    { icon: 'bi-people', title: 'Standardized Training', desc: 'Consistent delivery of policies and safety training across teams.' },
    { icon: 'bi-shield-check', title: 'Reduce Risk', desc: 'Minimize compliance risks while fostering accountability.' },
  ];

  // Business Growth
  growthItems = [
    { icon: 'bi-diagram-3', title: 'Partner & Channel Training', desc: 'Ensure distributors and partners represent your brand effectively.' },
    { icon: 'bi-bullseye', title: 'Improve Performance', desc: 'Align training with business goals and KPIs.' },
    { icon: 'bi-bar-chart-line', title: 'Data-Driven Decisions', desc: 'Use insights from learning analytics to optimize performance.' },
    { icon: 'bi-arrows-angle-expand', title: 'Scale Without Limits', desc: 'Expand training across regions, teams, and audiences.' },
  ];

    // FAQ from document
  faqs = [
    { q: 'What is Central LMS?', a: 'Central LMS is a comprehensive, AI-powered learning management system designed to help organizations create, deliver, manage, and monetize training programs. It provides a centralized platform for learning, combining enterprise-grade capabilities with a modern, user-friendly experience.' },
    { q: 'Who is Central LMS designed for?', a: 'Central LMS is designed for corporate organizations, training institutions, and businesses seeking to train employees, customers, and partners. It supports multiple user categories, including internal staff, contractors, and external learners.' },
    { q: 'How is Central LMS different from other platforms?', a: 'While platforms such as Thinkific focus primarily on individual course creators, Central LMS is built for enterprise use. It combines ease of course creation with advanced features such as compliance tracking, AI-driven insights, unlimited user licensing options, and seamless integration with business systems.' },
    { q: 'Can I sell courses online?', a: 'Yes. Central LMS includes integrated eCommerce functionality that allows organizations to monetize their training programs. Users can sell courses, manage payments, offer discounts, and support multiple currencies without relying on external platforms.' },
    { q: 'Does Central LMS support mobile learning?', a: 'Yes. The platform is fully accessible on mobile devices with a dedicated mobile app, allowing learners to access training content anytime and anywhere. Progress is synchronized across devices for a seamless learning experience.' },
    { q: 'Can the platform be customized to match our brand?', a: 'Yes. Central LMS offers full customization and white-labeling capabilities, allowing organizations to tailor the platform\u2019s appearance and user experience to reflect their brand identity.' },
    { q: 'How long does it take to implement Central LMS?', a: 'Implementation can typically be completed within 25 working days, covering system setup, customization, integration, testing, and user training.' },
    { q: 'Is our user data secure on Central LMS?', a: 'Yes. Central LMS includes enterprise-grade security features such as role-based access control, secure authentication, configurable password policies, and data protection measures to ensure the safety and integrity of user data.' },
    { q: 'Can we migrate existing training content into Central LMS?', a: 'Yes. The platform supports content migration, including SCORM and AICC courses, as well as conversion of existing materials into interactive eLearning formats.' },
    { q: 'Can our instance be hosted on our domain?', a: 'Yes, Central LMS can be hosted on your organization\u2019s domain (e.g., lms.yourcompany.com). This allows seamless branding and access for your users.' },
  ];

  toggleFaq(i: number) { this.openFaq = this.openFaq === i ? null : i; }

  ngOnInit() {
    this.titleService.setTitle('Central LMS – Learning Management System in Africa | Corporate Training Nigeria');
    this.metaService.updateTag({ name: 'description', content: 'Central LMS is a powerful learning management system in Africa designed for corporate training, employee onboarding, and eLearning. Accessible in Nigeria and across Africa with cloud and on-premise deployment.' });
    this.metaService.updateTag({ name: 'keywords', content: 'learning management system Africa, LMS Nigeria, corporate training software Africa, eLearning platform Nigeria, employee training LMS Africa, onboarding software Africa, LMS SaaS Africa' });

    setTimeout(() => this.heroVisible = true, 200); }
  ngAfterViewInit() {
    this.observer = new IntersectionObserver(entries => {
      entries.forEach(e => { if (e.isIntersecting) { const id = e.target.getAttribute('data-section'); if (id) this.sv[id] = true; } });
    }, { threshold: 0.1 });
    setTimeout(() => document.querySelectorAll('[data-section]').forEach(el => this.observer.observe(el)), 100);
  }
  ngOnDestroy() { this.observer?.disconnect(); }
}
