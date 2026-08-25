// reports/components/report-table/report-table.component.ts

import { Component, EventEmitter, Input, OnChanges, Output, SimpleChanges } from '@angular/core';
import { CommonModule, DecimalPipe, DatePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ReportMeta } from '../../models/report.models';

@Component({
  selector: 'app-report-table',
  standalone: true,
  imports: [CommonModule, FormsModule],
  providers: [DecimalPipe, DatePipe],
  template: `
    <div class="report-table card border-0 shadow-sm">
      <div class="card-body p-0">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom bg-light" *ngIf="title || searchable">
          <h6 class="mb-0 fw-semibold" *ngIf="title">{{ title }}</h6>
          <div class="d-flex gap-2 align-items-center">
            <span class="badge bg-primary" *ngIf="filteredData.length">{{ filteredData.length }} records</span>
            <div class="input-group input-group-sm" style="width: 220px" *ngIf="searchable">
              <span class="input-group-text"><i class="bi bi-search"></i></span>
              <input type="text" class="form-control" placeholder="Search..." [(ngModel)]="searchTerm" (input)="applySearch()">
            </div>
          </div>
        </div>

        <!-- Table -->
        <div class="table-responsive">
          <table class="table table-hover table-sm mb-0 align-middle">
            <thead class="table-light">
            <tr>
              <th *ngFor="let col of columns" class="px-3 py-2 small text-uppercase text-muted fw-semibold"
                  [class.sortable]="sortable" (click)="sortable && sort(col.key)"
                  [style.cursor]="sortable ? 'pointer' : 'default'"
                  [style.minWidth]="col.width || 'auto'">
                {{ col.label }}
                <i *ngIf="sortable && sortKey === col.key" class="bi ms-1"
                   [class.bi-sort-up]="sortDir === 'asc'" [class.bi-sort-down]="sortDir === 'desc'"></i>
              </th>
            </tr>
            </thead>
            <tbody>
            <tr *ngFor="let row of pagedData; let ri = index; trackBy: trackRow">
              <td *ngFor="let col of columns" class="px-3 py-2">

                <!-- Drilldown link (clickable number) -->
                <a *ngIf="col.type === 'drilldown' && row[col.key]"
                   class="fw-semibold text-primary text-decoration-none drilldown-link"
                   (click)="onCellClick(row, col, $event)" role="button">
                  {{ dp.transform(row[col.key], '1.0-0') }}
                  <i class="bi bi-box-arrow-up-right ms-1 small"></i>
                </a>
                <span *ngIf="col.type === 'drilldown' && !row[col.key]" class="text-muted">0</span>

                <!-- Badge rendering -->
                <span *ngIf="col.type === 'badge'" class="badge"
                      [class]="getBadgeClass(row[col.key], row[col.badgeKey || ''])">
                    {{ formatCell(row[col.key], col) }}
                  </span>

                <!-- Progress bar -->
                <div *ngIf="col.type === 'progress'" class="d-flex align-items-center gap-2">
                  <div class="progress flex-grow-1" style="height: 6px; min-width: 60px">
                    <div class="progress-bar" [class]="getProgressClass(row[col.key])"
                         [style.width.%]="row[col.key] || 0"></div>
                  </div>
                  <span class="small text-muted">{{ row[col.key] | number:'1.0-1' }}%</span>
                </div>

                <!-- Default text -->
                <span *ngIf="!col.type || col.type === 'text'">{{ formatCell(row[col.key], col) }}</span>
                <!-- Number -->
                <span *ngIf="col.type === 'number'">{{ dp.transform(row[col.key], col.format || '1.0-0') }}</span>
                <!-- Date -->
                <span *ngIf="col.type === 'date'" class="text-muted small">{{ datePipe.transform(row[col.key], col.format || 'mediumDate') }}</span>
              </td>
            </tr>
            <tr *ngIf="!filteredData.length">
              <td [attr.colspan]="columns.length" class="text-center py-5 text-muted">
                <i class="bi bi-inbox fs-2 d-block mb-2"></i>No data available
              </td>
            </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <div class="d-flex justify-content-between align-items-center px-3 py-2 border-top bg-light" *ngIf="totalPages > 1">
          <span class="small text-muted">
            Page {{ currentPage }} of {{ totalPages }} ({{ filteredData.length }} total)
          </span>
          <nav>
            <ul class="pagination pagination-sm mb-0">
              <li class="page-item" [class.disabled]="currentPage <= 1">
                <a class="page-link" (click)="goToPage(currentPage - 1)"><i class="bi bi-chevron-left"></i></a>
              </li>
              <li *ngFor="let p of pageNumbers" class="page-item" [class.active]="p === currentPage">
                <a class="page-link" (click)="goToPage(p)">{{ p }}</a>
              </li>
              <li class="page-item" [class.disabled]="currentPage >= totalPages">
                <a class="page-link" (click)="goToPage(currentPage + 1)"><i class="bi bi-chevron-right"></i></a>
              </li>
            </ul>
          </nav>
        </div>
      </div>
    </div>
  `,
  styles: [`
    .sortable:hover { background-color: #e9ecef; }
    .page-link { cursor: pointer; }
    .drilldown-link { cursor: pointer; }
    .drilldown-link:hover { text-decoration: underline !important; }
  `]
})
export class ReportTableComponent implements OnChanges {
  @Input() data: any[] = [];
  @Input() meta?: ReportMeta;
  @Input() title = '';
  @Input() sortable = true;
  @Input() searchable = true;
  @Input() pageSize = 25;
  @Input() customColumns?: TableColumn[];
  /** Columns whose numeric values should be rendered as clickable drilldown links */
  @Input() drilldownColumns: string[] = [];
  @Output() pageChange = new EventEmitter<number>();
  /** Emitted when a drilldown cell is clicked: { row, column, value } */
  @Output() cellClick = new EventEmitter<{ row: any; column: TableColumn; value: any }>();

