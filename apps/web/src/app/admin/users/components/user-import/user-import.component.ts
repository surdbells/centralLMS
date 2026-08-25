// users/components/user-import/user-import.component.ts
import { Component } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { UserService } from '../../services/user.service';
import { ImportPreview, ImportRow, ImportResult } from '../../models/user.models';
import {AdmintopComponent} from '../../../../partials/top/admintop/admintop.component';

@Component({
  selector: 'app-user-import',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink, AdmintopComponent],
  template: `
    <app-admintop></app-admintop>
    <main class="main px-lg-6">
      <div class="container-fluid">
            <div class="user-import">
      <div class="d-flex justify-content-between align-items-center mb-4">
        <div class="d-flex align-items-center gap-3">
          <a routerLink="/admin/users" class="btn btn-sm btn-secondary"><i class="bi bi-arrow-left"></i></a>
          <h4 class="fw-bold mb-0"><i class="bi bi-upload me-2"></i>Import Users</h4>
        </div>
        <button class="btn btn-primary btn-sm" (click)="downloadTemplate()"><i class="bi bi-download me-1"></i>Download Template</button>
      </div>

      <!-- Steps indicator -->
      <div class="d-flex gap-3 mb-4">
        <div *ngFor="let s of stepLabels; let i = index" class="d-flex align-items-center gap-2">
          <span class="badge rounded-pill" [class]="step > i ? 'bg-success' : step === i ? 'bg-primary' : 'bg-light text-muted'">{{ i + 1 }}</span>
          <small [class.fw-semibold]="step === i">{{ s }}</small>
          <i class="bi bi-chevron-right text-muted" *ngIf="i < stepLabels.length - 1"></i>
        </div>
      </div>

      <!-- Step 1: Upload -->
      <div *ngIf="step === 0" class="card border-0 shadow-sm">
        <div class="card-body text-center py-5">
          <div class="drop-zone p-5 border border-2 border-dashed rounded-3 mb-3" [class.border-primary]="dragging"
               (dragover)="onDragOver($event)" (dragleave)="dragging=false" (drop)="onDrop($event)" (click)="fileInput.click()">
            <i class="bi bi-cloud-upload fs-1 text-primary d-block mb-2"></i>
            <p class="mb-1 fw-semibold">Drop CSV or Excel file here</p>
            <p class="text-muted small mb-0">or click to browse</p>
          </div>
          <input #fileInput type="file" accept=".csv,.xlsx,.xls" class="d-none" (change)="onFileSelect($event)">
          <div *ngIf="selectedFile" class="d-flex align-items-center justify-content-center gap-2 mt-2">
            <i class="bi bi-file-earmark-spreadsheet text-success"></i>
            <span class="small">{{ selectedFile.name }} ({{ formatSize(selectedFile.size) }})</span>
            <button class="btn btn-sm btn-outline-danger" (click)="selectedFile = null"><i class="bi bi-x"></i></button>
          </div>
          <button class="btn btn-primary mt-3" [disabled]="!selectedFile || uploading" (click)="upload()">
            <span *ngIf="uploading" class="spinner-border spinner-border-sm me-1"></span>
            {{ uploading ? 'Validating...' : 'Upload & Validate' }}
          </button>
          <div class="alert alert-danger mt-3 text-start" *ngIf="uploadError"><i class="bi bi-exclamation-triangle me-2"></i>{{ uploadError }}</div>
        </div>
      </div>

      <!-- Step 2: Preview -->
      <div *ngIf="step === 1 && preview">
        <!-- Stats -->
        <div class="row g-3 mb-3">
          <div class="col"><div class="card border-0 shadow-sm text-center py-3"><h5 class="mb-0 fw-bold">{{ preview.stats.total }}</h5><small class="text-muted">Total Rows</small></div></div>
          <div class="col"><div class="card border-0 shadow-sm text-center py-3"><h5 class="mb-0 fw-bold text-success">{{ preview.stats.valid }}</h5><small class="text-muted">Valid</small></div></div>
          <div class="col"><div class="card border-0 shadow-sm text-center py-3"><h5 class="mb-0 fw-bold text-warning">{{ preview.stats.warnings }}</h5><small class="text-muted">Warnings</small></div></div>
          <div class="col"><div class="card border-0 shadow-sm text-center py-3"><h5 class="mb-0 fw-bold text-danger">{{ preview.stats.errors }}</h5><small class="text-muted">Errors</small></div></div>
          <div class="col"><div class="card border-0 shadow-sm text-center py-3"><h5 class="mb-0 fw-bold text-info">{{ preview.stats.duplicates }}</h5><small class="text-muted">Existing (Update)</small></div></div>
        </div>

        <!-- Preview table -->
        <div class="card border-0 shadow-sm">
          <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <span class="fw-semibold">Preview (showing {{ preview.preview.length }} of {{ preview.total_rows }})</span>
            <div class="d-flex gap-2">
              <select class="form-select form-select-sm" style="width:auto" [(ngModel)]="previewFilter">
                <option value="all">All rows</option>
                <option value="valid">Valid only</option>
                <option value="warning">Warnings</option>
                <option value="error">Errors</option>
              </select>
            </div>
          </div>
          <div class="table-responsive" style="max-height:400px">
            <table class="table table-sm table-hover mb-0">
              <thead class="table-light sticky-top">
                <tr>
                  <th style="width:40px">#</th>
                  <th style="width:70px">Status</th>
                  <th>Name</th><th>Email</th><th>Department</th><th>Role</th>
                  <th>Issues</th>
                </tr>
              </thead>
              <tbody>
                <ng-container *ngFor="let row of filteredPreview">
                  <tr [class.table-danger]="row.status==='error'" [class.table-warning]="row.status==='warning'">
                    <td><small>{{ row.row_number }}</small></td>
                    <td>
                      <i class="bi" [class]="row.status==='valid' ? 'bi-check-circle text-success' : row.status==='warning' ? 'bi-exclamation-triangle text-warning' : 'bi-x-circle text-danger'"></i>
                    </td>
                    <td><small>{{ row.data.first_name }} {{ row.data.last_name || '' }}</small></td>
                    <td><small>{{ row.data.email }}</small></td>
                    <td><small>{{ row.data.department || '-' }}</small></td>
                    <td><small>{{ row.data.role || '-' }}</small></td>
                    <td>
                      <small class="text-danger" *ngFor="let e of row.errors">{{ e }}<br></small>
                      <small class="text-warning" *ngFor="let w of row.warnings">{{ w }}<br></small>
                    </td>
                  </tr>
                </ng-container>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Import options -->
        <div class="card border-0 shadow-sm mt-3">
          <div class="card-body d-flex align-items-center gap-4">
            <div class="form-check">
              <input type="checkbox" class="form-check-input" id="skipErrors" [(ngModel)]="importOptions.skip_errors">
              <label class="form-check-label small" for="skipErrors">Skip error rows</label>
            </div>
            <div class="form-check">
              <input type="checkbox" class="form-check-input" id="updateExisting" [(ngModel)]="importOptions.update_existing">
              <label class="form-check-label small" for="updateExisting">Update existing users</label>
            </div>
            <div class="ms-auto d-flex gap-2">
              <button class="btn btn-secondary btn-sm" (click)="step=0; preview=null; selectedFile=null">
                <i class="bi bi-arrow-left me-1"></i>Back
              </button>
              <button class="btn btn-primary" [disabled]="executing || preview.stats.valid === 0" (click)="executeImport()">
                <span *ngIf="executing" class="spinner-border spinner-border-sm me-1"></span>
                {{ executing ? 'Importing...' : 'Import ' + preview.stats.valid + ' Users' }}
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Step 3: Results -->
      <div *ngIf="step === 2 && result">
        <div class="card border-0 shadow-sm">
          <div class="card-body text-center py-5">
            <i class="bi bi-check-circle-fill text-success fs-1 d-block mb-3"></i>
            <h5 class="fw-bold mb-3">Import Complete</h5>
            <div class="row g-3 justify-content-center mb-4">
              <div class="col-auto"><div class="text-center"><h4 class="fw-bold text-success mb-0">{{ result.created }}</h4><small class="text-muted">Created</small></div></div>
              <div class="col-auto"><div class="text-center"><h4 class="fw-bold text-info mb-0">{{ result.updated }}</h4><small class="text-muted">Updated</small></div></div>
              <div class="col-auto"><div class="text-center"><h4 class="fw-bold text-muted mb-0">{{ result.skipped }}</h4><small class="text-muted">Skipped</small></div></div>
              <div class="col-auto"><div class="text-center"><h4 class="fw-bold text-danger mb-0">{{ result.failed }}</h4><small class="text-muted">Failed</small></div></div>
            </div>

            <div class="text-start mx-auto" style="max-width:600px" *ngIf="result.errors.length">
              <p class="fw-semibold small text-danger"><i class="bi bi-exclamation-triangle me-1"></i>Errors:</p>
              <ul class="small text-muted">
                <li *ngFor="let e of result.errors">{{ e }}</li>
              </ul>
            </div>

            <div class="d-flex gap-2 justify-content-center mt-3">
              <a routerLink="/admin/users" class="btn btn-primary"><i class="bi bi-people me-1"></i>View Users</a>
              <button class="btn btn-secondary" (click)="reset()"><i class="bi bi-arrow-repeat me-1"></i>Import More</button>
            </div>
          </div>
        </div>
      </div>
    </div>
      </div>
    </main>
  `,
  styles: [`
    .drop-zone { cursor:pointer; transition: all .2s; }
    .drop-zone:hover, .drop-zone.border-primary { background-color: rgba(13,110,253,.05); }
    .border-dashed { border-style: dashed !important; }
  `]
})
export class UserImportComponent {
  step = 0;
  stepLabels = ['Upload File', 'Preview & Validate', 'Results'];

