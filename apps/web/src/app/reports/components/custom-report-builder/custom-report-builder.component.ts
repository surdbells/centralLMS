import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { catchError, of } from 'rxjs';
import {
  ReportService,
  CustomBuilderConfig,
  CustomColumnDef,
  CustomFilterDef,
  CustomReportResponse,
  CustomSourceSchema,
  CustomSourceSummary,
  SavedCustomReport,
} from '../../services/report.service';
import { ReportChartComponent } from '../report-chart/report-chart.component';
import { ReportTableComponent, TableColumn } from '../report-table/report-table.component';
import {AdmintopComponent} from '../../../partials/top/admintop/admintop.component';
import {TranslatePipe} from '../../../translate.pipe';
import {I18nService} from '../../../i18n.service';
import { HotToastService } from '@ngneat/hot-toast';
import { ConfirmDialogService } from '../../../shared/components/confirmation-dialog/confirmation-dialog.component';
import { ApiError } from '../../../core/api';
import { SkeletonComponent } from '../../../shared/skeleton/skeleton.component';
import { SelectComponent } from '../../../shared/select/select.component';

interface LookupOption { id: string; name: string; }
interface SortOption { key: string; label: string; }

@Component({
  selector: 'app-custom-report-builder',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink, ReportChartComponent, ReportTableComponent, AdmintopComponent, TranslatePipe, SkeletonComponent, SelectComponent],
  template: `
    <app-admintop></app-admintop>
    <main class="main">
      <div class="ui-page custom-builder">
        <header class="ui-page-header">
          <a routerLink="/admin/reports" class="ui-page-header__back" [attr.aria-label]="'common.back' | translate" [title]="'common.back' | translate">
            <i class="bi bi-chevron-left"></i>
          </a>
          <div class="ui-page-header__icon" aria-hidden="true"><i class="bi bi-tools"></i></div>
          <div class="ui-page-header__titles">
            <h1>{{ 'reports.custom_report_builder' | translate }}</h1>
            <p>{{ 'reports.builder_hint_before' | translate }} {{ 'reports.run' | translate }} {{ 'reports.builder_hint_after' | translate }}</p>
          </div>
        </header>

        <nav class="ui-tabs" role="tablist">
          <button type="button" role="tab" class="ui-tab" [class.is-active]="activeTab==='build'" [attr.aria-selected]="activeTab==='build'" (click)="activeTab='build'">
            <i class="bi bi-hammer"></i> {{ 'reports.builder' | translate }}
          </button>
          <button type="button" role="tab" class="ui-tab" [class.is-active]="activeTab==='saved'" [attr.aria-selected]="activeTab==='saved'" (click)="activeTab='saved'; loadSavedReports()">
            <i class="bi bi-collection"></i> {{ 'reports.saved_reports' | translate }}
            <span class="ui-tab__count" *ngIf="savedReports.length">{{ savedReports.length }}</span>
          </button>
        </nav>

        <!-- ═══════ TAB: BUILDER ═══════ -->
        <div *ngIf="activeTab === 'build'">
          <!-- The source list failed to load: say so, rather than an empty dropdown -->
          <div *ngIf="sourcesError" class="alert alert-danger d-flex justify-content-between align-items-center gap-2">
            <span><i class="bi bi-exclamation-triangle me-2"></i>{{ sourcesError }}</span>
            <button type="button" class="btn btn-sm btn-outline-danger" (click)="loadSources()">{{ 'common.retry' | translate }}</button>
          </div>

          <!-- Config Panel -->
          <section class="ui-section">
            <!-- Editing indicator -->
            <div class="alert alert-info py-2 small d-flex justify-content-between align-items-center" *ngIf="editingReport">
              <span><i class="bi bi-pencil me-1"></i>{{ 'reports.editing_label' | translate }} <strong>{{ editingReport.name }}</strong></span>
              <button type="button" class="btn btn-sm btn-outline-secondary" (click)="clearEdit()" [attr.aria-label]="'common.close' | translate"><i class="bi bi-x"></i></button>
            </div>
            <div class="row g-3">
              <div class="col-md-6 col-xl-3">
                <!-- Data Source -->
                <div class="mb-3">
                  <label class="form-label fw-semibold small" for="crb-source">{{ 'reports.data_source' | translate }}</label>
                  <app-select inputId="crb-source" [options]="sources" bindLabel="label" bindValue="key"
                              [ngModel]="config.source" (ngModelChange)="config.source = $event ?? ''; onSourceChange()"
                              [disabled]="sourcesLoading" [loading]="sourcesLoading"
                              placeholder="{{ 'reports.select_source' | translate }}" />
                  <div class="form-text" *ngIf="sourceDescription">{{ sourceDescription }}</div>
                  <div class="form-text" *ngIf="!sourcesLoading && !sourcesError && !sources.length">{{ 'reports.no_sources' | translate }}</div>
                </div>
              </div>
              <div class="col-md-6 col-xl-3">
                <!-- Columns -->
                <div class="mb-3" *ngIf="availableColumns.length">
                  <label class="form-label fw-semibold small">{{ 'reports.columns' | translate }}</label>
                  <div class="crb-cols">
                    <div *ngFor="let col of availableColumns" class="form-check">
                      <input type="checkbox" class="form-check-input" [id]="'col_'+col.key"
                             [checked]="config.columns.includes(col.key)" (change)="toggleColumn(col.key)">
                      <label class="form-check-label small" [for]="'col_'+col.key">{{ col.label }}</label>
                    </div>
                  </div>
                  <div class="d-flex gap-2 mt-1">
                    <button type="button" class="btn btn-link btn-sm p-0 small" (click)="selectAllColumns()">{{ 'reports.select_all' | translate }}</button>
                    <button type="button" class="btn btn-link btn-sm p-0 small ui-muted" (click)="config.columns = []; resetSortIfStale()">{{ 'common.clear' | translate }}</button>
                  </div>
                </div>
              </div>
              <div class="col-md-6 col-xl-3">
                <!-- Group By -->
                <div class="mb-3" *ngIf="availableGroupings.length">
                  <label class="form-label fw-semibold small" for="crb-group">{{ 'reports.group_by' | translate }}</label>
                  <app-select inputId="crb-group" [options]="availableGroupings" bindLabel="label" bindValue="key"
                              [ngModel]="config.group_by" (ngModelChange)="config.group_by = $event ?? ''; resetSortIfStale()"
                              placeholder="{{ 'reports.no_grouping' | translate }}" [clearable]="true" />
                </div>
              </div>
              <div class="col-md-6 col-xl-3">
                <!-- Sort -->
                <div class="mb-3" *ngIf="sortOptions.length">
                  <label class="form-label fw-semibold small" for="crb-sort">{{ 'reports.sort_by' | translate }}</label>
                  <div class="d-flex gap-2">
                    <app-select inputId="crb-sort" class="flex-grow-1" style="min-width:0" [options]="sortOptionsList" bindLabel="label" bindValue="key"
                                [ngModel]="config.sort_by" (ngModelChange)="config.sort_by = $event ?? ''"
                                placeholder="{{ 'reports.default_sort' | translate }}" [clearable]="true" />
                    <app-select [options]="sortDirOpts" [searchable]="false" [(ngModel)]="config.sort_dir"
                                style="width:100px;flex:0 0 auto" [ariaLabel]="'reports.sort_by' | translate" />
                  </div>
                </div>
                <!-- Limit -->
                <div class="mb-3">
                  <label class="form-label fw-semibold small" for="crb-limit">{{ 'reports.limit' | translate }}</label>
                  <app-select inputId="crb-limit" [options]="limitOpts" [searchable]="false" [(ngModel)]="config.per_page" />
                </div>
                <!-- Actions -->
                <div class="d-flex gap-2">
                  <button type="button" class="btn btn-primary btn-sm flex-grow-1" (click)="run()"
                          [disabled]="!config.source || !config.columns.length || building">
                    <span *ngIf="building" class="spinner-border spinner-border-sm me-1"></span>
                    <i *ngIf="!building" class="bi bi-play-fill me-1"></i>{{ 'reports.run' | translate }}
                  </button>
                  <button type="button" class="btn btn-outline-secondary btn-sm" (click)="openSaveDialog()" [disabled]="!result"
                          [title]="'reports.save_report_title' | translate">
                    <i class="bi bi-floppy me-1"></i>{{ 'common.save' | translate }}
                  </button>
                </div>
              </div>
            </div>
          </section>

          <!-- Filters: whatever the chosen source can be narrowed by -->
          <section class="ui-section" *ngIf="availableFilters.length">
            <div class="ui-section__head">
              <div><h2 class="ui-section__title"><i class="bi bi-funnel me-2"></i>{{ 'reports.filters' | translate }}</h2></div>
              <div class="ui-section__actions" *ngIf="hasFilters">
                <button type="button" class="btn btn-link btn-sm p-0" (click)="clearFilters()">{{ 'paths.clear_filters' | translate }}</button>
              </div>
            </div>
            <div class="row g-3">
              <div class="col-sm-6 col-lg-3" *ngFor="let f of availableFilters">
                <label class="form-label fw-semibold small" [for]="'flt_'+f.key">{{ f.label }}</label>
                <ng-container [ngSwitch]="f.type">
                  <input *ngSwitchCase="'date'" type="date" class="form-control" [id]="'flt_'+f.key" [(ngModel)]="config.filters[f.key]">
                  <app-select *ngSwitchCase="'lookup'" [inputId]="'flt_'+f.key" [options]="lookupOptions[f.lookupKind || ''] || noOptions"
                              bindLabel="name" bindValue="id" [clearable]="true" placeholder="{{ 'common.all' | translate }}"
                              [ngModel]="config.filters[f.key]" (ngModelChange)="config.filters[f.key] = $event ?? ''" />
                  <app-select *ngSwitchCase="'course'" [inputId]="'flt_'+f.key" [options]="courseOptions"
                              bindLabel="name" bindValue="id" [clearable]="true" placeholder="{{ 'reports.all_courses' | translate }}"
                              [ngModel]="config.filters[f.key]" (ngModelChange)="config.filters[f.key] = $event ?? ''" />
                  <app-select *ngSwitchDefault [inputId]="'flt_'+f.key" [options]="f.options || noOptions"
                              [clearable]="true" placeholder="{{ 'common.all' | translate }}"
                              [ngModel]="config.filters[f.key]" (ngModelChange)="config.filters[f.key] = $event ?? ''" />
                </ng-container>
              </div>
            </div>
          </section>

          <!-- Results -->
          <app-skeleton *ngIf="building" variant="table" [rows]="6"
                        [cols]="config.columns.length > 6 ? 6 : (config.columns.length || 5)"></app-skeleton>
          <div *ngIf="error" class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>{{ error }}</div>
          <div *ngIf="result && !building">
            <p class="small ui-muted mb-2">
              {{ 'learning.showing_count' | translate:{ shown: result.shown, total: result.matching } }}
              <span *ngIf="result.note"> · {{ result.note }}</span>
            </p>
            <app-report-chart *ngIf="result.chart_config && result.data.length" [data]="result" chartType="bar" [allowTypeChange]="true" class="d-block"></app-report-chart>
            <app-report-table [data]="result.data" [customColumns]="resultColumns" [title]="'reports.results' | translate" [sortable]="true" [searchable]="true"></app-report-table>
          </div>
          <div *ngIf="!result && !building && !error" class="ui-state">
            <span class="ui-state__icon"><i class="bi bi-tools"></i></span>
            <p>{{ 'reports.builder_hint_before' | translate }} <strong>{{ 'reports.run' | translate }}</strong> {{ 'reports.builder_hint_after' | translate }}</p>
          </div>
        </div>


        <!-- ═══════ TAB: SAVED REPORTS ═══════ -->
        <div *ngIf="activeTab === 'saved'">
          <app-skeleton *ngIf="loadingSaved" variant="cards" [count]="3"></app-skeleton>

          <div *ngIf="savedError && !loadingSaved" class="alert alert-danger d-flex justify-content-between align-items-center gap-2">
            <span><i class="bi bi-exclamation-triangle me-2"></i>{{ savedError }}</span>
            <button type="button" class="btn btn-sm btn-outline-danger" (click)="loadSavedReports()">{{ 'common.retry' | translate }}</button>
          </div>

          <div *ngIf="!loadingSaved && !savedError && !savedReports.length" class="ui-state">
            <span class="ui-state__icon"><i class="bi bi-collection"></i></span>
            <p>{{ 'reports.no_custom_saved' | translate }}</p>
          </div>

          <!-- Saved reports list -->
          <div class="ui-grid crb-grid" *ngIf="!loadingSaved && savedReports.length">
            <article *ngFor="let r of savedReports" class="ui-card">
              <div class="ui-card__body">
                <div class="d-flex justify-content-between align-items-start gap-2">
                  <h3 class="ui-card__title">{{ r.name }}</h3>
                  <!-- Actions dropdown -->
                  <div class="dropdown">
                    <button type="button" class="btn btn-sm btn-outline-secondary crb-more" data-bs-toggle="dropdown" [attr.aria-label]="'messaging.more' | translate"><i class="bi bi-three-dots-vertical"></i></button>
                    <ul class="dropdown-menu dropdown-menu-end">
                      <li><a class="dropdown-item" role="button" (click)="runSaved(r)"><i class="bi bi-play-fill me-2 text-primary"></i>{{ 'reports.run' | translate }}</a></li>
                      <li><a class="dropdown-item" role="button" (click)="editSaved(r)"><i class="bi bi-pencil me-2 text-warning"></i>{{ 'common.edit' | translate }}</a></li>
                      <li><a class="dropdown-item" role="button" (click)="duplicateSaved(r)"><i class="bi bi-copy me-2 text-info"></i>{{ 'common.duplicate' | translate }}</a></li>
                      <li><hr class="dropdown-divider"></li>
                      <li><a class="dropdown-item" role="button" (click)="exportSaved(r, 'csv')"><i class="bi bi-filetype-csv me-2"></i>{{ 'reports.export_csv' | translate }}</a></li>
                      <li><a class="dropdown-item" role="button" (click)="exportSaved(r, 'xlsx')"><i class="bi bi-file-earmark-excel me-2"></i>{{ 'reports.export_excel' | translate }}</a></li>
                      <li><hr class="dropdown-divider"></li>
                      <li><a class="dropdown-item text-danger" role="button" (click)="deleteSaved(r)"><i class="bi bi-trash me-2"></i>{{ 'common.delete' | translate }}</a></li>
                    </ul>
                  </div>
                </div>
                <p class="ui-card__meta mb-0" *ngIf="r.description">{{ r.description }}</p>
                <div class="d-flex flex-wrap gap-1">
                  <span class="ui-pill ui-pill--brand crb-tag">{{ r.source_label }}</span>
                  <span class="ui-pill ui-pill--neutral crb-tag">
                    {{ 'reports.columns_count' | translate:{ count: (r.config.columns.length || 0) } }}
                  </span>
                </div>
                <p class="ui-card__meta mb-0" *ngIf="r.created_at">{{ 'reports.created_prefix' | translate }} {{ r.created_at | date:'mediumDate' }}</p>
              </div>
              <div class="ui-card__footer">
                <button type="button" class="btn btn-sm btn-primary w-100" (click)="runSaved(r)">
                  <i class="bi bi-play-fill me-1"></i>{{ 'reports.run_report' | translate }}
                </button>
              </div>
            </article>
          </div>

          <!-- Run result for saved report -->
          <div *ngIf="savedResultName" class="mt-4">
            <div class="ui-section__head">
              <div><h2 class="ui-section__title"><i class="bi bi-bar-chart me-2"></i>{{ savedResultName }}</h2></div>
              <div class="ui-section__actions">
                <button type="button" class="btn btn-sm btn-outline-secondary" (click)="closeSavedResult()">
                  <i class="bi bi-x-lg me-1"></i>{{ 'common.close' | translate }}
                </button>
              </div>
            </div>
            <app-skeleton *ngIf="runningSaved" variant="table" [rows]="6" [cols]="5"></app-skeleton>
            <div *ngIf="savedRunError" class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>{{ savedRunError }}</div>
            <ng-container *ngIf="savedResult && !runningSaved">
              <p class="small ui-muted mb-2">
                {{ 'learning.showing_count' | translate:{ shown: savedResult.shown, total: savedResult.matching } }}
                <span *ngIf="savedResult.note"> · {{ savedResult.note }}</span>
              </p>
              <app-report-chart *ngIf="savedResult.chart_config && savedResult.data.length" [data]="savedResult" chartType="bar" [allowTypeChange]="true" class="d-block"></app-report-chart>
              <app-report-table [data]="savedResult.data" [customColumns]="savedResultColumns" [title]="''" [sortable]="true" [searchable]="true"></app-report-table>
            </ng-container>
          </div>
        </div>

        <!-- ═══════ SAVE DIALOG (modal) ═══════ -->
        <div class="modal fade" id="saveCustomModal" tabindex="-1" *ngIf="showSaveDialog">
          <div class="modal-dialog">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title">{{ (editingReport ? 'reports.update_custom_report' : 'reports.save_custom_report') | translate }}</h5>
                <button type="button" class="btn-close" (click)="showSaveDialog = false" [attr.aria-label]="'common.close' | translate"></button>
              </div>
              <div class="modal-body">
                <div class="mb-3">
                  <label class="form-label small fw-semibold" for="crb-save-name">{{ 'reports.report_name' | translate }}</label>
                  <input id="crb-save-name" type="text" class="form-control" [(ngModel)]="saveName" placeholder="{{ 'reports.report_name_ph' | translate }}" maxlength="255">
                </div>
                <div class="mb-3">
                  <label class="form-label small fw-semibold" for="crb-save-desc">{{ 'reports.description_optional' | translate }}</label>
                  <textarea id="crb-save-desc" class="form-control" rows="2" [(ngModel)]="saveDescription"
                            placeholder="{{ 'reports.description_ph' | translate }}"></textarea>
                </div>
                <div *ngIf="saveError" class="alert alert-danger small py-2 mb-0">{{ saveError }}</div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" (click)="showSaveDialog = false">{{ 'common.cancel' | translate }}</button>
                <button type="button" class="btn btn-sm btn-primary" (click)="confirmSave()" [disabled]="!saveName.trim() || saving">
                  <span *ngIf="saving" class="spinner-border spinner-border-sm me-1"></span>
                  {{ (editingReport ? 'reports.update' : 'common.save') | translate }}
                </button>
              </div>
            </div>
          </div>
        </div>
        <!-- Backdrop -->
        <div class="modal-backdrop fade show" *ngIf="showSaveDialog" (click)="showSaveDialog = false"></div>
      </div>
    </main>
  `,
  styles: [`
    .modal.fade { display: block; opacity: 1; }
    .modal-dialog { margin-top: 10vh; }
    .ui-tabs { margin-bottom: 16px; }
    .crb-cols { max-height: 200px; overflow-y: auto; padding: 8px 10px; border: 1px solid var(--ui-border); border-radius: var(--ui-radius-ctl, 8px); }
    .crb-grid { gap: 12px; }
    .crb-grid .ui-card__body { gap: 8px; }
    .crb-tag { font-size: .6875rem; padding-top: 1px; padding-bottom: 1px; }
    .crb-more { padding: 2px 8px; }
    app-report-table { display: block; margin-bottom: 20px; }
  `]
})
export class CustomReportBuilderComponent implements OnInit {
  activeTab: 'build' | 'saved' = 'build';

