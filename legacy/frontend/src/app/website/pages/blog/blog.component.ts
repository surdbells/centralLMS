// website/pages/blog/blog.component.ts
import { Component, OnInit } from '@angular/core';
import { Title, Meta } from '@angular/platform-browser';
import { CommonModule } from '@angular/common';
import { RouterLink } from '@angular/router';
import { FormsModule } from '@angular/forms';
import { NavbarComponent } from '../../shared/navbar/navbar.component';
import { FooterComponent } from '../../shared/footer/footer.component';

interface BlogPost {
  id: number; slug: string; title: string; excerpt: string; category: string;
  categoryColor: string; date: string; readTime: string; author: string;
  authorRole: string; authorInitials: string; gradient: string; featured?: boolean;
}

@Component({
  selector: 'ws-blog',
  standalone: true,
  imports: [CommonModule, RouterLink, FormsModule, NavbarComponent, FooterComponent],
  styleUrls: ['../../website.scss'],
  template: `
    <div class="website-shell">
      <div class="grain-overlay"></div>
      <ws-navbar></ws-navbar>

      <!-- Page Header -->
      <section style="padding: 8rem 0 3rem; background: var(--c-bg-alt); border-bottom: 1px solid var(--c-border-light);">
        <div class="ws-container">
          <span class="eyebrow">Insights & Resources</span>
          <h1 class="display-md" style="margin-top: 0.5rem;">eLearning Insights and Corporate Training Trends in Africa</h1>
          <p class="body-lg" style="margin-top: 0.75rem;">
            Stay Updated with LMS Best Practices and Training Strategies for African Enterprises. <br>
            Explore our expert articles on learning technology, compliance, engagement, and industry trends to elevate your L&D strategy.
          </p>
          <!-- Search + Categories -->
          <div style="display: flex; gap: 1rem; margin-top: 2rem; flex-wrap: wrap; align-items: center;">
            <div style="position: relative; flex: 1; max-width: 360px;">
              <i class="bi bi-search" style="position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--c-text-muted);font-size:0.9rem;"></i>
              <input type="text" [(ngModel)]="searchQuery" placeholder="Search articles..."
                     style="width:100%;padding:0.625rem 1rem 0.625rem 2.5rem;border:1px solid var(--c-border);border-radius:var(--radius-full);font-size:0.9rem;background:var(--c-surface);outline:none;font-family:var(--font-body);"
                     (input)="filterPosts()" />
            </div>
            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
              <button *ngFor="let cat of categories" (click)="activeCategory = cat; filterPosts()"
                      class="cat-btn" [class.active]="activeCategory === cat">{{ cat }}</button>
            </div>
          </div>
        </div>
      </section>

      <!-- Featured Post -->
      <section class="ws-section" style="padding-top: 3rem;" *ngIf="!searchQuery && activeCategory === 'All'">
        <div class="ws-container">
          <div class="featured-post">
            <div class="featured-img" [style.background]="featuredPost.gradient"></div>
            <div class="featured-body">
              <span class="blog-tag" [style.color]="featuredPost.categoryColor">{{ featuredPost.category }}</span>
              <h2 style="font-family: var(--font-display); font-size: 1.75rem; font-weight: 700; margin: 0.75rem 0; line-height: 1.25;">
                {{ featuredPost.title }}
              </h2>
              <p style="color: var(--c-text-secondary); line-height: 1.7; margin-bottom: 1.5rem;">{{ featuredPost.excerpt }}</p>
              <div style="display: flex; align-items: center; gap: 0.75rem;">
                <div class="testimonial-avatar" style="width:36px;height:36px;font-size:0.75rem;">{{ featuredPost.authorInitials }}</div>
                <div>
                  <strong style="font-size:0.85rem;">{{ featuredPost.author }}</strong>
                  <span style="display:block;font-size:0.75rem;color:var(--c-text-muted);">{{ featuredPost.date }} &bull; {{ featuredPost.readTime }}</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- Posts Grid -->
      <section class="ws-section" [style.paddingTop]="(!searchQuery && activeCategory === 'All') ? '0' : '3rem'">
        <div class="ws-container">
          <div class="blog-listing-grid">
            <a [routerLink]="['/blog', post.slug]" class="blog-card" *ngFor="let post of filteredPosts">
              <div class="blog-img" [style.background]="post.gradient"></div>
              <div class="blog-body">
                <span class="blog-tag" [style.color]="post.categoryColor">{{ post.category }}</span>
                <h4>{{ post.title }}</h4>
                <p>{{ post.excerpt }}</p>
                <div class="blog-meta">
                  <span>{{ post.author }}</span>
                  <span>{{ post.date }}</span>
                  <span>{{ post.readTime }}</span>
                </div>
              </div>
            </a>
          </div>
          <div *ngIf="!filteredPosts.length" style="text-align:center; padding:4rem 0;">
            <i class="bi bi-search" style="font-size:2.5rem; color:var(--c-text-muted); display:block; margin-bottom:1rem;"></i>
            <p style="color:var(--c-text-muted);">No articles found matching your search.</p>
          </div>
        </div>
      </section>

      <!-- Newsletter CTA -->
      <section class="ws-section ws-section--alt" style="padding: 4rem 0;">
        <div class="ws-container" style="max-width:680px; text-align:center;">
          <h3 class="display-md">Stay Ahead on L&D Trends</h3>
          <p class="body-md" style="margin: 0.75rem 0 1.5rem;">Join 10,000+ learning professionals. Weekly insights, no spam.</p>
          <div style="display:flex; gap:0.5rem; max-width:440px; margin:0 auto;">
            <input type="email" placeholder="Your email address"
                   style="flex:1;padding:0.75rem 1rem;border:1px solid var(--c-border);border-radius:var(--radius-full);font-size:0.9rem;outline:none;font-family:var(--font-body);" />
            <button class="btn-hero primary">Subscribe</button>
          </div>
        </div>
      </section>

      <ws-footer></ws-footer>
    </div>
  `,
  styles: [`
    .cat-btn {
      padding: 0.4rem 1rem; border-radius: var(--radius-full); font-size: 0.82rem; font-weight: 500;
      background: var(--c-surface); border: 1px solid var(--c-border); color: var(--c-text-secondary);
      cursor: pointer; transition: all 0.2s; font-family: var(--font-body);
      &:hover { border-color: var(--c-primary); color: var(--c-primary); }
      &.active { background: var(--c-primary); border-color: var(--c-primary); color: #fff; }
    }
    .featured-post {
      display: grid; grid-template-columns: 1.2fr 1fr; gap: 0; border-radius: var(--radius-lg);
      overflow: hidden; background: var(--c-surface); border: 1px solid var(--c-border-light);
      transition: all 0.4s var(--ease-out);
      &:hover { box-shadow: var(--shadow-lg); }
    }
    .featured-img { min-height: 320px; }
    .featured-body { padding: 2.5rem; display: flex; flex-direction: column; justify-content: center; }
    .blog-listing-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.5rem; }
    .blog-card {
      background: var(--c-surface); border: 1px solid var(--c-border-light); border-radius: var(--radius-lg);
      overflow: hidden; text-decoration: none; color: inherit; transition: all 0.4s var(--ease-out);
      &:hover { transform: translateY(-6px); box-shadow: var(--shadow-lg); }
    }
    .blog-img { height: 180px; }
    .blog-body { padding: 1.5rem; }
    .blog-tag { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; }
    .blog-card h4 { font-family: var(--font-display); font-size: 1.1rem; font-weight: 600; margin: 0.5rem 0; line-height: 1.35; color: var(--c-text); }
    .blog-card p { font-size: 0.85rem; color: var(--c-text-secondary); line-height: 1.6; margin: 0 0 1rem; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
    .blog-meta { display: flex; gap: 1rem; font-size: 0.75rem; color: var(--c-text-muted); }
    .testimonial-avatar { width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,var(--c-primary),var(--c-primary-light));color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:0.82rem;flex-shrink:0; }
    @media (max-width: 991px) { .featured-post { grid-template-columns: 1fr; } .featured-img { min-height: 220px; } }
    @media (max-width: 767px) { .blog-listing-grid { grid-template-columns: 1fr; } }
  `]
})
export class BlogComponent implements OnInit {
    constructor(
    private titleService: Title, 
    private metaService: Meta
  ) {}
  searchQuery = '';
  activeCategory = 'All';
  categories = ['All', 'Learning Technology', 'Compliance', 'Engagement', 'Technical', 'Industry Trends', 'Best Practices'];

