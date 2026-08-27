// website/shared/footer/footer.component.ts
import { Component } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterLink } from '@angular/router';
import { FormsModule } from '@angular/forms';

@Component({
  selector: 'ws-footer',
  standalone: true,
  imports: [CommonModule, RouterLink, FormsModule],
  template: `
    <footer class="ws-footer">
      <div class="ws-container">
        <div class="footer-grid">
          <div class="footer-brand-col">
            <p class="footer-desc">
              Empowering organizations across Africa and beyond with scalable, enterprise-grade learning technology that drives real business results.
            </p>
          </div>

          <div>
            <h6 class="footer-heading">Quick Links</h6>
            <ul class="footer-links">
              <li><a routerLink="/features">Features</a></li>
              <li><a routerLink="/pricing">Pricing</a></li>
<!--              <li><a routerLink="/content-marketplace">Marketplace</a></li>-->
             <li><a routerLink="/book-demo">Book a Demo</a></li>
<!--                <li><a href="#">API Documentation</a></li>
              <li><a href="#">Changelog</a></li> -->
            </ul>
          </div>

          <div>
            <h6 class="footer-heading">Company</h6>
            <ul class="footer-links">
<!--               <li><a href="#">About Us</a></li>
 -->              <li><a routerLink="/blog">Blog & Insights</a></li>
<!--               <li><a href="#">Case Studies</a></li>
              <li><a href="#">Careers</a></li> -->
              <li><a routerLink="/contact">Contact Us</a></li>
<!--               <li><a href="#">Partners</a></li>
 -->            </ul>
          </div>

     <div>
<!--             <div class="footer-socials">
              <a href="#"><i class="bi bi-linkedin"></i></a>
              <a href="#"><i class="bi bi-twitter-x"></i></a>
              <a href="#"><i class="bi bi-facebook"></i></a>
              <a href="#"><i class="bi bi-youtube"></i></a>
              <a href="#"><i class="bi bi-instagram"></i></a>
            </div> -->
          <!--   <h6 class="footer-heading">Resources</h6>
            <ul class="footer-links">
              <li><a href="#">Help Center</a></li>
              <li><a href="#">Webinars & Events</a></li>
              <li><a href="#">eBooks & Guides</a></li>
              <li><a href="#">ROI Calculator</a></li>
              <li><a href="#">System Status</a></li>
              <li><a href="#">Security</a></li>
            </ul>-->
          </div>
        </div>

        <!-- Newsletter -->
<!--         <div style="margin-top: 3rem; padding: 2rem; background: rgba(255,255,255,0.04); border-radius: var(--radius-lg); border: 1px solid rgba(255,255,255,0.06); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1.5rem;">
          <div>
            <h6 style="font-size: 1rem; font-weight: 600; margin-bottom: 0.25rem; color: rgba(255,255,255,0.9);">Stay ahead on Learning & HR trends</h6>
            <p style="font-size: 0.85rem; color: rgba(255,255,255,0.4); margin: 0;">Join 10,000+ L&D professionals. No spam, unsubscribe anytime.</p>
          </div>
          <div style="display: flex; gap: 0.5rem; flex: 1; max-width: 400px;">
            <input type="email" [(ngModel)]="newsletterEmail" placeholder="Your email address"
                   style="flex: 1; padding: 0.625rem 1rem; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1); border-radius: var(--radius-full); color: #fff; font-size: 0.9rem; outline: none;" />
            <button class="btn-hero primary sm" (click)="subscribeNewsletter()">Subscribe</button>
          </div>
        </div> -->

        <div class="footer-bottom">
          <p>&copy; 2026 Central LMS by Kodek Innovations Limited. All rights reserved.</p>
          <div class="footer-legal">
            <a href="#">Privacy Policy</a>
            <a href="#">Terms of Service</a>
            <a href="#">Cookie Policy</a>
          </div>
        </div>
      </div>
    </footer>
  `
})
export class FooterComponent {
  newsletterEmail = '';
  subscribeNewsletter() {
    if (this.newsletterEmail.includes('@')) {
      alert('Thank you for subscribing!');
      this.newsletterEmail = '';
    }
  }
}