  readonly sortDirOpts = [{ value: 'asc', label: 'ASC' }, { value: 'desc', label: 'DESC' }] as const;
  readonly limitOpts = [25, 50, 100, 500] as const;
  readonly noOptions: readonly any[] = [];
  private sortOptsCache: { key: string; cols: unknown; groups: unknown; list: SortOption[] } | null = null;

  // Sources
  sources: CustomSourceSummary[] = [];
  sourcesLoading = false;
  sourcesError = '';

  // Builder state — everything below the source dropdown comes from the
  // chosen source's schema.
  schema: CustomSourceSchema | null = null;
  availableColumns: CustomColumnDef[] = [];
  availableGroupings: Array<{ key: string; label: string }> = [];
  availableFilters: CustomFilterDef[] = [];
  lookupOptions: Record<string, LookupOption[]> = {};
  courseOptions: LookupOption[] = [];
  config: CustomBuilderConfig = emptyConfig();
  result: CustomReportResponse | null = null;
  resultColumns: TableColumn[] | undefined;
  building = false;
  error = '';

  // Saved reports state
  savedReports: SavedCustomReport[] = [];
  loadingSaved = false;
  savedError = '';
  savedResult: CustomReportResponse | null = null;
  savedResultColumns: TableColumn[] | undefined;
  savedResultName = '';
  runningSaved = false;
  savedRunError = '';

