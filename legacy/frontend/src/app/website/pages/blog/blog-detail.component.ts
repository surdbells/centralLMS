// website/pages/blog/blog-detail.component.ts
import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { NavbarComponent } from '../../shared/navbar/navbar.component';
import { FooterComponent } from '../../shared/footer/footer.component';

interface BlogPost {
  slug: string; title: string; excerpt: string; category: string; categoryColor: string;
  date: string; readTime: string; author: string; authorRole: string; authorInitials: string;
  gradient: string; content: string[];
}

@Component({
  selector: 'ws-blog-detail', standalone: true,
  imports: [CommonModule, RouterLink, NavbarComponent, FooterComponent],
  styleUrls: ['../../website.scss'],
  template: `
    <div class="website-shell"><div class="grain-overlay"></div><ws-navbar></ws-navbar>

    <div *ngIf="post">
      <!-- Hero banner -->
      <section class="article-hero" [style.background]="'#000000'">
        <div class="ws-container">
          <a routerLink="/blog" class="back-link"><i class="bi bi-arrow-left me-1"></i> All Articles</a>
          <span class="article-cat" [style.background]="'rgba(255,255,255,0.15)'">{{ post.category }}</span>
          <h1>{{ post.title }}</h1>
          <div class="article-meta-row">
            <div class="article-author-pill">
              <div class="avatar-sm">{{ post.authorInitials }}</div>
              <div><strong>{{ post.author }}</strong><span>{{ post.authorRole }}</span></div>
            </div>
            <span>{{ post.date }}</span>
            <span>{{ post.readTime }}</span>
          </div>
        </div>
      </section>

      <!-- Article body -->
      <section style="padding:3rem 0 5rem;">
        <div class="ws-container">
          <div class="article-layout">
            <article class="article-content">
              <p *ngFor="let para of post.content" [innerHTML]="para" class="article-para"></p>

              <!-- Share + Tags -->
              <div class="article-footer">
                <div class="article-tags">
                  <span class="atag" [style.background]="post.categoryColor + '12'" [style.color]="post.categoryColor">{{ post.category }}</span>
                  <span class="atag">LMS</span>
                  <span class="atag">Enterprise</span>
                </div>
                <div class="article-share">
                  <span style="font-size:0.82rem; color:var(--c-text-muted); margin-right:0.5rem;">Share:</span>
                  <a href="#" class="share-btn"><i class="bi bi-linkedin"></i></a>
                  <a href="#" class="share-btn"><i class="bi bi-twitter-x"></i></a>
                  <a href="#" class="share-btn"><i class="bi bi-facebook"></i></a>
                  <a href="#" class="share-btn"><i class="bi bi-link-45deg"></i></a>
                </div>
              </div>
            </article>

            <!-- Sidebar -->
            <aside class="article-sidebar">
              <div class="sidebar-card">
                <h5>About the Author</h5>
                <div style="display:flex; gap:0.75rem; align-items:center;">
                  <div class="avatar-sm" style="width:48px; height:48px; font-size:0.9rem;">{{ post.authorInitials }}</div>
                  <div><strong style="font-size:0.92rem;">{{ post.author }}</strong><p style="font-size:0.78rem; color:var(--c-text-muted); margin:0;">{{ post.authorRole }}</p></div>
                </div>
              </div>
              <div class="sidebar-card">
                <h5>Related Articles</h5>
                <a *ngFor="let r of relatedPosts" [routerLink]="['/blog', r.slug]" class="related-link">
                  <span class="related-cat" [style.color]="r.categoryColor">{{ r.category }}</span>
                  <span class="related-title">{{ r.title }}</span>
                </a>
              </div>
              <div class="sidebar-card sidebar-cta">
                <h5>Ready to get started?</h5>
                <p style="font-size:0.85rem; color:var(--c-text-secondary); margin:0.5rem 0 1rem;">See how Central LMS can transform your training program.</p>
                <a routerLink="/onboarding" class="btn-hero primary sm" style="width:100%; justify-content:center;">Sign Up</a>
                <a routerLink="/book-demo" class="btn-hero outline sm" style="width:100%; justify-content:center; margin-top:0.5rem;">Book a Demo</a>
              </div>
            </aside>
          </div>
        </div>
      </section>
    </div>

    <!-- 404 -->
    <div *ngIf="!post && loaded" style="padding:12rem 2rem 6rem; text-align:center;">
      <i class="bi bi-journal-x" style="font-size:3rem; color:var(--c-text-muted);"></i>
      <h2 style="margin:1rem 0 0.5rem;">Article Not Found</h2>
      <p style="color:var(--c-text-muted);">The article you're looking for doesn't exist.</p>
      <a routerLink="/blog" class="btn-hero primary" style="margin-top:1.5rem;">Browse All Articles</a>
    </div>

    <ws-footer></ws-footer></div>
  `,
  styles: [`
    .article-hero { padding: 9rem 0 3rem; color: #fff; position: relative; }
    .back-link { color: rgba(255,255,255,0.65); text-decoration: none; font-size: 0.85rem; display: inline-flex; align-items: center; margin-bottom: 1.5rem; &:hover { color: #fff; } }
    .article-cat { display: inline-block; padding: 0.2rem 0.75rem; border-radius: var(--radius-full); font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: #fff; margin-bottom: 1rem; }
    .article-hero h1 { font-family: var(--font-display); font-size: clamp(1.75rem, 4vw, 2.5rem); font-weight: 700; line-height: 1.2; max-width: 700px; margin-bottom: 1.5rem; }
    .article-meta-row { display: flex; align-items: center; gap: 1.5rem; flex-wrap: wrap; font-size: 0.85rem; color: rgba(255,255,255,0.65); }
    .article-author-pill { display: flex; align-items: center; gap: 0.625rem; strong { display: block; color: #fff; font-size: 0.88rem; } span { font-size: 0.75rem; color: rgba(255,255,255,0.5); } }
    .avatar-sm { width: 36px; height: 36px; border-radius: 50%; background: rgba(255,255,255,0.2); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.78rem; flex-shrink: 0; }

    .article-layout { display: grid; grid-template-columns: 1fr 320px; gap: 3rem; align-items: start; }
    .article-content { max-width: 100%; }
    .article-para { font-size: 1.05rem; line-height: 1.85; color: var(--c-text); margin-bottom: 1.5rem; }
    .article-footer { display: flex; justify-content: space-between; align-items: center; padding-top: 2rem; border-top: 1px solid var(--c-border-light); margin-top: 2rem; flex-wrap: wrap; gap: 1rem; }
    .article-tags { display: flex; gap: 0.375rem; }
    .atag { padding: 0.2rem 0.625rem; border-radius: var(--radius-full); font-size: 0.72rem; font-weight: 600; background: var(--c-bg-alt); color: var(--c-text-muted); }
    .article-share { display: flex; align-items: center; }
    .share-btn { width: 32px; height: 32px; border-radius: 50%; border: 1px solid var(--c-border-light); display: flex; align-items: center; justify-content: center; color: var(--c-text-muted); text-decoration: none; margin-left: 0.375rem; transition: all 0.2s; &:hover { border-color: var(--c-primary); color: var(--c-primary); background: var(--c-primary-lighter); } }

    .article-sidebar { position: sticky; top: 5rem; }
    .sidebar-card { padding: 1.25rem; background: var(--c-surface); border: 1px solid var(--c-border-light); border-radius: var(--radius-md); margin-bottom: 1rem; h5 { font-size: 0.85rem; font-weight: 600; margin-bottom: 0.75rem; } }
    .sidebar-cta { background: var(--c-primary-lighter); border-color: rgba(10,79,42,0.1); }
    .related-link { display: block; padding: 0.625rem 0; border-bottom: 1px solid var(--c-border-light); text-decoration: none; &:last-child { border-bottom: none; } &:hover .related-title { color: var(--c-primary); } }
    .related-cat { font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; }
    .related-title { display: block; font-size: 0.88rem; font-weight: 500; color: var(--c-text); margin-top: 0.2rem; line-height: 1.4; transition: color 0.2s; }

    @media (max-width: 991px) { .article-layout { grid-template-columns: 1fr; } .article-sidebar { position: static; } }
  `]
})
export class BlogDetailComponent implements OnInit {
  post: BlogPost | null = null;
  relatedPosts: BlogPost[] = [];
  loaded = false;