  selectedFile: File | null = null;
  dragging = false;
  uploading = false;
  uploadError = '';
  executing = false;

  preview: ImportPreview | null = null;
  result: ImportResult | null = null;
  previewFilter = 'all';

  importOptions = { skip_errors: true, update_existing: true };

  constructor(private svc: UserService) {}

  /* Drag & drop */
  onDragOver(e: DragEvent): void { e.preventDefault(); this.dragging = true; }
  onDrop(e: DragEvent): void {
    e.preventDefault(); this.dragging = false;
    const f = e.dataTransfer?.files?.[0];
    if (f && (f.name.endsWith('.csv') || f.name.endsWith('.xlsx') || f.name.endsWith('.xls'))) this.selectedFile = f;
  }

  onFileSelect(e: Event): void {
    const f = (e.target as HTMLInputElement).files?.[0];
    if (f) this.selectedFile = f;
  }

  upload(): void {
    if (!this.selectedFile) return;
    this.uploading = true;
    this.uploadError = '';
    this.svc.importPreview(this.selectedFile).subscribe({
      next: p => {
        this.uploading = false;
        if (!p.success) { this.uploadError = p.error || 'Validation failed'; return; }
        this.preview = p;
        this.step = 1;
      },
      error: (e) => { this.uploading = false; this.uploadError = e?.error?.message || 'Upload failed'; }
    });
  }

  executeImport(): void {
    this.executing = true;
    this.svc.importExecute(this.importOptions).subscribe({
      next: r => { this.result = r; this.step = 2; this.executing = false; },
      error: () => { this.executing = false; alert('Import failed'); }
    });
  }

  get filteredPreview(): ImportRow[] {
    if (!this.preview) return [];
    if (this.previewFilter === 'all') return this.preview.preview;
    return this.preview.preview.filter(r => r.status === this.previewFilter);
  }

  downloadTemplate(): void { this.svc.downloadTemplate(); }
  formatSize(b: number): string { return b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : (b / 1024).toFixed(1) + ' KB'; }
  reset(): void { this.step = 0; this.selectedFile = null; this.preview = null; this.result = null; this.uploadError = ''; }
}