  // Save/edit dialog state
  showSaveDialog = false;
  saveName = '';
  saveDescription = '';
  saving = false;
  saveError = '';
  editingReport: SavedCustomReport | null = null;

  private lookupsRequested = false;
  private coursesRequested = false;
  /** Guards against a slow schema response for a source no longer chosen. */
  private schemaFor = '';

  constructor(private svc: ReportService, private i18n: I18nService, private toast: HotToastService, private dialog: ConfirmDialogService) {}

  ngOnInit(): void {
    this.loadSources();
  }

  /* ═══════ SOURCES & SCHEMA ═══════ */

  loadSources(): void {
    this.sourcesLoading = true;
    this.sourcesError = '';
    this.svc.getCustomSources().subscribe({
      next: (sources) => { this.sources = sources ?? []; this.sourcesLoading = false; },
      error: (e) => {
        this.sources = [];
        this.sourcesLoading = false;
        this.sourcesError = this.errorMessage(e, 'reports.sources_failed');
      },
    });
  }

  get sourceDescription(): string {
    return this.sources.find((s) => s.key === this.config.source)?.description ?? '';
  }

  get hasFilters(): boolean {
    return Object.values(this.config.filters).some((v) => v !== '' && v !== null && v !== undefined);
  }

  /**
   * What the report can be sorted by, with the labels people chose from.
   *
   * Grouped: the group, its row count, and the chosen columns that have a
   * summary. Ungrouped: the chosen columns.
   */
  get sortOptions(): SortOption[] {
    const chosen = this.availableColumns.filter((c) => this.config.columns.includes(c.key));

    if (!this.config.group_by) {
      return chosen.map((c) => ({ key: c.key, label: c.label }));
    }

    const group = this.availableGroupings.find((g) => g.key === this.config.group_by);

    return [
      ...(group ? [{ key: group.key, label: group.label }] : []),
      { key: 'records', label: this.i18n.t('reports.record_count') },
      ...chosen.filter((c) => c.aggregate !== null).map((c) => ({ key: c.key, label: c.label })),
    ];
  }

