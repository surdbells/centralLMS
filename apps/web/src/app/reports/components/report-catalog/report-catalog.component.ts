import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
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
    <main class="main px-lg-6">
        <div class="container-fluid">
        <div class="report-catalog">
      <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
          <h4 class="fw-bold mb-1">{{ 'reports.catalog_title' | translate }}</h4>
          <p class="text-muted mb-0 small">{{ 'reports.catalog_subtitle' | translate:{ reports: filteredReports.length, categories: categories.length } }}</p>
        </div>
        <div class="d-flex gap-2">
          <div class="input-group" style="width: 280px">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="text" class="form-control" placeholder="{{ 'reports.search_reports' | translate }}" [(ngModel)]="searchTerm" (input)="applyFilters()">
          </div>
          <div class="btn-group">
            <button class="btn btn-secondary" [class.active]="viewMode==='grid'" (click)="viewMode='grid'"><i class="bi bi-grid-3x3-gap"></i></button>
            <button class="btn btn-secondary" [class.active]="viewMode==='list'" (click)="viewMode='list'"><i class="bi bi-list-ul"></i></button>
          </div>
        </div>
      </div>

      <!-- Category Pills -->
      <div class="d-flex flex-wrap gap-2 mb-4">
        <button class="btn btn-sm" [class]="activeCategory===''?'btn-primary':'btn-primary'" (click)="filterCategory('')">
          {{ 'common.all' | translate }} <span class="badge bg-white text-primary ms-1">{{ allReports.length }}</span>
        </button>
        <button *ngFor="let cat of categories" class="btn btn-sm"
                [class]="activeCategory===cat.key?'btn-primary':'btn-primary'" (click)="filterCategory(cat.key)">
          <i [class]="cat.icon+' me-1'"></i>{{ cat.name }}
          <span class="badge bg-white text-primary ms-1">{{ cat.count }}</span>
        </button>
      </div>

      <!-- Grid View -->
      <div class="row g-3" *ngIf="viewMode==='grid'">
        <div *ngFor="let r of filteredReports" class="col-sm-6 col-md-4 col-lg-3">
          <div class="card border-0 shadow-sm h-100" [routerLink]="['/admin/reports', r.key]" style="cursor:pointer;transition:transform .15s">
            <div class="card-body">
              <div class="d-flex justify-content-between mb-2">
                <span class="badge bg-primary-subtle text-primary small">{{ getCategoryName(r.category) }}</span>
                <i class="bi bi-box-arrow-up-right text-muted small"></i>
              </div>
              <h6 class="fw-semibold mb-1">{{ r.name }}</h6>
              <p class="text-muted small mb-2" style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">{{ r.description }}</p>
              <div class="d-flex gap-1 flex-wrap">
                <span *ngFor="let a of r.audience" class="badge bg-light text-muted" style="font-size:.65rem">{{ a }}</span>
                <span *ngIf="r.exportable" class="badge bg-success-subtle text-success" style="font-size:.65rem">
                  <i class="bi bi-download"></i>
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- List View -->
      <div class="list-group" *ngIf="viewMode==='list'">
        <a *ngFor="let r of filteredReports" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
           [routerLink]="['/admin/reports', r.key]">
          <div class="d-flex align-items-center gap-3">
            <span class="badge bg-primary-subtle text-primary" style="min-width:90px">{{ getCategoryName(r.category) }}</span>
            <div>
              <strong>{{ r.name }}</strong>
              <p class="text-muted small mb-0">{{ r.description }}</p>
            </div>
          </div>
          <div class="d-flex gap-1">
            <span *ngFor="let a of r.audience" class="badge bg-light text-muted small">{{ a }}</span>
          </div>
        </a>
      </div>

      <!-- Empty -->
      <div *ngIf="!filteredReports.length" class="text-center py-5 text-muted">
        <i class="bi bi-search fs-1 d-block mb-2"></i>
        <p>{{ 'reports.no_reports_match' | translate }}</p>
      </div>
    </div>
      </div>
    </main>
  `,
  styles: [`
    .card:hover { transform: translateY(-2px); box-shadow: 0 .5rem 1rem rgba(0,0,0,.1) !important; }
  `]
})
export class ReportCatalogComponent implements OnInit {
  allReports: ReportCatalogItem[] = [];
  filteredReports: ReportCatalogItem[] = [];
  categories: ReportCategory[] = [];
  searchTerm = '';
  activeCategory = '';
  viewMode: 'grid' | 'list' = 'grid';

  constructor(private reportService: ReportService, private route: ActivatedRoute) {}

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
