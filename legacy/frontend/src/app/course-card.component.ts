import { Component, Input, OnInit } from '@angular/core';
import {CommonModule} from '@angular/common';

@Component({
  selector: 'app-course-card',
  standalone: true,
  template: `
    <div class="course-card" [ngStyle]="{ background: gradient }">
      <h3 class="course-title text-center">
        {{ title }}
      </h3>
    </div>
  `,
  imports: [
    CommonModule
  ],
  styles: [`
    .course-card {
      height: 100%;
      padding: 18px;
      cursor: pointer;
      box-shadow: 0 12px 30px rgba(0, 0, 0, 0.15);
      transition: transform 0.3s ease, box-shadow 0.3s ease;
    }

    .course-card:hover {
      transform: translateY(-6px);
      box-shadow: 0 20px 45px rgba(0, 0, 0, 0.2);
    }

    .course-title {
      color: #ffffff;
      font-size: 16px;
      font-weight: 600;
      line-height: 6.5;
      margin: 0;

      display: -webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }
  `]
})
export class CourseCardComponent implements OnInit {

  @Input() title!: string;
  @Input() index = 0;
  private gradients: string[] = [
    'linear-gradient(135deg, #6366f1, #10b981)',
    'linear-gradient(135deg, #f59e0b, #ef4444)',
    'linear-gradient(135deg, #06b6d4, #3b82f6)',
    'linear-gradient(135deg, #8b5cf6, #ec4899)',
    'linear-gradient(135deg, #22c55e, #14b8a6)',
    'linear-gradient(135deg, #111827, #374151)'
  ];
  gradient!: string;
  ngOnInit(): void {
    this.gradient = this.gradients[
    this.index % this.gradients.length
      ];
  }
}