  /** sortOptions with a stable reference while its inputs are unchanged (for the select's [options]). */
  get sortOptionsList(): SortOption[] {
    const key = this.i18n.lang + '|' + this.config.group_by + '|' + this.config.columns.join(',');
    const c = this.sortOptsCache;
    if (c && c.key === key && c.cols === this.availableColumns && c.groups === this.availableGroupings) return c.list;
    const list = this.sortOptions;
    this.sortOptsCache = { key, cols: this.availableColumns, groups: this.availableGroupings, list };
    return list;
  }

  onSourceChange(): void {
    this.result = null;
    this.resultColumns = undefined;
    this.error = '';
    this.config = { ...emptyConfig(), source: this.config.source, per_page: this.config.per_page };
    this.loadSchema(true);
  }

  /**
   * Fetches the chosen source's columns, groupings and filters.
   *
   * `fresh` ticks the source's default columns and clears the filters; an
   * edit keeps the saved report's own.
   */
  private loadSchema(fresh: boolean): void {
    this.schema = null;
    this.availableColumns = [];
    this.availableGroupings = [];
    this.availableFilters = [];

    const source = this.config.source;
    this.schemaFor = source;

    if (!source) return;

    this.svc.getCustomColumns(source).subscribe({
      next: (schema) => {
        if (this.schemaFor !== source) return;

        this.schema = schema;
        this.availableColumns = schema.columns ?? [];
        this.availableGroupings = schema.groupings ?? [];
        this.availableFilters = schema.filters ?? [];

        const known = new Set(this.availableColumns.map((c) => c.key));

        this.config.columns = fresh
          ? (schema.defaultColumns ?? []).filter((k) => known.has(k))
          : this.config.columns.filter((k) => known.has(k));

        // Every filter control starts bound to a value, so a select shows its
        // "All" option rather than a blank.
        const filters: Record<string, string> = {};
        for (const f of this.availableFilters) {
          filters[f.key] = fresh ? '' : (this.config.filters[f.key] ?? '');
        }
        this.config.filters = filters;

        this.resetSortIfStale();
        this.loadFilterOptions();
      },
      error: (e) => {
        if (this.schemaFor !== source) return;
        this.error = this.errorMessage(e, 'reports.load_failed');
      },
    });
  }

