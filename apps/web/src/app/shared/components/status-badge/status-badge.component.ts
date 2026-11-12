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
  /*
    Colour comes from the global soft-pill badge rules in styles.scss: each
    bg-{tone} class renders as a ~10% tint of the status tone (--ui-success,
    --ui-danger, …) with a hairline and a darker label, and bg-primary follows the
    tenant brand. This component only adds its size/shape variants, the outline
    variant, and the extra categorical colours (purple/teal/orange/cyan) that
    Bootstrap has no utility for — without these, those question-type badges
    rendered as white text on no background at all.
  */
  styles: [`
    .badge {
      display: inline-flex;
      align-items: center;
      font-weight: 500;
      /* Square-ish by default so the pill input still means something; the
         global badge style is a full pill. */
      border-radius: 6px;
    }
    .badge-sm { font-size: 0.6875rem; padding: 0.1875rem 0.5rem; }
    .badge-lg { font-size: 0.875rem; padding: 0.375rem 0.75rem; }
    .badge-pill { border-radius: var(--ui-radius-pill, 999px); }

    /* Categorical tones (question types). Same soft-pill recipe as the
       status tones: tint, hairline, darker label. */
    .badge.bg-purple {
      background-color: rgba(111, 66, 193, 0.10) !important;
      color: #5a34a0 !important;
      border-color: rgba(111, 66, 193, 0.28);
    }
    .badge.bg-teal {
      background-color: rgba(15, 128, 110, 0.10) !important;
      color: #0d6b5c !important;
      border-color: rgba(15, 128, 110, 0.28);
    }
    .badge.bg-orange {
      background-color: rgba(214, 98, 18, 0.10) !important;
      color: #a54a0c !important;
      border-color: rgba(214, 98, 18, 0.28);
    }
    .badge.bg-cyan {
      background-color: rgba(8, 128, 160, 0.10) !important;
      color: #076a85 !important;
      border-color: rgba(8, 128, 160, 0.28);
    }

    /* Outline: no fill, a tone-coloured hairline and label. !important because
       both the bg-* utilities and the global soft-pill rule are !important. */
    .badge.badge-outline {
      background: transparent !important;
      border: 1px solid currentColor;
    }
    .badge.badge-outline.bg-success { color: var(--ui-success-text, #23643e) !important; border-color: rgba(var(--ui-success-rgb, 46, 125, 79), 0.45); }
    .badge.badge-outline.bg-danger { color: var(--ui-danger-text, #a12c24) !important; border-color: rgba(var(--ui-danger-rgb, 192, 53, 43), 0.45); }
    .badge.badge-outline.bg-warning { color: var(--ui-warning-text, #8f4b07) !important; border-color: rgba(var(--ui-warning-rgb, 178, 94, 9), 0.45); }
    .badge.badge-outline.bg-info { color: var(--ui-info-text, #1d518c) !important; border-color: rgba(var(--ui-info-rgb, 37, 99, 168), 0.45); }
    .badge.badge-outline.bg-primary { color: var(--brand-primary, #303234) !important; border-color: rgba(var(--brand-primary-rgb, 48, 50, 52), 0.45); }
    .badge.badge-outline.bg-secondary,
    .badge.badge-outline.bg-dark { color: var(--ui-neutral-text, #3c423d) !important; border-color: rgba(var(--ui-neutral-rgb, 75, 82, 76), 0.35); }
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