  columns: TableColumn[] = [];
  filteredData: any[] = [];
  pagedData: any[] = [];
  searchTerm = '';
  sortKey = '';
  sortDir: 'asc' | 'desc' = 'asc';
  currentPage = 1;

  constructor(public dp: DecimalPipe, public datePipe: DatePipe) {}

  ngOnChanges(changes: SimpleChanges): void {
    if (changes['data'] || changes['customColumns'] || changes['drilldownColumns']) {
      this.columns = this.customColumns || this.autoDetectColumns();
      this.filteredData = [...(this.data || [])];
      this.currentPage = 1;
      this.applySearch();
    }
  }

  onCellClick(row: any, col: TableColumn, event: Event): void {
    event.preventDefault();
    this.cellClick.emit({ row, column: col, value: row[col.key] });
  }

  /* ═══════ AUTO COLUMN DETECTION ═══════ */

  private autoDetectColumns(): TableColumn[] {
    if (!this.data?.length) return [];
    const sample = this.data[0];
    const cols: TableColumn[] = [];

    for (const [key, value] of Object.entries(sample)) {
      if (key === 'tenant_id' || key.startsWith('_')) continue;
      if (value !== null && typeof value === 'object' && !Array.isArray(value)) continue;

      const col: TableColumn = { key, label: this.formatLabel(key) };

      if (key.endsWith('_id') && key !== 'id') continue;

      // Drilldown columns take priority
      if (this.drilldownColumns.includes(key)) {
        col.type = 'drilldown';
      } else if (key.endsWith('_rate') || key === 'completion_rate' || key === 'compliance_rate' || key.includes('percent')) {
        col.type = 'progress';
      } else if (key === 'status' || key === 'freshness' || key === 'engagement' || key === 'effectiveness'
        || key === 'badge' || key === 'percentile' || key === 'tier' || key === 'speed_tier'
        || key === 'risk_level') {
        col.type = 'badge';
      } else if (key.includes('_at') || key.includes('date') || key === 'created_at' || key === 'last_accessed') {
        col.type = 'date';
      } else if (typeof value === 'number' && !key.endsWith('_id')) {
        col.type = 'number';
      }

      cols.push(col);
    }
    return cols;
  }

