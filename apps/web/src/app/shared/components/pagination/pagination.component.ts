// components/pagination/pagination.component.ts
import { Component, Input, Output, EventEmitter, ChangeDetectionStrategy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { TranslatePipe } from '../../../translate.pipe';
import { SelectComponent } from '../../select/select.component';

@Component({
  selector: 'app-pagination',
  standalone: true,
  imports: [CommonModule, TranslatePipe, SelectComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="pagination-container" *ngIf="totalPages > 0">
      <!-- Page Size Selector -->
      <div class="page-size" *ngIf="showPageSize">
        <label>{{ 'shared.pagination.show' | translate }}</label>
        <app-select size="sm" [options]="pageSizeOptions" [searchable]="false"
                    [ariaLabel]="'shared.pagination.show' | translate"
                    [value]="pageSize" (selectionChange)="onPageSizeChange($event)" />
        <span>{{ 'shared.pagination.entries' | translate }}</span>
      </div>

      <!-- Info -->
      <div class="page-info" *ngIf="showInfo">
        {{ 'shared.pagination.showing' | translate:{ from: startItem, to: endItem, total: totalItems } }}
      </div>

      <!-- Navigation -->
      <nav class="page-nav" aria-label="Pagination">
        <ul class="pagination pagination-sm mb-0">
          <!-- First -->
          <li class="page-item" [class.disabled]="currentPage === 1" *ngIf="showFirstLast">
            <button class="page-link" (click)="goToPage(1)" [disabled]="currentPage === 1" [title]="'shared.pagination.first' | translate">
              <i class="bi bi-chevron-double-left"></i>
            </button>
          </li>

          <!-- Previous -->
          <li class="page-item" [class.disabled]="currentPage === 1">
            <button class="page-link" (click)="goToPage(currentPage - 1)" [disabled]="currentPage === 1" [title]="'common.previous' | translate">
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
            <button class="page-link" (click)="goToPage(currentPage + 1)" [disabled]="currentPage === totalPages" [title]="'common.next' | translate">
              <i class="bi bi-chevron-right"></i>
            </button>
          </li>

          <!-- Last -->
          <li class="page-item" [class.disabled]="currentPage === totalPages" *ngIf="showFirstLast">
            <button class="page-link" (click)="goToPage(totalPages)" [disabled]="currentPage === totalPages" [title]="'shared.pagination.last' | translate">
              <i class="bi bi-chevron-double-right"></i>
            </button>
          </li>
        </ul>
      </nav>

      <!-- Quick Jump -->
      <div class="page-jump" *ngIf="showQuickJump && totalPages > 10">
        <label>{{ 'shared.pagination.go_to' | translate }}</label>
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
      font-size: 0.8125rem;
      color: var(--ui-text-muted, #6a716b);
    }
    .page-size app-select {
      width: 84px;
    }

    .page-info {
      font-size: 0.8125rem;
      color: var(--ui-text-muted, #6a716b);
      font-variant-numeric: tabular-nums;
    }

    .page-nav {
      display: flex;
    }

    /*
      Separated 36px squares, radius 8, hairline edge; the current page takes
      the tenant brand colour (it was a hard-coded Bootstrap blue that ignored
      the organisation's colour). The list carries .pagination-sm for its font
      size, but the buttons are sized to the shared 36px control height so they
      line up with the selects and buttons beside them.
    */
    .pagination {
      gap: 0.25rem;
    }
    .page-link {
      min-width: var(--ui-ctl-h, 36px);
      height: var(--ui-ctl-h, 36px);
      padding: 0 0.625rem;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-left: 0 !important;
      border-radius: var(--ui-radius-ctl, 8px) !important;
      font-size: 0.8125rem;
      font-weight: 500;
      font-variant-numeric: tabular-nums;
      color: var(--ui-text, #1b1f1c);
      background-color: var(--ui-surface, #fff);
      border: 1px solid var(--ui-border-strong, #d9ddd9);
      transition: background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease;
    }
    .page-link:hover {
      background-color: var(--ui-surface-2, #f7f8f7);
      border-color: #c9cec9;
      color: var(--ui-text, #1b1f1c);
    }
    .page-link:focus-visible {
      box-shadow: 0 0 0 3px rgba(var(--brand-primary-rgb, 48, 50, 52), 0.18);
    }
    .page-item.active .page-link {
      background-color: var(--brand-primary, #303234);
      border-color: var(--brand-primary, #303234);
      color: var(--brand-primary-contrast, #fff);
    }
    .page-item.disabled .page-link {
      color: var(--ui-text-subtle, #8d948e);
      background-color: var(--ui-surface, #fff);
      border-color: var(--ui-border, #e7e9e7);
    }
    /* The ellipsis is a gap, not a control. */
    .page-item.disabled span.page-link {
      border-color: transparent;
      background: transparent;
      min-width: 24px;
      padding: 0;
    }

    /* Keep the page-size select and jump box at the same control height. */
    .page-jump input {
      min-height: var(--ui-ctl-h, 36px);
      font-size: 0.8125rem;
    }

    .page-jump {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      font-size: 0.8125rem;
      color: var(--ui-text-muted, #6a716b);
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