  /** The departments, teams, courses… a filter dropdown lists, fetched once. */
  private loadFilterOptions(): void {
    if (!this.lookupsRequested && this.availableFilters.some((f) => f.type === 'lookup')) {
      this.lookupsRequested = true;
      this.svc.getAllFilters().pipe(catchError(() => of(null))).subscribe((all) => {
        if (!all) { this.lookupsRequested = false; return; }
        this.lookupOptions = {
          department: toOptions(all.departments),
          team: toOptions(all.teams),
          branch: toOptions(all.branches),
          category: toOptions(all.categories),
        };
      });
    }

    if (!this.coursesRequested && this.availableFilters.some((f) => f.type === 'course')) {
      this.coursesRequested = true;
      this.svc.getCourses().pipe(catchError(() => of(null))).subscribe((courses) => {
        if (!courses) { this.coursesRequested = false; return; }
        this.courseOptions = (courses ?? [])
          .map((c: any) => ({ id: String(c.id), name: String(c.title ?? c.name ?? '') }))
          .sort((a, b) => a.name.localeCompare(b.name));
      });
    }
  }

  /* ═══════ BUILDER ═══════ */

  toggleColumn(key: string): void {
    const idx = this.config.columns.indexOf(key);
    if (idx >= 0) this.config.columns.splice(idx, 1);
    else this.config.columns.push(key);
    this.resetSortIfStale();
  }