  featuredPost: BlogPost = {
    id: 0, slug: 'scalable-learning-tech-africa', featured: true,
    title: 'Why Scalable Learning Technology is No Longer Optional for African Enterprises',
    excerpt: 'As organisations evolve, the need for scalable learning technology has become essential. Traditional methods no longer meet the demands of a modern, distributed workforce that requires flexibility, accessibility, personalisation, and measurable impact. Learn how leading African enterprises are transforming their L&D strategy with technology.',
    category: 'Learning Technology', categoryColor: '#0A4F2A',
    date: 'Mar 28, 2026', readTime: '8 min read',
    author: 'Seyi Adekoya', authorRole: 'Chief Learning Officer', authorInitials: 'SA',
    gradient: 'linear-gradient(135deg, #0A4F2A 0%, #0D9488 100%)',
  };

  allPosts: BlogPost[] = [
    { id: 1, slug: 'scorm-compliance-guide', title: 'The Complete Guide to SCORM Compliance in Enterprise LMS', excerpt: 'Everything you need to know about SCORM 1.2, SCORM 2004, and xAPI standards for your learning content.', category: 'Technical', categoryColor: '#0284C7', date: 'Mar 15, 2026', readTime: '8 min', author: 'Seyi Adekoya', authorRole: 'CTO', authorInitials: 'SA', gradient: 'linear-gradient(135deg, #0284C7, #7C3AED)' },
    { id: 2, slug: 'gamification-strategies', title: '5 Gamification Strategies That Actually Boost Training Completion', excerpt: 'Data-driven approaches to points, badges, and leaderboards that transform learner engagement.', category: 'Engagement', categoryColor: '#C9A227', date: 'Mar 5, 2026', readTime: '5 min', author: 'Seyi Adekoya', authorRole: 'L&D Specialist', authorInitials: 'SA', gradient: 'linear-gradient(135deg, #C9A227, #F59E0B)' },
    { id: 3, slug: 'compliance-training-nigeria', title: 'Building a Compliance Training Program for Nigerian Financial Institutions', excerpt: 'CBN, NDPR, and KYC/AML requirements translated into actionable learning paths for your workforce.', category: 'Compliance', categoryColor: '#DC2626', date: 'Feb 20, 2026', readTime: '7 min', author: 'Seyi Adekoya', authorRole: 'Risk Analyst', authorInitials: 'SA', gradient: 'linear-gradient(135deg, #DC2626, #B91C1C)' },
    { id: 4, slug: 'lms-roi-measurement', title: 'How to Measure ROI on Your Learning Management System Investment', excerpt: 'Frameworks and metrics to demonstrate the business impact of your LMS to stakeholders and executives.', category: 'Best Practices', categoryColor: '#059669', date: 'Feb 10, 2026', readTime: '6 min', author: 'Seyi Adekoya', authorRole: 'HR Director', authorInitials: 'FA', gradient: 'linear-gradient(135deg, #059669, #10B981)' },
    { id: 5, slug: 'proctored-assessments-guide', title: 'AI-Powered Proctoring: A Complete Guide for Enterprise Assessments', excerpt: 'How to implement secure, fair online assessments with webcam monitoring, trust scoring, and automated grading.', category: 'Technical', categoryColor: '#0284C7', date: 'Jan 28, 2026', readTime: '10 min', author: 'Seyi Adekoya', authorRole: 'CTO', authorInitials: 'SA', gradient: 'linear-gradient(135deg, #0284C7, #0EA5E9)' },
    { id: 6, slug: 'oil-gas-training', title: 'HSE Training in Oil & Gas: Meeting NUPRC and DPR Requirements', excerpt: 'Best practices for deploying safety and compliance training across upstream and downstream operations.', category: 'Industry Trends', categoryColor: '#D97706', date: 'Jan 15, 2026', readTime: '7 min', author: 'Seyi Adekoya', authorRole: 'HSE Manager', authorInitials: 'EN', gradient: 'linear-gradient(135deg, #D97706, #F59E0B)' },
    { id: 7, slug: 'blended-learning-2026', title: 'Blended Learning in 2026: Combining Virtual, Physical, and Self-Paced', excerpt: 'The future of corporate training combines all modalities. Here is how leading organizations are getting it right.', category: 'Learning Technology', categoryColor: '#0A4F2A', date: 'Jan 5, 2026', readTime: '6 min', author: 'Seyi Adekoya', authorRole: 'Learning Architect', authorInitials: 'KO', gradient: 'linear-gradient(135deg, #0A4F2A, #065F46)' },
    { id: 8, slug: 'multi-tenant-lms-architecture', title: 'Multi-Tenant Architecture: How to Serve 1000 Organizations from One Platform', excerpt: 'Technical deep-dive into tenant isolation, shared database patterns, and scalable LMS architecture.', category: 'Technical', categoryColor: '#0284C7', date: 'Dec 18, 2025', readTime: '12 min', author: 'Seyi Adekoya', authorRole: 'CTO', authorInitials: 'SA', gradient: 'linear-gradient(135deg, #1E293B, #475569)' },
    { id: 9, slug: 'onboarding-automation', title: 'Automating Employee Onboarding: From Day 1 to Productive in Half the Time', excerpt: 'How learning paths, auto-assignments, and progress tracking cut onboarding time by 73%.', category: 'Best Practices', categoryColor: '#059669', date: 'Dec 5, 2025', readTime: '5 min', author: 'Seyi Adekoya', authorRole: 'People Ops Lead', authorInitials: 'BA', gradient: 'linear-gradient(135deg, #059669, #34D399)' },
  ];

  filteredPosts: BlogPost[] = [];

  ngOnInit(): void {
      this.filteredPosts = [...this.allPosts];
      this.titleService.setTitle('LMS Blog Africa | eLearning, Training & LMS Insights Africa');
      this.metaService.updateTag({ name: 'title', content: 'Central LMS Blog | eLearning Trends Africa & Training Insights' });
      this.metaService.updateTag({ name: 'description', content: 'Read the latest insights on eLearning, corporate training, employee development & training, HR tech, Compliance and LMS trends across Africa.' });
      this.metaService.updateTag({ name: 'keywords', content: 'LMS blog Africa, eLearning articles Nigeria, corporate training tips Africa, employee training insights, HR tech Africa' });

  }

  filterPosts() {
    let posts = [...this.allPosts];
    if (this.activeCategory !== 'All') {
      posts = posts.filter(p => p.category === this.activeCategory);
    }
    if (this.searchQuery.trim()) {
      const q = this.searchQuery.toLowerCase();
      posts = posts.filter(p => p.title.toLowerCase().includes(q) || p.excerpt.toLowerCase().includes(q) || p.category.toLowerCase().includes(q));
    }
    this.filteredPosts = posts;
  }
}