  /* ═══════ SORTING ═══════ */

  sort(key: string): void {
    if (this.sortKey === key) {
      this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
    } else {
      this.sortKey = key;
      this.sortDir = 'asc';
    }
    this.filteredData.sort((a, b) => {
      const va = a[key], vb = b[key];
      if (va == null) return 1;
      if (vb == null) return -1;
      const cmp = typeof va === 'number' ? va - vb : String(va).localeCompare(String(vb));
      return this.sortDir === 'asc' ? cmp : -cmp;
    });
    this.updatePage();
  }

  /* ═══════ SEARCH ═══════ */

  applySearch(): void {
    if (!this.searchTerm.trim()) {
      this.filteredData = [...(this.data || [])];
    } else {
      const term = this.searchTerm.toLowerCase();
      this.filteredData = (this.data || []).filter(row =>
        Object.values(row).some(v => v != null && String(v).toLowerCase().includes(term))
      );
    }
    this.currentPage = 1;
    this.updatePage();
  }

  /* ═══════ PAGINATION ═══════ */

  get totalPages(): number {
    if (this.meta) return this.meta.last_page || 1;
    return Math.ceil(this.filteredData.length / this.pageSize) || 1;
  }

  get pageNumbers(): number[] {
    const total = this.totalPages;
    const current = this.meta ? this.meta.page : this.currentPage;
    const pages: number[] = [];
    const start = Math.max(1, current - 2);
    const end = Math.min(total, current + 2);
    for (let i = start; i <= end; i++) pages.push(i);
    return pages;
  }

  goToPage(page: number): void {
    if (page < 1 || page > this.totalPages) return;
    if (this.meta) {
      this.pageChange.emit(page);
    } else {
      this.currentPage = page;
      this.updatePage();
    }
  }

  private updatePage(): void {
    if (this.meta) {
      this.pagedData = this.filteredData;
    } else {
      const start = (this.currentPage - 1) * this.pageSize;
      this.pagedData = this.filteredData.slice(start, start + this.pageSize);
    }
  }

  /* ═══════ FORMATTING ═══════ */

  formatCell(value: any, col: TableColumn): string {
    if (value == null) return '-';
    if (typeof value === 'object' && !Array.isArray(value)) {
      return value.label || value.name || value.title || JSON.stringify(value);
    }
    if (Array.isArray(value)) return value.join(', ');
    if (col.type === 'badge') return String(value).replace(/_/g, ' ');
    return String(value);
  }

  getBadgeClass(value: any, badgeHint?: string): string {
    const v = String(value || badgeHint || '').toLowerCase();
    if (['completed', 'success', 'active', 'passed', 'on_track', 'above_average', 'high', 'fresh', 'excellent', 'compliant'].some(s => v.includes(s)))
      return 'bg-success-subtle text-success';
    if (['in_progress', 'warning', 'pending', 'needs_attention', 'aging', 'medium', 'fair', 'normal'].some(s => v.includes(s)))
      return 'bg-warning-subtle text-warning';
    if (['overdue', 'failed', 'danger', 'expired', 'at_risk', 'below_average', 'low', 'stale', 'poor', 'slow', 'critical'].some(s => v.includes(s)))
      return 'bg-danger-subtle text-danger';
    return 'bg-secondary-subtle text-secondary';
  }

  getProgressClass(value: number): string {
    if (value >= 80) return 'bg-success';
    if (value >= 50) return 'bg-warning';
    return 'bg-danger';
  }

  trackRow(index: number, item: any): any { return item.id || item.user_id || index; }

  private formatLabel(key: string): string {
    return key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
      .replace(/\bId\b/, 'ID').replace(/\bNps\b/, 'NPS');
  }
}

export interface TableColumn {
  key: string;
  label: string;
  type?: 'text' | 'number' | 'date' | 'badge' | 'progress' | 'drilldown';
  format?: string;
  width?: string;
  badgeKey?: string;
}