  selectAllColumns(): void {
    this.config.columns = this.availableColumns.map((c) => c.key);
  }

  clearFilters(): void {
    for (const key of Object.keys(this.config.filters)) this.config.filters[key] = '';
  }

  /** A sort on a column no longer shown would be ignored by the server; say so here too. */
  resetSortIfStale(): void {
    if (this.config.sort_by && !this.sortOptions.some((o) => o.key === this.config.sort_by)) {
      this.config.sort_by = '';
    }
  }

  run(): void {
    this.building = true;
    this.error = '';
    this.svc.buildCustomReport(this.config).subscribe({
      next: (r) => {
        this.result = r;
        this.resultColumns = toTableColumns(r.columns);
        this.building = false;
      },
      error: (e) => { this.error = this.errorMessage(e, 'reports.build_failed'); this.building = false; },
    });
  }

  /* ═══════ SAVE / UPDATE ═══════ */

  openSaveDialog(): void {
    this.saveName = this.editingReport?.name || '';
    this.saveDescription = this.editingReport?.description || '';
    this.saveError = '';
    this.showSaveDialog = true;
  }

  confirmSave(): void {
    if (!this.saveName.trim()) return;
    this.saving = true;
    this.saveError = '';

    const obs = this.editingReport
      ? this.svc.updateCustomReport(this.editingReport.id, this.saveName.trim(), this.saveDescription, this.config)
      : this.svc.saveCustomReport(this.saveName.trim(), this.saveDescription, this.config);

    obs.subscribe({
      next: (saved) => {
        this.saving = false;
        this.showSaveDialog = false;
        if (this.editingReport) this.editingReport = saved;
        this.toast.success(this.i18n.t('reports.save_success'));
        this.loadSavedReports();
      },
      error: (e) => {
        this.saving = false;
        this.saveError = this.errorMessage(e, 'reports.save_failed');
      },
    });
  }

