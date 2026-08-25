import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { ReportService } from '../../services/report.service';
import { ReportChartComponent } from '../report-chart/report-chart.component';
import { ReportTableComponent } from '../report-table/report-table.component';
import {AdmintopComponent} from '../../../partials/top/admintop/admintop.component';

@Component({
  selector: 'app-custom-report-builder',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink, ReportChartComponent, ReportTableComponent, AdmintopComponent],
  template: `
    <app-admintop></app-admintop>
    <main class="main px-lg-6">
      <div class="container-fluid">
            <div class="custom-builder">
      <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="fw-bold mb-0"><i class="bi bi-tools me-2"></i>Custom Report Builder</h4>
        <div class="d-flex gap-2">
          <a routerLink="/admin/reports" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
          <button class="btn btn-sm" [class]="activeTab==='build'?'btn-primary':'btn-primary'" (click)="activeTab='build'">
            <i class="bi bi-hammer me-1"></i>Builder
          </button>
          <button class="btn btn-sm" [class]="activeTab==='saved'?'btn-primary':'btn-primary'" (click)="activeTab='saved'; loadSavedReports()">
            <i class="bi bi-collection me-1"></i>Saved Reports
            <span class="badge bg-white text-primary ms-1" *ngIf="savedReports.length">{{ savedReports.length }}</span>
          </button>
        </div>
      </div>

      <!-- ═══════ TAB: BUILDER ═══════ -->
      <div *ngIf="activeTab === 'build'">
        <div class="row g-4">
          <!-- Config Panel -->
          <div class="col-12 mb-5">
            <div class="card border-0 shadow-sm">
              <div class="card-body">
                <!-- Editing indicator -->
                <div class="alert alert-info py-2 small d-flex justify-content-between align-items-center" *ngIf="editingReport">
                  <span><i class="bi bi-pencil me-1"></i>Editing: <strong>{{ editingReport.name }}</strong></span>
                  <button class="btn btn-sm btn-outline-info" (click)="clearEdit()"><i class="bi bi-x"></i></button>
                </div>
                <div class="row">
                  <div class="col-3">
                    <!-- Data Source -->
                    <div class="mb-3">
                      <label class="form-label fw-semibold small">Data Source</label>
                      <select class="form-select form-select-sm" [(ngModel)]="config.source" (change)="onSourceChange()">
                        <option value="">Select source...</option>
                        <option *ngFor="let s of sources" [value]="s.key">{{ s.label }}</option>
                      </select>
                    </div>
                  </div>
                  <div class="col-3">
                    <!-- Columns -->
                    <div class="mb-3" *ngIf="availableColumns.length">
                      <label class="form-label fw-semibold small">Columns</label>
                      <div class="border rounded p-2" style="max-height: 200px; overflow-y: auto">
                        <div *ngFor="let col of availableColumns" class="form-check">
                          <input type="checkbox" class="form-check-input" [id]="'col_'+col.key"
                                 [checked]="config.columns.includes(col.key)" (change)="toggleColumn(col.key)">
                          <label class="form-check-label small" [for]="'col_'+col.key">{{ col.label }}</label>
                        </div>
                      </div>
                      <div class="d-flex gap-2 mt-1">
                        <button class="btn btn-link btn-sm p-0 small" (click)="selectAllColumns()">Select All</button>
                        <button class="btn btn-link btn-sm p-0 small text-muted" (click)="config.columns = []">Clear</button>
                      </div>
                    </div>
                  </div>
                  <div class="col-3">
                    <!-- Group By -->
                    <div class="mb-3" *ngIf="availableGroupings.length">
                      <label class="form-label fw-semibold small">Group By</label>
                      <select class="form-select form-select-sm" [(ngModel)]="config.group_by">
                        <option value="">No grouping</option>
                        <option *ngFor="let g of availableGroupings" [value]="g.key">{{ g.label }}</option>
                      </select>
                    </div>
                  </div>
                  <div class="col-3">
                    <!-- Sort -->
                    <div class="mb-3" *ngIf="config.columns.length">
                      <label class="form-label fw-semibold small">Sort By</label>
                      <div class="d-flex gap-2">
                        <select class="form-select form-select-sm" [(ngModel)]="config.sort_by">
                          <option value="">Default</option>
                          <option *ngFor="let col of config.columns" [value]="col">{{ formatKey(col) }}</option>
                        </select>
                        <select class="form-select form-select-sm" [(ngModel)]="config.sort_dir" style="width:90px">
                          <option value="asc">ASC</option>
                          <option value="desc">DESC</option>
                        </select>
                      </div>
                    </div>
                    <!-- Limit -->
                    <div class="mb-3">
                      <label class="form-label fw-semibold small">Limit</label>
                      <select class="form-select form-select-sm" [(ngModel)]="config.per_page">
                        <option [ngValue]="25">25</option>
                        <option [ngValue]="50">50</option>
                        <option [ngValue]="100">100</option>
                        <option [ngValue]="500">500</option>
                      </select>
                    </div>
                    <!-- Actions -->
                    <div class="d-flex gap-2">
                      <button class="btn btn-primary btn-sm flex-grow-1" (click)="run()"
                              [disabled]="!config.source || !config.columns.length || building">
                        <span *ngIf="building" class="spinner-border spinner-border-sm me-1"></span>
                        <i *ngIf="!building" class="bi bi-play-fill me-1"></i>Run
                      </button>
                      <button class="btn btn-success btn-sm" (click)="openSaveDialog()" [disabled]="!result"
                              title="Save Report">
                        <i class="bi bi-floppy me-1"></i>Save
                      </button>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Results -->
          <div class="col-12">
            <div *ngIf="building" class="text-center py-5"><div class="spinner-border text-primary"></div><p class="mt-2 text-muted">Building report...</p></div>
            <div *ngIf="error" class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>{{ error }}</div>
            <div *ngIf="result && !building">
              <app-report-chart [data]="result" chartType="bar" [allowTypeChange]="true" class="d-block mb-4"></app-report-chart>
              <app-report-table [data]="result.data || result" [title]="'Results'" [sortable]="true" [searchable]="true"></app-report-table>
            </div>
            <div *ngIf="!result && !building && !error" class="text-center py-5 text-muted">
              <i class="bi bi-tools fs-1 d-block mb-2"></i>
              <p>Select a data source, choose columns, and click <strong>Run</strong> to build your report.</p>
            </div>
          </div>
        </div>
      </div>


      <!-- ═══════ TAB: SAVED REPORTS ═══════ -->
      <div *ngIf="activeTab === 'saved'">
        <div *ngIf="loadingSaved" class="text-center py-5"><div class="spinner-border text-primary"></div></div>

        <div *ngIf="!loadingSaved && !savedReports.length" class="text-center py-5 text-muted">
          <i class="bi bi-collection fs-1 d-block mb-2"></i>
          <p>No saved custom reports yet. Build a report and save it to see it here.</p>
        </div>

        <!-- Saved reports list -->
        <div class="row g-3" *ngIf="!loadingSaved && savedReports.length">
          <div *ngFor="let r of savedReports" class="col-sm-6 col-md-4">
            <div class="card border-0 shadow-sm h-100">
              <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-2">
                  <h6 class="fw-semibold mb-0">{{ r.name }}</h6>
                  <!-- Actions dropdown -->
                  <div class="dropdown">
                    <button class="btn btn-sm btn-light" data-bs-toggle="dropdown"><i class="bi bi-three-dots-vertical"></i></button>
                    <ul class="dropdown-menu dropdown-menu-end">
                      <li><a class="dropdown-item" (click)="runSaved(r)"><i class="bi bi-play-fill me-2 text-primary"></i>Run</a></li>
                      <li><a class="dropdown-item" (click)="editSaved(r)"><i class="bi bi-pencil me-2 text-warning"></i>Edit</a></li>
                      <li><a class="dropdown-item" (click)="duplicateSaved(r)"><i class="bi bi-copy me-2 text-info"></i>Duplicate</a></li>
                      <li><hr class="dropdown-divider"></li>
                      <li><a class="dropdown-item" (click)="exportSaved(r, 'csv')"><i class="bi bi-filetype-csv me-2"></i>Export CSV</a></li>
                      <li><a class="dropdown-item" (click)="exportSaved(r, 'xlsx')"><i class="bi bi-file-earmark-excel me-2"></i>Export Excel</a></li>
                      <li><a class="dropdown-item" (click)="exportSaved(r, 'pdf')"><i class="bi bi-file-earmark-pdf me-2"></i>Export PDF</a></li>
                      <li><hr class="dropdown-divider"></li>
                      <li><a class="dropdown-item text-danger" (click)="deleteSaved(r)"><i class="bi bi-trash me-2"></i>Delete</a></li>
                    </ul>
                  </div>
                </div>
                <p class="text-muted small mb-2" *ngIf="r.description">{{ r.description }}</p>
                <div class="d-flex flex-wrap gap-1 mb-2">
                  <span class="badge bg-primary-subtle text-primary" style="font-size:.65rem">{{ r.source || r.config?.source }}</span>
                  <span class="badge bg-light text-muted" style="font-size:.65rem">
                    {{ (r.config?.columns?.length || 0) }} columns
                  </span>
                </div>
                <p class="text-muted small mb-0" *ngIf="r.created_at">Created {{ r.created_at | date:'mediumDate' }}</p>
              </div>
              <div class="card-footer bg-transparent border-top-0 pt-0">
                <button class="btn btn-sm btn-primary w-100" (click)="runSaved(r)">
                  <i class="bi bi-play-fill me-1"></i>Run Report
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Run result for saved report -->
        <div *ngIf="savedResult" class="mt-4">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-semibold mb-0">
              <i class="bi bi-bar-chart me-2"></i>{{ savedResultName }}
            </h5>
            <button class="btn btn-sm btn-secondary" (click)="savedResult = null">
              <i class="bi bi-x-lg me-1"></i>Close
            </button>
          </div>
          <app-report-chart [data]="savedResult" chartType="bar" [allowTypeChange]="true" class="d-block mb-4"></app-report-chart>
          <app-report-table [data]="savedResult.data || savedResult" [title]="''" [sortable]="true" [searchable]="true"></app-report-table>
        </div>
      </div>

      <!-- ═══════ SAVE DIALOG (modal) ═══════ -->
      <div class="modal fade" id="saveCustomModal" tabindex="-1" *ngIf="showSaveDialog">
        <div class="modal-dialog">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">{{ editingReport ? 'Update' : 'Save' }} Custom Report</h5>
              <button type="button" class="btn-close" (click)="showSaveDialog = false"></button>
            </div>
            <div class="modal-body">
              <div class="mb-3">
                <label class="form-label small fw-semibold">Report Name</label>
                <input type="text" class="form-control form-control-sm" [(ngModel)]="saveName" placeholder="My Custom Report">
              </div>
              <div class="mb-3">
                <label class="form-label small fw-semibold">Description (optional)</label>
                <textarea class="form-control form-control-sm" rows="2" [(ngModel)]="saveDescription"
                          placeholder="Brief description of this report..."></textarea>
              </div>
            </div>
            <div class="modal-footer">
              <button class="btn btn-sm btn-secondary" (click)="showSaveDialog = false">Cancel</button>
              <button class="btn btn-sm btn-primary" (click)="confirmSave()" [disabled]="!saveName.trim() || saving">
                <span *ngIf="saving" class="spinner-border spinner-border-sm me-1"></span>
                {{ editingReport ? 'Update' : 'Save' }}
              </button>
            </div>
          </div>
        </div>
      </div>
      <!-- Backdrop -->
      <div class="modal-backdrop fade show" *ngIf="showSaveDialog" (click)="showSaveDialog = false"></div>
    </div>
      </div>
    </main>
  `,
  styles: [`
    .modal.fade { display: block; opacity: 1; }
    .modal-dialog { margin-top: 10vh; }
  `]
})
export class CustomReportBuilderComponent implements OnInit {
  activeTab: 'build' | 'saved' = 'build';

