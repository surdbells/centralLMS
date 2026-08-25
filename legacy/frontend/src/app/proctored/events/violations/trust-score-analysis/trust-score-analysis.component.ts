// trust-score-analysis/trust-score-analysis.component.ts
import { Component, Input, OnChanges, SimpleChanges, ChangeDetectionStrategy } from '@angular/core';
import { CommonModule } from '@angular/common';

export interface TrustFactor {
  factor_id: string;
  name: string;
  category: 'identity' | 'behavior' | 'environment' | 'integrity';
  score: number; // 0-100
  weight: number; // 0-1
  weighted_score: number;
  status: 'pass' | 'warning' | 'fail';
  details?: string;
  violations?: number;
}

@Component({
  selector: 'app-trust-score-analysis',
  standalone: true,
  imports: [CommonModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="trust-score-analysis" [class.compact]="compact">
      <!-- Overall Score -->
      <div class="overall-score" *ngIf="!compact">
        <div class="score-circle" [class.high]="trustScore >= 80" [class.medium]="trustScore >= 50 && trustScore < 80" [class.low]="trustScore < 50">
          <svg viewBox="0 0 100 100">
            <circle class="bg" cx="50" cy="50" r="45"></circle>
            <circle class="progress" cx="50" cy="50" r="45" [attr.stroke-dasharray]="getCircleProgress()"></circle>
          </svg>
          <div class="score-value">{{ trustScore }}</div>
          <div class="score-label">Trust Score</div>
        </div>
        <div class="score-status">
          <span class="status-badge" [ngClass]="getOverallStatus()">
            <i class="bi" [ngClass]="getStatusIcon()"></i>
            {{ getStatusLabel() }}
          </span>
          <p class="status-desc">{{ getStatusDescription() }}</p>
        </div>
      </div>

      <!-- Factors by Category -->
      <div class="factors-section">
        <div *ngFor="let category of categories" class="factor-category">
          <div class="category-header">
            <i class="bi" [ngClass]="getCategoryIcon(category)"></i>
            <span class="category-name">{{ getCategoryLabel(category) }}</span>
            <span class="category-score" [class.low]="getCategoryScore(category) < 50">
              {{ getCategoryScore(category) }}%
            </span>
          </div>
          
          <div class="factor-list">
            <div *ngFor="let factor of getFactorsByCategory(category)" class="factor-item" [class.warning]="factor.status === 'warning'" [class.fail]="factor.status === 'fail'">
              <div class="factor-info">
                <span class="factor-name">{{ factor.name }}</span>
                <span class="factor-detail" *ngIf="factor.details">{{ factor.details }}</span>
              </div>
              <div class="factor-score-bar">
                <div class="score-bar-track">
                  <div class="score-bar-fill" [style.width.%]="factor.score" 
                       [class.high]="factor.score >= 80" [class.medium]="factor.score >= 50 && factor.score < 80" [class.low]="factor.score < 50"></div>
                </div>
                <span class="factor-score-value">{{ factor.score }}%</span>
              </div>
              <div class="factor-status">
                <i class="bi" [ngClass]="getFactorStatusIcon(factor)"></i>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Breakdown Summary -->
      <div class="breakdown-summary" *ngIf="!compact">
        <h6><i class="bi bi-pie-chart me-2"></i>Score Breakdown</h6>
        <div class="breakdown-chart">
          <div *ngFor="let category of categories" class="breakdown-bar" 
               [style.width.%]="getCategoryContribution(category)"
               [class.identity]="category === 'identity'"
               [class.behavior]="category === 'behavior'"
               [class.environment]="category === 'environment'"
               [class.integrity]="category === 'integrity'">
          </div>
        </div>
        <div class="breakdown-legend">
          <div *ngFor="let category of categories" class="legend-item">
            <span class="legend-dot" [ngClass]="category"></span>
            <span class="legend-label">{{ getCategoryLabel(category) }}</span>
            <span class="legend-value">{{ getCategoryContribution(category) | number:'1.0-0' }}%</span>
          </div>
        </div>
      </div>

      <!-- Recommendations -->
      <div class="recommendations" *ngIf="!compact && recommendations.length > 0">
        <h6><i class="bi bi-lightbulb me-2"></i>Recommendations</h6>
        <ul class="recommendation-list">
          <li *ngFor="let rec of recommendations">
            <i class="bi bi-arrow-right-circle"></i>
            {{ rec }}
          </li>
        </ul>
      </div>
    </div>
  `,
  styles: [`
    .trust-score-analysis { padding: 1rem; }
    .trust-score-analysis.compact { padding: 0.5rem; }

    .overall-score { display: flex; align-items: center; gap: 1.5rem; margin-bottom: 1.5rem; padding-bottom: 1.5rem; border-bottom: 1px solid #e9ecef; }
    .score-circle { position: relative; width: 120px; height: 120px; }
    .score-circle svg { width: 100%; height: 100%; transform: rotate(-90deg); }
    .score-circle circle { fill: none; stroke-width: 8; }
    .score-circle .bg { stroke: #e9ecef; }
    .score-circle .progress { stroke-linecap: round; transition: stroke-dasharray 0.5s; }
    .score-circle.high .progress { stroke: #198754; }
    .score-circle.medium .progress { stroke: #ffc107; }
    .score-circle.low .progress { stroke: #dc3545; }
    .score-value { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -60%); font-size: 2rem; font-weight: 700; }
    .score-label { position: absolute; top: 50%; left: 50%; transform: translate(-50%, 50%); font-size: 0.75rem; color: #6c757d; }

    .status-badge { display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.375rem 0.75rem; border-radius: 1rem; font-weight: 600; }
    .status-badge.high { background: #d1e7dd; color: #0f5132; }
    .status-badge.medium { background: #fff3cd; color: #664d03; }
    .status-badge.low { background: #f8d7da; color: #842029; }
    .status-desc { margin: 0.5rem 0 0; color: #6c757d; font-size: 0.875rem; }

    .factor-category { margin-bottom: 1rem; }
    .category-header { display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem; background: #f8f9fa; border-radius: 0.375rem; margin-bottom: 0.5rem; }
    .category-name { flex: 1; font-weight: 600; font-size: 0.875rem; }
    .category-score { font-weight: 700; }
    .category-score.low { color: #dc3545; }

    .factor-item { display: flex; align-items: center; gap: 0.75rem; padding: 0.5rem; border-left: 3px solid #e9ecef; margin-bottom: 0.25rem; }
    .factor-item.warning { border-color: #ffc107; background: #fffbeb; }
    .factor-item.fail { border-color: #dc3545; background: #fff5f5; }
    .factor-info { flex: 1; min-width: 0; }
    .factor-name { display: block; font-size: 0.8125rem; font-weight: 500; }
    .factor-detail { display: block; font-size: 0.75rem; color: #6c757d; }

    .factor-score-bar { display: flex; align-items: center; gap: 0.5rem; width: 100px; }
    .score-bar-track { flex: 1; height: 6px; background: #e9ecef; border-radius: 3px; overflow: hidden; }
    .score-bar-fill { height: 100%; border-radius: 3px; }
    .score-bar-fill.high { background: #198754; }
    .score-bar-fill.medium { background: #ffc107; }
    .score-bar-fill.low { background: #dc3545; }
    .factor-score-value { font-size: 0.75rem; font-weight: 600; min-width: 35px; text-align: right; }

    .factor-status i { font-size: 1rem; }
    .factor-status .bi-check-circle-fill { color: #198754; }
    .factor-status .bi-exclamation-circle-fill { color: #ffc107; }
    .factor-status .bi-x-circle-fill { color: #dc3545; }

    .breakdown-summary { margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #e9ecef; }
    .breakdown-summary h6 { margin-bottom: 0.75rem; }
    .breakdown-chart { display: flex; height: 24px; border-radius: 12px; overflow: hidden; background: #e9ecef; }
    .breakdown-bar { transition: width 0.3s; }
    .breakdown-bar.identity { background: #0d6efd; }
    .breakdown-bar.behavior { background: #198754; }
    .breakdown-bar.environment { background: #ffc107; }
    .breakdown-bar.integrity { background: #6f42c1; }
    
    .breakdown-legend { display: flex; flex-wrap: wrap; gap: 1rem; margin-top: 0.75rem; }
    .legend-item { display: flex; align-items: center; gap: 0.25rem; font-size: 0.75rem; }
    .legend-dot { width: 10px; height: 10px; border-radius: 2px; }
    .legend-dot.identity { background: #0d6efd; }
    .legend-dot.behavior { background: #198754; }
    .legend-dot.environment { background: #ffc107; }
    .legend-dot.integrity { background: #6f42c1; }
    .legend-value { font-weight: 600; }

    .recommendations { margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #e9ecef; }
    .recommendations h6 { margin-bottom: 0.75rem; }
    .recommendation-list { list-style: none; padding: 0; margin: 0; }
    .recommendation-list li { display: flex; align-items: flex-start; gap: 0.5rem; padding: 0.375rem 0; font-size: 0.875rem; color: #6c757d; }
    .recommendation-list i { color: #0d6efd; margin-top: 2px; }

    .compact .factor-category { margin-bottom: 0.5rem; }
    .compact .category-header { padding: 0.375rem 0.5rem; }
    .compact .factor-item { padding: 0.25rem 0.5rem; }
  `]
})
export class TrustScoreAnalysisComponent implements OnChanges {
  @Input() trustScore = 0;
  @Input() factors: TrustFactor[] = [];
  @Input() compact = false;

  categories: ('identity' | 'behavior' | 'environment' | 'integrity')[] = ['identity', 'behavior', 'environment', 'integrity'];
  recommendations: string[] = [];

  ngOnChanges(changes: SimpleChanges): void {
    if (changes['factors'] || changes['trustScore']) {
      this.generateRecommendations();
    }
  }

  getCircleProgress(): string {
    const circumference = 2 * Math.PI * 45;
    const progress = (this.trustScore / 100) * circumference;
    return `${progress} ${circumference}`;
  }

  getOverallStatus(): string {
    if (this.trustScore >= 80) return 'high';
    if (this.trustScore >= 50) return 'medium';
    return 'low';
  }

  getStatusIcon(): string {
    if (this.trustScore >= 80) return 'bi-shield-check';
    if (this.trustScore >= 50) return 'bi-shield-exclamation';
    return 'bi-shield-x';
  }

  getStatusLabel(): string {
    if (this.trustScore >= 80) return 'High Confidence';
    if (this.trustScore >= 50) return 'Medium Confidence';
    return 'Low Confidence';
  }

  getStatusDescription(): string {
    if (this.trustScore >= 80) return 'Session integrity appears intact with minimal concerns.';
    if (this.trustScore >= 50) return 'Some concerns detected. Manual review recommended.';
    return 'Significant integrity concerns. Immediate review required.';
  }

  getCategoryIcon(category: string): string {
    const icons: Record<string, string> = {
      identity: 'bi-person-badge',
      behavior: 'bi-activity',
      environment: 'bi-pc-display',
      integrity: 'bi-shield-lock'
    };
    return icons[category] || 'bi-circle';
  }

  getCategoryLabel(category: string): string {
    const labels: Record<string, string> = {
      identity: 'Identity Verification',
      behavior: 'Behavioral Analysis',
      environment: 'Environment Check',
      integrity: 'Academic Integrity'
    };
    return labels[category] || category;
  }

  getFactorsByCategory(category: string): TrustFactor[] {
    return this.factors.filter(f => f.category === category);
  }

  getCategoryScore(category: string): number {
    const categoryFactors = this.getFactorsByCategory(category);
    if (categoryFactors.length === 0) return 100;
    const total = categoryFactors.reduce((sum, f) => sum + f.score, 0);
    return Math.round(total / categoryFactors.length);
  }

  getCategoryContribution(category: string): number {
    const categoryFactors = this.getFactorsByCategory(category);
    const totalWeight = this.factors.reduce((sum, f) => sum + f.weight, 0);
    const categoryWeight = categoryFactors.reduce((sum, f) => sum + f.weight, 0);
    return totalWeight > 0 ? (categoryWeight / totalWeight) * 100 : 25;
  }

  getFactorStatusIcon(factor: TrustFactor): string {
    switch (factor.status) {
      case 'pass': return 'bi-check-circle-fill';
      case 'warning': return 'bi-exclamation-circle-fill';
      case 'fail': return 'bi-x-circle-fill';
      default: return 'bi-circle';
    }
  }

  private generateRecommendations(): void {
    this.recommendations = [];
    const failedFactors = this.factors.filter(f => f.status === 'fail');
    const warningFactors = this.factors.filter(f => f.status === 'warning');

    if (failedFactors.length > 0) {
      this.recommendations.push('Review failed verification factors carefully before approving this attempt.');
    }
    if (warningFactors.length > 2) {
      this.recommendations.push('Multiple warning flags detected. Consider comparing with learner\'s previous attempts.');
    }
    if (this.trustScore < 50) {
      this.recommendations.push('Trust score is critically low. Manual identity verification recommended.');
      this.recommendations.push('Consider scheduling a live proctored re-examination.');
    }
    if (this.factors.some(f => f.factor_id === 'multiple_faces' && f.status !== 'pass')) {
      this.recommendations.push('Multiple faces were detected. Review recordings for unauthorized assistance.');
    }
    if (this.factors.some(f => f.factor_id === 'tab_switch' && (f.violations || 0) > 3)) {
      this.recommendations.push('Frequent tab switches detected. Check for potential reference material usage.');
    }
  }
}