  private allPosts: BlogPost[] = [
    { slug: 'scalable-learning-tech-africa', title: 'Why Scalable Learning Technology is Essential for African Enterprises', excerpt: '', category: 'Learning Technology', categoryColor: '#0A4F2A', date: 'Mar 28, 2026', readTime: '6 min read', author: 'Seyi Adekoya', authorRole: 'Chief Learning Officer', authorInitials: 'AO', gradient: 'linear-gradient(135deg,#0A4F2A,#0D9488)',
      content: ['As organisations evolve across Africa, the need for scalable learning technology has become essential. Traditional classroom methods no longer meet the demands of a modern, distributed workforce that requires flexibility, accessibility, personalisation, and measurable impact.','The pace of digital transformation, particularly in Nigeria, Kenya, South Africa, and Ghana, has created a growing skills gap. Companies that fail to invest in continuous, technology-driven learning risk falling behind competitors who are already leveraging LMS platforms to onboard faster, train smarter, and retain talent.','Enterprise learning management systems like Central LMS provide organisations with the tools to deliver consistent training across multiple locations, languages, and time zones. With features like SCORM compliance, automated compliance tracking, and real-time analytics, L&D teams can demonstrate measurable ROI on their training investments.','The rise of mobile-first learning is especially significant in African markets, where smartphone penetration outpaces desktop usage. Platforms that offer native mobile apps with offline access, push notifications, and seamless progress syncing are seeing dramatically higher engagement rates.','Looking ahead, AI-powered personalisation and adaptive learning paths will further transform how African enterprises approach workforce development. The organisations that invest in scalable learning infrastructure today will be best positioned to compete in the rapidly evolving global marketplace.'] },
    { slug: 'scorm-compliance-guide', title: 'The Complete Guide to SCORM Compliance in Enterprise LMS', excerpt: '', category: 'Technical', categoryColor: '#0284C7', date: 'Mar 15, 2026', readTime: '8 min read', author: 'Seyi Adekoya', authorRole: 'CTO, Kodek Innovations', authorInitials: 'SA', gradient: 'linear-gradient(135deg,#0284C7,#7C3AED)',
      content: ['SCORM (Sharable Content Object Reference Model) remains the gold standard for eLearning interoperability. If you\'re deploying an enterprise LMS, understanding SCORM compliance isn\'t optional \u2014 it\'s the foundation of your content strategy.','<strong>SCORM 1.2 vs SCORM 2004:</strong> SCORM 1.2 is simpler and widely supported, tracking basic completion status and scores. SCORM 2004 (4th Edition) adds sequencing, navigation rules, and richer data tracking through CMI data model extensions.','<strong>xAPI (Tin Can) \u2014 The Next Generation:</strong> xAPI extends beyond SCORM by tracking any learning experience, not just packaged content. It uses statement-based tracking (Actor-Verb-Object) that captures informal learning, mobile interactions, and real-world activities.','For enterprise deployments, your LMS should support all three standards simultaneously. Central LMS provides a unified content player that automatically detects the content type and applies the appropriate tracking wrapper \u2014 no manual configuration required.','<strong>Implementation tips:</strong> Always validate SCORM packages before deployment using tools like SCORM Cloud or Rustici. Ensure your LMS stores the complete CMI data model including lesson_status, score.raw, session_time, and suspend_data for proper resume functionality.','The key takeaway: invest in an LMS that handles the complexity of content standards for you, so your team can focus on creating impactful learning experiences rather than debugging XML manifests.'] },
    { slug: 'gamification-strategies', title: '5 Gamification Strategies That Actually Boost Training Completion', excerpt: '', category: 'Engagement', categoryColor: '#C9A227', date: 'Mar 5, 2026', readTime: '5 min read', author: 'Seyi Adekoya', authorRole: 'L&D Specialist', authorInitials: 'AO', gradient: 'linear-gradient(135deg,#C9A227,#F59E0B)',
      content: ['Gamification isn\'t about turning training into a game. It\'s about applying proven psychological principles to make learning inherently rewarding. Here are five strategies that deliver real results based on data from 500+ organisations.','<strong>1. Progressive Point Systems:</strong> Award points for course completion (100), assessment passes (50), daily login streaks (10), and peer collaboration (25). The key is making the point values transparent and meaningful. Central LMS users who implemented points saw a 73% increase in voluntary course enrollment.','<strong>2. Achievement Badges That Tell a Story:</strong> Don\'t just create generic badges. Design badge sequences that represent career development narratives. A "Compliance Champion" badge series that progresses from Bronze to Platinum gives learners a visible growth path. Our data shows badge collectors complete 2.4x more courses than non-collectors.','<strong>3. Team-Based Leaderboards:</strong> Individual leaderboards can demotivate lower performers. Instead, implement department or team leaderboards that encourage collaborative learning. Teams in the top quartile show 89% higher compliance training completion rates.','<strong>4. Streak Mechanics with Multipliers:</strong> Daily learning streaks with increasing point multipliers (1.5x after 7 days, 2x after 30 days) create powerful habits. The dropout rate decreases by 60% once a learner maintains a 14-day streak.','<strong>5. Certificates as Social Currency:</strong> Auto-generated certificates with QR verification codes become shareable credentials. When learners can add certificates to LinkedIn or internal profiles, completion rates for certification courses increase by 340%. This social proof loop creates organic demand for training.'] },
    { slug: 'compliance-training-nigeria', title: 'Building a Compliance Training Program for Nigerian Financial Institutions', excerpt: '', category: 'Compliance', categoryColor: '#DC2626', date: 'Feb 20, 2026', readTime: '7 min read', author: 'Seyi Adekoya', authorRole: 'Risk & Compliance Analyst', authorInitials: 'TA', gradient: 'linear-gradient(135deg,#DC2626,#B91C1C)',
      content: ['Nigerian financial institutions face a complex regulatory landscape governed by the CBN, SEC, NDIC, NAICOM, and FCCPC. A robust compliance training program isn\'t just best practice \u2014 it\'s a regulatory mandate.','The key compliance domains for Nigerian banks include Know Your Customer (KYC), Anti-Money Laundering (AML), the Nigeria Data Protection Regulation (NDPR), and cybersecurity awareness. Each requires mandatory training with documented completion records and periodic recertification.','An effective LMS-driven compliance program should automate course assignments based on employee roles and departments, track completion with audit-ready reporting, manage certification lifecycles with automatic renewal reminders, and provide real-time compliance dashboards for management oversight.','Central LMS enables financial institutions to configure compliance rules that automatically assign the right training to the right employees at the right time. When CBN issues new guidelines, administrators can push updated training to all affected staff within hours \u2014 not weeks.','The ROI is compelling: organisations using automated compliance training report 65% reduction in compliance-related incidents and save an average of 40% on training administration costs compared to manual processes.'] },
    { slug: 'lms-roi-measurement', title: 'How to Measure ROI on Your Learning Management System Investment', excerpt: '', category: 'Best Practices', categoryColor: '#059669', date: 'Feb 10, 2026', readTime: '6 min read', author: 'Seyi Adekoya', authorRole: 'HR Director', authorInitials: 'FA', gradient: 'linear-gradient(135deg,#059669,#10B981)',
      content: ['Demonstrating the return on investment for an LMS goes beyond tracking course completions. Stakeholders want to see how learning technology impacts business metrics: revenue, productivity, retention, and compliance costs.','The Kirkpatrick Model provides a useful framework: Level 1 (Reaction \u2014 learner satisfaction), Level 2 (Learning \u2014 knowledge gains), Level 3 (Behavior \u2014 on-the-job application), and Level 4 (Results \u2014 business impact). Your LMS analytics should map to all four levels.','Key metrics to track include: time-to-productivity for new hires (target: 30-50% reduction), compliance training completion rates (target: 95-100%), voluntary course enrollment rates (indicates engagement), and support ticket reduction after customer training deployment.','Central LMS provides 127+ built-in reports that automatically track these metrics across the organisation. The executive dashboard shows real-time KPIs including training spend per employee, completion trends, compliance status, and department-level performance comparisons.','When presenting ROI to leadership, focus on cost avoidance (reduced compliance fines, fewer onboarding errors), efficiency gains (hours saved in training administration), and revenue impact (faster sales ramp-up, partner certification programs). A well-implemented LMS typically delivers 3-5x ROI within the first 18 months.'] },
    { slug: 'proctored-assessments-guide', title: 'AI-Powered Proctoring: A Complete Guide for Enterprise Assessments', excerpt: '', category: 'Technical', categoryColor: '#0284C7', date: 'Jan 28, 2026', readTime: '10 min read', author: 'Seyi Adekoya', authorRole: 'CTO, Kodek Innovations', authorInitials: 'SA', gradient: 'linear-gradient(135deg,#0284C7,#0EA5E9)',
      content: ['As remote work and distributed teams become the norm, ensuring assessment integrity requires technology that goes beyond honor systems. AI-powered proctoring provides the security enterprises need without the friction of in-person invigilation.','Modern proctoring systems monitor multiple signals: webcam feeds for face detection and eye tracking, browser activity for tab switching and copy-paste attempts, and system-level controls for fullscreen enforcement and application blocking.','The concept of a Trust Score aggregates these signals into a single confidence metric. Central LMS calculates trust scores based on 30+ violation types, each weighted by severity. A minor tab switch might reduce the score by 2 points, while a face-not-detected event could trigger a 15-point deduction.','For enterprise deployments, the key is configuring appropriate thresholds. A high-stakes certification exam might require a 90% trust score, while a routine knowledge check might accept 70%. The flexibility to configure these per assessment is critical.','Implementation considerations include bandwidth requirements (webcam streaming), browser compatibility (Chrome and Edge offer the best proctoring API support), accessibility accommodations (configurable time extensions, alternative proctoring modes), and data privacy compliance (GDPR/NDPR requirements for webcam image retention policies).','The result is a proctoring system that maintains assessment integrity while respecting learner experience \u2014 secure enough for compliance certifications, flexible enough for daily knowledge checks.'] },
    { slug: 'oil-gas-training', title: 'HSE Training in Oil & Gas: Meeting NUPRC and DPR Requirements', excerpt: '', category: 'Industry Trends', categoryColor: '#D97706', date: 'Jan 15, 2026', readTime: '7 min read', author: 'Seyi Adekoya', authorRole: 'HSE Manager', authorInitials: 'EN', gradient: 'linear-gradient(135deg,#D97706,#F59E0B)',
      content: ['The Nigerian upstream and downstream petroleum sectors are governed by stringent Health, Safety, and Environment (HSE) requirements from NUPRC (formerly DPR). Training compliance is not optional \u2014 it\'s a condition of operating licenses.','Key HSE training requirements include: Permit to Work (PTW) certification, hydrogen sulfide (H2S) awareness, fire safety and emergency response, working at heights, confined space entry, and personal protective equipment (PPE) usage. Each requires initial certification and periodic recertification.','An LMS-driven approach transforms HSE training from a logistical nightmare into a managed process. Central LMS enables oil and gas operators to assign role-specific safety training based on job classifications, track certification expiry dates with automated 30/60/90-day reminders, generate NUPRC-compliant training records for regulatory audits, and deliver refresher training through mobile devices even in remote field locations.','The mobile-first approach is especially valuable for upstream operations where workers may be on offshore platforms or remote drilling sites. With offline course access and automatic sync when connectivity is restored, training gaps are eliminated regardless of location.','Companies using LMS-managed HSE programs report 45% fewer safety incidents and 100% audit compliance rates \u2014 compared to 72% average compliance with manual training tracking systems.'] },
    { slug: 'blended-learning-2026', title: 'Blended Learning in 2026: Combining Virtual, Physical, and Self-Paced', excerpt: '', category: 'Learning Technology', categoryColor: '#0A4F2A', date: 'Jan 5, 2026', readTime: '6 min read', author: 'Seyi Adekoya', authorRole: 'Learning Architect', authorInitials: 'KO', gradient: 'linear-gradient(135deg,#0A4F2A,#065F46)',
      content: ['The most effective corporate training programs in 2026 don\'t choose between virtual and physical \u2014 they combine both with self-paced digital learning in a blended approach that maximises engagement and knowledge retention.','A well-designed blended program follows the 70-20-10 model: 70% learning through on-the-job application, 20% through social interaction and coaching, and 10% through formal coursework. Your LMS should support all three dimensions.','Central LMS enables blended learning through three integrated modules: self-paced courses (SCORM, video, interactive), live training sessions (physical with QR attendance, virtual via Zoom/Teams/Meet, or hybrid), and learning paths that sequence both modalities into structured programs.','The data shows that blended programs outperform single-modality approaches: 94% higher knowledge retention compared to classroom-only, 37% faster skill application compared to eLearning-only, and 2.3x higher learner satisfaction scores when combining formats.','The key to successful blended learning is seamless tracking across modalities. When a learner completes an online pre-work module, attends a virtual workshop, and then passes an in-person practical assessment, all of that should flow into a single progress view \u2014 not three disconnected systems.'] },
    { slug: 'onboarding-automation', title: 'Automating Employee Onboarding: From Day 1 to Productive in Half the Time', excerpt: '', category: 'Best Practices', categoryColor: '#059669', date: 'Dec 5, 2025', readTime: '5 min read', author: 'Seyi Adekoya', authorRole: 'People Ops Lead', authorInitials: 'BA', gradient: 'linear-gradient(135deg,#059669,#34D399)',
      content: ['The average new hire takes 8-12 months to reach full productivity. With automated LMS-driven onboarding, that timeline can be cut to 4-6 months \u2014 a 73% improvement that directly impacts your bottom line.','The secret is structured learning paths that automatically assign role-specific training from day one. When HR creates a new user in Central LMS, the system can automatically enrol them in their department\'s onboarding program, assign mandatory compliance training, schedule live orientation sessions, and set milestone checkpoints with manager notifications.','A well-designed onboarding path typically includes: Week 1 (company culture, policies, systems access), Week 2-3 (role-specific training, product knowledge), Month 1 (compliance certifications, team integration), and Month 2-3 (advanced skills, performance benchmarks).','The measurable impact: organisations using automated onboarding report 54% higher new-hire retention at 12 months, 73% faster time-to-productivity, and 82% reduction in HR administrative time spent on training coordination.','Central LMS tracks the entire onboarding journey with real-time dashboards showing where each new hire stands, which modules are completed, and which deadlines are approaching \u2014 giving managers and HR complete visibility without manual follow-up.'] },
  ];

  constructor(private route: ActivatedRoute) {}

  ngOnInit() {
    const slug = this.route.snapshot.paramMap.get('slug');
    this.post = this.allPosts.find(p => p.slug === slug) || null;
    this.relatedPosts = this.allPosts.filter(p => p.slug !== slug).slice(0, 3);
    this.loaded = true;
  }
}