  // Builder state
  sources: any[] = [];
  availableColumns: any[] = [];
  availableGroupings: any[] = [];
  config: any = { source: '', columns: [], group_by: '', sort_by: '', sort_dir: 'asc', per_page: 50, filters: {} };
  result: any = null;
  building = false;
  error = '';

  // Saved reports state
  savedReports: any[] = [];
  loadingSaved = false;
  savedResult: any = null;
  savedResultName = '';

  // Save/edit dialog state
  showSaveDialog = false;
  saveName = '';
  saveDescription = '';
  saving = false;
  editingReport: any = null;

  constructor(private svc: ReportService) {}

  ngOnInit(): void {
    this.svc.getCustomSources().subscribe(s => this.sources = s);
  }

  /* ═══════ BUILDER ═══════ */

  onSourceChange(): void {
    this.availableColumns = [];
    this.availableGroupings = [];
    this.config.columns = [];
    this.result = null;
    if (!this.config.source) return;
    this.svc.getCustomColumns(this.config.source).subscribe((res: any) => {
      this.availableColumns = res.columns || res || [];
      this.availableGroupings = res.groupings || [];
    });
  }

  toggleColumn(key: string): void {
    const idx = this.config.columns.indexOf(key);
    if (idx >= 0) this.config.columns.splice(idx, 1);
    else this.config.columns.push(key);
  }

