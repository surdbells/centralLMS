// components/pagination/pagination.component.ts
import { Component, Input, Output, EventEmitter, ChangeDetectionStrategy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';

@Component({
  selector: 'app-pagination',
  standalone: true,
  imports: [CommonModule, FormsModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="pagination-container" *ngIf="totalPages > 0">
      <!-- Page Size Selector -->
      <div class="page-size" *ngIf="showPageSize">
        <label>Show</label>
        <select class="form-select form-select-sm" [ngModel]="pageSize" (ngModelChange)="onPageSizeChange($event)">
          <option *ngFor="let size of pageSizeOptions" [value]="size">{{ size }}</option>
        </select>
        <span>entries</span>
      </div>

      <!-- Info -->
      <div class="page-info" *ngIf="showInfo">
        Showing {{ startItem }} to {{ endItem }} of {{ totalItems }}
      </div>

      <!-- Navigation -->
      <nav class="page-nav" aria-label="Pagination">
        <ul class="pagination pagination-sm mb-0">
          <!-- First -->
          <li class="page-item" [class.disabled]="currentPage === 1" *ngIf="showFirstLast">
            <button class="page-link" (click)="goToPage(1)" [disabled]="currentPage === 1" title="First">
              <i class="bi bi-chevron-double-left"></i>
            </button>
          </li>

          <!-- Previous -->
          <li class="page-item" [class.disabled]="currentPage === 1">
            <button class="page-link" (click)="goToPage(currentPage - 1)" [disabled]="currentPage === 1" title="Previous">
              <i class="bi bi-chevron-left"></i>
            </button>
          </li>

          <!-- Page Numbers -->
          <ng-container *ngFor="let page of visiblePages">
            <li class="page-item" *ngIf="page === '...'" class="page-item disabled">
              <span class="page-link">...</span>
            </li>
            <li class="page-item" *ngIf="page !== '...'" [class.active]="page === currentPage">
              <button class="page-link" (click)="goToPage(+page)">{{ page }}</button>
            </li>
          </ng-container>

          <!-- Next -->
          <li class="page-item" [class.disabled]="currentPage === totalPages">
            <button class="page-link" (click)="goToPage(currentPage + 1)" [disabled]="currentPage === totalPages" title="Next">
              <i class="bi bi-chevron-right"></i>
            </button>
          </li>

          <!-- Last -->
          <li class="page-item" [class.disabled]="currentPage === totalPages" *ngIf="showFirstLast">
            <button class="page-link" (click)="goToPage(totalPages)" [disabled]="currentPage === totalPages" title="Last">
              <i class="bi bi-chevron-double-right"></i>
            </button>
          </li>
        </ul>
      </nav>

      <!-- Quick Jump -->
      <div class="page-jump" *ngIf="showQuickJump && totalPages > 10">
        <label>Go to</label>
        <input 
          type="number" 
          class="form-control form-control-sm"
          [min]="1"
          [max]="totalPages"
          [value]="currentPage"
          (keyup.enter)="onQuickJump($event)"
          (blur)="onQuickJump($event)">
      </div>
    </div>
  `,
  styles: [`
    .pagination-container {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      padding: 0.75rem 0;
    }

    .page-size {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      font-size: 0.875rem;
      color: #6c757d;
    }
    .page-size select {
      width: auto;
    }

    .page-info {
      font-size: 0.875rem;
      color: #6c757d;
    }

    .page-nav {
      display: flex;
    }

    .pagination {
      gap: 0.25rem;
    }
    .page-link {
      min-width: 36px;
      text-align: center;
      border-radius: 0.25rem !important;
      color: #495057;
      border-color: #dee2e6;
    }
    .page-link:hover {
      background-color: #e9ecef;
    }
    .page-item.active .page-link {
      background-color: #0d6efd;
      border-color: #0d6efd;
    }
    .page-item.disabled .page-link {
      color: #adb5bd;
    }

    .page-jump {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      font-size: 0.875rem;
      color: #6c757d;
    }
    .page-jump input {
      width: 60px;
      text-align: center;
    }

    @media (max-width: 576px) {
      .pagination-container {
        justify-content: center;
      }
      .page-size, .page-info, .page-jump {
        display: none;
      }
    }
  `]
})
export class PaginationComponent {
  @Input() currentPage: number = 1;
  @Input() pageSize: number = 10;
  @Input() totalItems: number = 0;
  @Input() pageSizeOptions: number[] = [10, 25, 50, 100];
  @Input() maxVisiblePages: number = 5;
  @Input() showPageSize: boolean = true;
  @Input() showInfo: boolean = true;
  @Input() showFirstLast: boolean = true;
  @Input() showQuickJump: boolean = false;

  @Output() pageChange = new EventEmitter<number>();
  @Output() pageSizeChange = new EventEmitter<number>();

  get totalPages(): number {
    return Math.ceil(this.totalItems / this.pageSize);
  }

  get startItem(): number {
    return this.totalItems === 0 ? 0 : (this.currentPage - 1) * this.pageSize + 1;
  }

  get endItem(): number {
    return Math.min(this.currentPage * this.pageSize, this.totalItems);
  }

  get visiblePages(): (number | string)[] {
    const pages: (number | string)[] = [];
    const total = this.totalPages;
    const current = this.currentPage;
    const maxVisible = this.maxVisiblePages;

    if (total <= maxVisible + 2) {
      for (let i = 1; i <= total; i++) {
        pages.push(i);
      }
    } else {
      const half = Math.floor(maxVisible / 2);
      let start = Math.max(2, current - half);
      let end = Math.min(total - 1, current + half);

      if (current <= half + 1) {
        end = maxVisible;
      } else if (current >= total - half) {
        start = total - maxVisible + 1;
      }

      pages.push(1);
      if (start > 2) pages.push('...');
      for (let i = start; i <= end; i++) {
        pages.push(i);
      }
      if (end < total - 1) pages.push('...');
      pages.push(total);
    }

    return pages;
  }

  goToPage(page: number): void {
    if (page >= 1 && page <= this.totalPages && page !== this.currentPage) {
      this.pageChange.emit(page);
    }
  }

  onPageSizeChange(size: number): void {
    this.pageSizeChange.emit(+size);
    this.pageChange.emit(1);
  }

  onQuickJump(event: Event): void {
    const input = event.target as HTMLInputElement;
    const page = parseInt(input.value, 10);
    if (!isNaN(page)) {
      const validPage = Math.max(1, Math.min(page, this.totalPages));
      input.value = validPage.toString();
      this.goToPage(validPage);
    }
  }
}
