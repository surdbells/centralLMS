import { Component, OnInit, inject } from '@angular/core';
import { CommonModule, Location } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink, ActivatedRoute } from '@angular/router';
import { ReportService } from '../../services/report.service';
import { ReportCatalogItem, ReportCategory } from '../../models/report.models';
import {AdmintopComponent} from '../../../partials/top/admintop/admintop.component';
import {TranslatePipe} from '../../../translate.pipe';

@Component({
  selector: 'app-report-catalog',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink, AdmintopComponent, TranslatePipe],
  template: `
    <app-admintop></app-admintop>
    <main class="main">
      <div class="ui-page report-catalog">
        <header class="ui-page-header">
          <button type="button" class="ui-page-header__back" (click)="goBack()" [attr.aria-label]="'common.back' | translate"><i class="bi bi-chevron-left"></i></button>
          <div class="ui-page-header__icon" aria-hidden="true"><i class="bi bi-grid-3x3-gap"></i></div>
          <div class="ui-page-header__titles">
            <h1>{{ 'reports.catalog_title' | translate }}</h1>
            <p>{{ 'reports.catalog_subtitle' | translate:{ reports: filteredReports.length, categories: categories.length } }}</p>
          </div>
        </header>

        <section class="ui-section">
          <div class="ui-toolbar">
            <!-- Category chips -->
            <div class="ui-filters">
              <button type="button" class="ui-filter" [class.is-active]="activeCategory===''" (click)="filterCategory('')">
                {{ 'common.all' | translate }} <span class="ui-filter__count">{{ allReports.length }}</span>
              </button>
              <button type="button" *ngFor="let cat of categories" class="ui-filter"
                      [class.is-active]="activeCategory===cat.key" (click)="filterCategory(cat.key)">
                <i [class]="cat.icon"></i>{{ cat.name }}
                <span class="ui-filter__count">{{ cat.count }}</span>
              </button>
            </div>
          </div>
          <div class="ui-toolbar">
            <label class="ui-search">
              <i class="bi bi-search"></i>
              <input type="text" placeholder="{{ 'reports.search_reports' | translate }}" [(ngModel)]="searchTerm" (input)="applyFilters()">
            </label>
            <div class="rc-view" role="group">
              <button type="button" class="ui-filter" [class.is-active]="viewMode==='grid'" (click)="viewMode='grid'"><i class="bi bi-grid-3x3-gap"></i></button>
              <button type="button" class="ui-filter" [class.is-active]="viewMode==='list'" (click)="viewMode='list'"><i class="bi bi-list-ul"></i></button>
            </div>
          </div>

          <!-- Grid View -->
          <div class="ui-grid rc-grid" *ngIf="viewMode==='grid' && filteredReports.length">
            <div *ngFor="let r of filteredReports" class="ui-card rc-card" [routerLink]="['/admin/reports', r.key]">
              <div class="ui-card__body">
                <div class="d-flex justify-content-between align-items-center">
                  <span class="ui-pill ui-pill--brand">{{ getCategoryName(r.category) }}</span>
                  <i class="bi bi-box-arrow-up-right ui-subtle small"></i>
                </div>
                <h3 class="ui-card__title">{{ r.name }}</h3>
                <p class="ui-card__meta rc-clamp mb-0">{{ r.description }}</p>
                <div class="d-flex gap-1 flex-wrap">
                  <span *ngFor="let a of r.audience" class="ui-pill ui-pill--neutral rc-tag">{{ a }}</span>
                  <span *ngIf="r.exportable" class="ui-pill ui-pill--success rc-tag">
                    <i class="bi bi-download"></i>
                  </span>
                </div>
              </div>
            </div>
          </div>

          <!-- List View -->
          <ul class="ui-list rc-list" *ngIf="viewMode==='list' && filteredReports.length">
            <li *ngFor="let r of filteredReports">
              <a class="rc-row" [routerLink]="['/admin/reports', r.key]">
                <span class="ui-pill ui-pill--brand rc-row__cat">{{ getCategoryName(r.category) }}</span>
                <span class="rc-row__main">
                  <strong>{{ r.name }}</strong>
                  <span class="ui-muted small d-block">{{ r.description }}</span>
                </span>
                <span class="d-flex gap-1 flex-wrap">
                  <span *ngFor="let a of r.audience" class="ui-pill ui-pill--neutral rc-tag">{{ a }}</span>
                </span>
              </a>
            </li>
          </ul>

          <!-- Empty -->
          <div *ngIf="!filteredReports.length" class="ui-state">
            <span class="ui-state__icon"><i class="bi bi-search"></i></span>
            <p>{{ 'reports.no_reports_match' | translate }}</p>
          </div>
        </section>
      </div>
    </main>
  `,
  styles: [`
    .rc-view { display: inline-flex; gap: 6px; margin-left: auto; }
    .rc-grid { grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 12px; }
    .rc-card { cursor: pointer; }
    .rc-card .ui-card__body { gap: 8px; padding: 14px 16px; }
    .rc-clamp { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    .rc-tag { font-size: .6875rem; padding-top: 1px; padding-bottom: 1px; }
    .rc-list > li { padding: 0; }
    .rc-row { display: flex; align-items: center; gap: 14px; width: 100%; padding: 12px 4px; color: inherit; text-decoration: none; }
    .rc-row:hover strong { color: var(--brand-primary); }
    .rc-row__cat { min-width: 110px; justify-content: center; flex-shrink: 0; }
    .rc-row__main { flex: 1; min-width: 0; }
    @media (max-width: 575.98px) { .rc-row { flex-wrap: wrap; } }
  `]
})
export class ReportCatalogComponent implements OnInit {
  private readonly backLocation = inject(Location);
  private readonly navRouter = inject(Router);
  allReports: ReportCatalogItem[] = [];
  filteredReports: ReportCatalogItem[] = [];
  categories: ReportCategory[] = [];
  searchTerm = '';
  activeCategory = '';
  viewMode: 'grid' | 'list' = 'grid';

  constructor(private reportService: ReportService, private route: ActivatedRoute) {}

  /** Header back button: the previous page, or /admin/reports when there is no history (fresh tab / deep link). */
  goBack(): void {
    if (window.history.length > 1) {
      this.backLocation.back();
      return;
    }
    void this.navRouter.navigate(['/admin/reports']);
  }

  ngOnInit(): void {
    this.route.queryParams.subscribe(p => {
      if (p['category']) this.activeCategory = p['category'];
      this.load();
    });
  }

  private load(): void {
    this.reportService.getCategories().subscribe(cats => this.categories = cats);
    this.reportService.getCatalog().subscribe(items => {
      this.allReports = items;
      this.applyFilters();
    });
  }

  filterCategory(key: string): void {
    this.activeCategory = key;
    this.applyFilters();
  }

  applyFilters(): void {
    let result = this.allReports;
    if (this.activeCategory) result = result.filter(r => r.category === this.activeCategory);
    if (this.searchTerm.trim()) {
      const t = this.searchTerm.toLowerCase();
      result = result.filter(r => r.name.toLowerCase().includes(t) || r.description.toLowerCase().includes(t) || r.key.includes(t));
    }
    this.filteredReports = result;
  }

  getCategoryName(key: string): string {
    return this.categories.find(c => c.key === key)?.name || key;
  }
}