  selectAllColumns(): void {
    this.config.columns = this.availableColumns.map((c: any) => c.key);
  }

  run(): void {
    this.building = true;
    this.error = '';
    this.svc.buildCustomReport(this.config).subscribe({
      next: r => { this.result = r; this.building = false; },
      error: e => { this.error = e?.error?.message || e?.error?.error || 'Build failed'; this.building = false; }
    });
  }

  /* ═══════ SAVE / UPDATE ═══════ */

  openSaveDialog(): void {
    this.saveName = this.editingReport?.name || '';
    this.saveDescription = this.editingReport?.description || '';
    this.showSaveDialog = true;
  }

  confirmSave(): void {
    if (!this.saveName.trim()) return;
    this.saving = true;

    const obs = this.editingReport
      ? this.svc.updateCustomReport(this.editingReport.id, this.saveName, this.saveDescription, this.config)
      : this.svc.saveCustomReport(this.saveName, this.saveDescription, this.config);

    obs.subscribe({
      next: () => {
        this.saving = false;
        this.showSaveDialog = false;
        this.loadSavedReports();
      },
      error: () => { this.saving = false; }
    });
  }

  /* ═══════ SAVED REPORTS CRUD ═══════ */

  loadSavedReports(): void {
    this.loadingSaved = true;
    this.svc.getCustomReportsList().subscribe(list => {
      this.savedReports = list;
      this.loadingSaved = false;
    });
  }

