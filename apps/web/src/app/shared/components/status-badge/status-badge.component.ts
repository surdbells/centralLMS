// components/status-badge/status-badge.component.ts
import { Component, Input, ChangeDetectionStrategy } from '@angular/core';
import { CommonModule } from '@angular/common';

type BadgeType = 'status' | 'difficulty' | 'priority' | 'grade' | 'proctoring' | 'question' | 'certificate' | 'custom';

@Component({
  selector: 'app-status-badge',
  standalone: true,
  imports: [CommonModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <span 
      class="badge" 
      [ngClass]="[badgeClass, sizeClass]"
      [class.badge-pill]="pill"
      [class.badge-outline]="outline">
      <i *ngIf="icon" class="bi" [ngClass]="icon" [class.me-1]="!!value"></i>
      {{ displayValue }}
    </span>
  `,
  styles: [`
    .badge {
      display: inline-flex;
      align-items: center;
      font-weight: 500;
      padding: 0.35em 0.65em;
    }
    .badge-sm { font-size: 0.75em; padding: 0.25em 0.5em; }
    .badge-lg { font-size: 1em; padding: 0.5em 0.85em; }
    .badge-pill { border-radius: 50rem; }
    .badge-outline {
      background: transparent !important;
      border: 1px solid currentColor;
    }
    .badge-outline.bg-success { color: #198754; }
    .badge-outline.bg-danger { color: #dc3545; }
    .badge-outline.bg-warning { color: #ffc107; }
    .badge-outline.bg-info { color: #0dcaf0; }
    .badge-outline.bg-primary { color: #0d6efd; }
    .badge-outline.bg-secondary { color: #6c757d; }
  `]
})
export class StatusBadgeComponent {
  @Input() value: string = '';
  @Input() type: BadgeType = 'custom';
  @Input() color: string = '';
  @Input() icon: string = '';
  @Input() size: 'sm' | 'md' | 'lg' = 'md';
  @Input() pill: boolean = false;
  @Input() outline: boolean = false;
  @Input() uppercase: boolean = false;

  get displayValue(): string {
    if (!this.value) return '';
    const formatted = this.value.replace(/_/g, ' ');
    return this.uppercase ? formatted.toUpperCase() : this.titleCase(formatted);
  }

  get sizeClass(): string {
    if (this.size === 'md') return '';
    return `badge-${this.size}`;
  }

  get badgeClass(): string {
    if (this.color) return `bg-${this.color}`;
    return this.getColorByType();
  }

  private getColorByType(): string {
    switch (this.type) {
      case 'status':
        return this.getStatusColor();
      case 'difficulty':
        return this.getDifficultyColor();
      case 'priority':
        return this.getPriorityColor();
      case 'grade':
        return this.getGradeColor();
      case 'proctoring':
        return this.getProctoringColor();
      case 'question':
        return this.getQuestionTypeColor();
      case 'certificate':
        return this.getCertificateColor();
      default:
        return 'bg-secondary';
    }
  }

  private getStatusColor(): string {
    const colors: Record<string, string> = {
      'draft': 'bg-secondary',
      'published': 'bg-success',
      'archived': 'bg-dark',
      'closed': 'bg-danger',
      'not_started': 'bg-secondary',
      'in_progress': 'bg-primary',
      'paused': 'bg-warning',
      'submitted': 'bg-info',
      'auto_submitted': 'bg-info',
      'grading': 'bg-warning',
      'graded': 'bg-success',
      'abandoned': 'bg-danger',
      'pending': 'bg-warning',
      'active': 'bg-success',
      'completed': 'bg-success',
      'failed': 'bg-danger',
      'passed': 'bg-success'
    };
    return colors[this.value.toLowerCase()] || 'bg-secondary';
  }

  private getDifficultyColor(): string {
    const colors: Record<string, string> = {
      'very_easy': 'bg-success',
      'easy': 'bg-info',
      'medium': 'bg-primary',
      'moderate': 'bg-primary',
      'hard': 'bg-warning',
      'very_hard': 'bg-danger'
    };
    return colors[this.value.toLowerCase()] || 'bg-secondary';
  }

  private getPriorityColor(): string {
    const colors: Record<string, string> = {
      'low': 'bg-secondary',
      'normal': 'bg-primary',
      'high': 'bg-warning',
      'urgent': 'bg-danger'
    };
    return colors[this.value.toLowerCase()] || 'bg-secondary';
  }

  private getGradeColor(): string {
    const grade = this.value.toUpperCase();
    if (grade.startsWith('A')) return 'bg-success';
    if (grade.startsWith('B')) return 'bg-info';
    if (grade.startsWith('C')) return 'bg-primary';
    if (grade.startsWith('D')) return 'bg-warning';
    return 'bg-danger';
  }

  private getProctoringColor(): string {
    const colors: Record<string, string> = {
      'none': 'bg-secondary',
      'basic': 'bg-info',
      'standard': 'bg-primary',
      'advanced': 'bg-warning'
    };
    return colors[this.value.toLowerCase()] || 'bg-secondary';
  }

  private getQuestionTypeColor(): string {
    const colors: Record<string, string> = {
      'multiple_choice': 'bg-primary',
      'multiple_response': 'bg-info',
      'true_false': 'bg-success',
      'short_answer': 'bg-warning',
      'long_answer': 'bg-danger',
      'fill_in_blank': 'bg-secondary',
      'matching': 'bg-purple',
      'ordering': 'bg-teal',
      'hotspot': 'bg-orange',
      'file_upload': 'bg-dark',
      'numeric': 'bg-cyan'
    };
    return colors[this.value.toLowerCase()] || 'bg-secondary';
  }

  private getCertificateColor(): string {
    const colors: Record<string, string> = {
      'active': 'bg-success',
      'revoked': 'bg-danger',
      'expired': 'bg-warning',
      'superseded': 'bg-secondary'
    };
    return colors[this.value.toLowerCase()] || 'bg-secondary';
  }

  private titleCase(str: string): string {
    return str.replace(/\b\w/g, l => l.toUpperCase());
  }
}