  /* ═══════ SAVED REPORTS CRUD ═══════ */

  loadSavedReports(): void {
    this.loadingSaved = true;
    this.savedError = '';
    this.svc.getCustomReportsList().subscribe({
      next: (list) => {
        this.savedReports = list;
        this.loadingSaved = false;
      },
      error: (e) => {
        this.loadingSaved = false;
        this.savedError = this.errorMessage(e, 'reports.load_failed');
      },
    });
  }

  runSaved(report: SavedCustomReport): void {
    this.savedResultName = report.name;
    this.savedResult = null;
    this.savedRunError = '';
    this.runningSaved = true;
    this.svc.runSavedCustomReport(report.id).subscribe({
      next: (r) => {
        this.savedResult = r;
        this.savedResultColumns = toTableColumns(r.columns);
        this.runningSaved = false;
      },
      error: (e) => {
        this.runningSaved = false;
        this.savedRunError = this.errorMessage(e, 'reports.run_failed');
      },
    });
  }

  closeSavedResult(): void {
    this.savedResult = null;
    this.savedResultName = '';
    this.savedRunError = '';
  }

  editSaved(report: SavedCustomReport): void {
    this.editingReport = report;
    this.activeTab = 'build';

    const cfg = report.config;
    this.config = {
      source: cfg.source || report.source || '',
      columns: [...(cfg.columns ?? [])],
      group_by: cfg.group_by || '',
      sort_by: cfg.sort_by || '',
      sort_dir: cfg.sort_dir === 'desc' ? 'desc' : 'asc',
      per_page: cfg.per_page || cfg.limit || 50,
      filters: { ...(cfg.filters ?? {}) },
    };

    this.result = null;
    this.resultColumns = undefined;
    this.error = '';
    // Re-fetch the source's schema, keeping the saved columns and filters.
    this.loadSchema(false);
  }