  runSaved(report: any): void {
    this.savedResultName = report.name;
    this.savedResult = null;
    this.svc.runSavedCustomReport(report.id).subscribe({
      next: r => this.savedResult = r,
      error: () => this.savedResult = { data: [], error: 'Failed to run report' }
    });
  }

  editSaved(report: any): void {
    this.editingReport = report;
    this.activeTab = 'build';

    // Load config into builder
    const cfg = report.config || {};
    this.config = {
      source: cfg.source || report.source || '',
      columns: cfg.columns || [],
      group_by: cfg.group_by || '',
      sort_by: cfg.sort_by || '',
      sort_dir: cfg.sort_dir || 'asc',
      limit: cfg.limit || 50,
      filters: cfg.filters || {}
    };

    // Re-fetch columns for this source
    if (this.config.source) {
      this.svc.getCustomColumns(this.config.source).subscribe((res: any) => {
        this.availableColumns = res.columns || res || [];
        this.availableGroupings = res.groupings || [];
      });
    }

    this.result = null;
  }

  clearEdit(): void {
    this.editingReport = null;
    this.config = { source: '', columns: [], group_by: '', sort_by: '', sort_dir: 'asc', limit: 50, filters: {} };
    this.availableColumns = [];
    this.availableGroupings = [];
    this.result = null;
  }

  duplicateSaved(report: any): void {
    this.svc.duplicateCustomReport(report.id).subscribe({
      next: () => this.loadSavedReports(),
      error: () => alert('Failed to duplicate report')
    });
  }

  exportSaved(report: any, format: string): void {
    this.svc.exportCustomReport(report.id, format).subscribe({
      next: (blob) => {
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `${report.name.replace(/\s+/g, '_')}_${new Date().toISOString().split('T')[0]}.${format}`;
        a.click();
        window.URL.revokeObjectURL(url);
      },
      error: () => alert('Export failed')
    });
  }

  deleteSaved(report: any): void {
    if (!confirm(`Delete "${report.name}"? This cannot be undone.`)) return;
    this.svc.deleteCustomReport(report.id).subscribe({
      next: () => {
        this.savedReports = this.savedReports.filter(r => r.id !== report.id);
        if (this.editingReport?.id === report.id) this.clearEdit();
      },
      error: () => alert('Failed to delete report')
    });
  }

  formatKey(k: string): string { return k.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()); }
}
