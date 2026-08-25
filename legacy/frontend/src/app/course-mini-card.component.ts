import { Component, Input, OnInit } from '@angular/core';
import {CommonModule, NgStyle} from '@angular/common';

@Component({
  selector: 'app-course-mini-card',
  standalone: true,
  template: `
    <div class="mini-card"
      [ngStyle]="{ background: gradient }"
      title="{{ title }}" >
      <span class="initials">
        {{ initials }}
      </span>
    </div>
  `,
  imports: [ CommonModule ],
  styles: [`
    .mini-card {
      width: 56px;
      height: 56px;
      border-radius: 14px;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;

      box-shadow: 0 8px 20px rgba(0, 0, 0, 0.18);
      transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .mini-card:hover {
      transform: scale(1.05);
      box-shadow: 0 12px 30px rgba(0, 0, 0, 0.25);
    }

    .initials {
      color: #ffffff;
      font-size: 16px;
      font-weight: 700;
      letter-spacing: 0.5px;
      user-select: none;
    }
  `]
})
export class CourseMiniCardComponent implements OnInit {

  @Input() title!: string;
  @Input() index = 0;

  initials = '';
  gradient = '';

  private gradients: string[] = [
    'linear-gradient(135deg, #6366f1, #10b981)',
    'linear-gradient(135deg, #f59e0b, #ef4444)',
    'linear-gradient(135deg, #06b6d4, #3b82f6)',
    'linear-gradient(135deg, #8b5cf6, #ec4899)',
    'linear-gradient(135deg, #22c55e, #14b8a6)',
    'linear-gradient(135deg, #111827, #374151)'
  ];

  ngOnInit(): void {
    this.initials = this.getInitials(this.title);
    this.gradient = this.gradients[
    this.index % this.gradients.length
      ];
  }

  private getInitials(title: string): string {
    if (!title) return '';

    const words = title
      .trim()
      .split(' ')
      .filter(w => w.length > 0);

    if (words.length === 1) {
      return words[0].substring(0, 2).toUpperCase();
    }

    return (
      words[0][0] + words[1][0]
    ).toUpperCase();
  }
}