  clearEdit(): void {
    this.editingReport = null;
    this.config = emptyConfig();
    this.schemaFor = '';
    this.schema = null;
    this.availableColumns = [];
    this.availableGroupings = [];
    this.availableFilters = [];
    this.result = null;
    this.resultColumns = undefined;
    this.error = '';
  }

  duplicateSaved(report: SavedCustomReport): void {
    this.svc.duplicateCustomReport(report.id).subscribe({
      next: () => this.loadSavedReports(),
      error: (e) => this.toast.error(this.errorMessage(e, 'reports.duplicate_failed')),
    });
  }

  exportSaved(report: SavedCustomReport, _format: string): void {
    this.svc.exportCustomReport(report.id).subscribe({
      next: (blob) => {
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        // The server writes CSV whichever item was clicked; name it so.
        a.download = `${report.name.replace(/[^\w-]+/g, '_')}_${new Date().toISOString().split('T')[0]}.csv`;
        a.click();
        window.URL.revokeObjectURL(url);
      },
      error: (e) => this.toast.error(this.errorMessage(e, 'reports.export_failed')),
    });
  }

  async deleteSaved(report: SavedCustomReport): Promise<void> {
    if (!(await this.dialog.confirm(this.i18n.t('reports.confirm_delete_undone', { name: report.name }), { type: 'danger' }))) return;
    this.svc.deleteCustomReport(report.id).subscribe({
      next: () => {
        this.savedReports = this.savedReports.filter(r => r.id !== report.id);
        if (this.editingReport?.id === report.id) this.clearEdit();
      },
      error: (e) => this.toast.error(this.errorMessage(e, 'reports.delete_failed')),
    });
  }

  /**
   * The server's own words where it has some — "Choose at least one
   * column.", "You already keep a custom report called …" — and the
   * translated fallback where it does not.
   */
  private errorMessage(e: unknown, fallbackKey: string): string {
    if (e instanceof ApiError) {
      const first = Object.values(e.violations)[0]?.[0];
      if (first) return first;
      if (e.isConflict || e.isNotFound || e.isForbidden) return e.message;
    }
    return this.i18n.t(fallbackKey);
  }
}

function emptyConfig(): CustomBuilderConfig {
  return { source: '', columns: [], group_by: '', sort_by: '', sort_dir: 'asc', per_page: 50, filters: {} };
}

function toOptions(items: unknown): LookupOption[] {
  return (Array.isArray(items) ? items : [])
    .map((i: any) => ({ id: String(i?.id ?? ''), name: String(i?.name ?? '') }))
    .filter((o) => o.id !== '');
}

/**
 * The API's column types as the table draws them — a percentage as a bar,
 * seconds as "1h 30m", a status as a badge — with the API's labels.
 */
function toTableColumns(columns: Array<{ key: string; label: string; type?: string }> | undefined): TableColumn[] | undefined {
  if (!Array.isArray(columns) || columns.length === 0) return undefined;

  const drawnAs: Record<string, TableColumn['type']> = {
    number: 'number',
    percent: 'progress',
    date: 'date',
    status: 'badge',
    duration: 'duration',
  };

  return columns.map((c) => ({ key: c.key, label: c.label, type: drawnAs[c.type ?? ''] ?? 'text' }));
}
