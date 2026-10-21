// reports/components/report-export/report-export.component.ts
//
// Two modes:
//   1. Server export (default): calls API export endpoint for full reports
//   2. Client export: generates CSV/XLSX/PDF from local data[] (used by drilldown)
//
// Client-side libraries (loaded from CDN on demand):
//   - XLSX: SheetJS (xlsx.full.min.js)
//   - PDF:  jsPDF + jsPDF-AutoTable

import { Component, Input } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ReportService } from '../../services/report.service';
import { ReportFilters } from '../../models/report.models';
import { TranslatePipe } from '../../../translate.pipe';
import { I18nService } from '../../../i18n.service';
import { HotToastService } from '@ngneat/hot-toast';

declare var XLSX: any;

@Component({
  selector: 'app-report-export',
  standalone: true,
  imports: [CommonModule, TranslatePipe],
  template: `
    <div class="dropdown d-inline-block">
      <button class="btn btn-sm dropdown-toggle" [class]="btnClass" data-bs-toggle="dropdown" [disabled]="exporting">
        <i class="bi bi-download me-1" *ngIf="!exporting"></i>
        <span class="spinner-border spinner-border-sm me-1" *ngIf="exporting"></span>
        {{ label || ('reports.export' | translate) }}
      </button>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" role="button" (click)="doExport('csv')"><i class="bi bi-filetype-csv me-2 text-success"></i>CSV</a></li>
        <li><a class="dropdown-item" role="button" (click)="doExport('xlsx')"><i class="bi bi-file-earmark-excel me-2 text-success"></i>{{ 'reports.export_excel_xlsx' | translate }}</a></li>
        <li><a class="dropdown-item" role="button" (click)="doExport('pdf')"><i class="bi bi-file-earmark-pdf me-2 text-danger"></i>PDF</a></li>
      </ul>
    </div>
  `
})
export class ReportExportComponent {
  /** Server-side export props */
  @Input() reportKey = '';
  @Input() filters: ReportFilters = {};

  /** Client-side export props (for drilldown / local data) */
  @Input() data: any[] | null = null;
  @Input() title = '';

  /** Styling */
  @Input() label = '';
  @Input() btnClass = 'btn-success';

  exporting = false;

  constructor(private reportService: ReportService, private i18n: I18nService, private toast: HotToastService) {}

  doExport(format: 'csv' | 'xlsx' | 'pdf'): void {
    if (this.data && this.data.length) {
      this.clientExport(format);
    } else if (this.reportKey) {
      this.serverExport(format);
    }
  }

  /* ═══════ SERVER-SIDE EXPORT ═══════ */

  private serverExport(format: string): void {
    this.exporting = true;
    this.reportService.exportReport({
      format, report_key: this.reportKey, filters: this.filters
    }).subscribe({
      next: (blob) => {
        // Detect if server fell back to CSV (check blob type)
        let ext = format;
        if (blob.type === 'text/csv' && format !== 'csv') ext = 'csv';
        // Also handle JSON error responses wrapped in blob
        if (blob.type === 'application/json') {
          blob.text().then(t => {
            try {
              const err = JSON.parse(t);
              this.toast.error(err.message || this.i18n.t('reports.export_failed'));
            } catch { this.toast.error(this.i18n.t('reports.export_failed')); }
          });
          this.exporting = false;
          return;
        }
        this.downloadBlob(blob, `${this.reportKey}_${this.datestamp()}.${ext}`);
        this.exporting = false;
      },
      error: () => { this.exporting = false; this.toast.error(this.i18n.t('reports.export_failed_csv')); }
    });
  }

  /* ═══════ CLIENT-SIDE EXPORT ═══════ */

  private clientExport(format: string): void {
    if (!this.data?.length) return;
    this.exporting = true;
    const filename = (this.title || 'drilldown').replace(/[^a-zA-Z0-9]/g, '_').toLowerCase();

    switch (format) {
      case 'csv':  this.exportClientCsv(filename); break;
      case 'xlsx': this.exportClientXlsx(filename); break;
      case 'pdf':  this.exportClientPdf(filename); break;
    }
  }

  /* ── CSV ── */

  private exportClientCsv(filename: string): void {
    const rows = this.data!;
    const headers = this.getHeaders(rows);
    const lines: string[] = [headers.map(h => this.csvEscape(this.formatLabel(h))).join(',')];

    for (const row of rows) {
      lines.push(headers.map(h => this.csvEscape(this.cellValue(row[h]))).join(','));
    }

    const blob = new Blob(['\uFEFF' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
    this.downloadBlob(blob, `${filename}_${this.datestamp()}.csv`);
    this.exporting = false;
  }

  /* ── XLSX ── */

  private async exportClientXlsx(filename: string): Promise<void> {
    try {
      await this.loadScript('https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js', 'XLSX');

      const rows = this.data!;
      const headers = this.getHeaders(rows);
      const headerLabels = headers.map(h => this.formatLabel(h));

      // Build AOA (array of arrays) for SheetJS
      const wsData: any[][] = [headerLabels];
      for (const row of rows) {
        wsData.push(headers.map(h => {
          const v = row[h];
          // Preserve numbers as numbers for Excel
          if (typeof v === 'number') return v;
          return this.cellValue(v);
        }));
      }

      const wb = XLSX.utils.book_new();
      const ws = XLSX.utils.aoa_to_sheet(wsData);

      // Auto-width columns
      ws['!cols'] = headers.map((h, i) => {
        const maxLen = Math.max(
          headerLabels[i].length,
          ...rows.slice(0, 100).map(r => String(this.cellValue(r[h])).length)
        );
        return { wch: Math.min(Math.max(maxLen + 2, 10), 40) };
      });

      const sheetName = (this.title || 'Data').substring(0, 31).replace(/[\\\/\?\*\[\]]/g, '');
      XLSX.utils.book_append_sheet(wb, ws, sheetName);

      // Write as array buffer - produces a valid .xlsx
      const wbOut: ArrayBuffer = XLSX.write(wb, { bookType: 'xlsx', type: 'array' });
      const blob = new Blob([wbOut], {
        type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
      });
      this.downloadBlob(blob, `${filename}_${this.datestamp()}.xlsx`);
    } catch (e) {
      console.error('XLSX export failed, falling back to CSV:', e);
      this.toast.error(this.i18n.t('reports.excel_export_failed'));
      this.exportClientCsv(filename);
    }
    this.exporting = false;
  }

  /* ── PDF ── */

  private async exportClientPdf(filename: string): Promise<void> {
    try {
      await this.loadScript(
        'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js', 'jspdf'
      );
      await this.loadScript(
        'https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js', 'jspdfAutotable'
      );

      const rows = this.data!;
      const headers = this.getHeaders(rows);
      const headerLabels = headers.map(h => this.formatLabel(h));

      // jsPDF constructor: UMD puts it at window.jspdf.jsPDF
      const JsPDFClass = (window as any).jspdf?.jsPDF;
      if (!JsPDFClass) throw new Error('jsPDF not loaded');

      const doc = new JsPDFClass({ orientation: 'landscape', unit: 'mm', format: 'a4' });

      // Title
      doc.setFontSize(14);
      doc.setFont('helvetica', 'bold');
      doc.text(this.title || 'Report Export', 14, 15);

      doc.setFontSize(8);
      doc.setFont('helvetica', 'normal');
      doc.text(`Generated: ${new Date().toLocaleString()}  |  ${rows.length} records`, 14, 21);

      // Table body
      const bodyData = rows.map((row: any) =>
        headers.map(h => String(this.cellValue(row[h])))
      );

      (doc as any).autoTable({
        head: [headerLabels],
        body: bodyData,
        startY: 25,
        styles: {
          fontSize: 7,
          cellPadding: 1.5,
          overflow: 'linebreak',
          lineWidth: 0.1,
          lineColor: [200, 200, 200]
        },
        headStyles: {
          fillColor: [41, 128, 185],
          textColor: 255,
          fontStyle: 'bold',
          fontSize: 7
        },
        alternateRowStyles: { fillColor: [245, 247, 250] },
        margin: { left: 10, right: 10 },
        didDrawPage: () => {
          // Page number footer
          const pageCount = (doc as any).internal.getNumberOfPages();
          doc.setFontSize(7);
          doc.setFont('helvetica', 'italic');
          doc.text(
            `Page ${doc.getCurrentPageInfo().pageNumber} of ${pageCount}`,
            doc.internal.pageSize.getWidth() - 30,
            doc.internal.pageSize.getHeight() - 8
          );
        }
      });

      doc.save(`${filename}_${this.datestamp()}.pdf`);
    } catch (e) {
      console.error('PDF export failed, falling back to CSV:', e);
      this.toast.error(this.i18n.t('reports.pdf_export_failed'));
      this.exportClientCsv(filename);
    }
    this.exporting = false;
  }

  /* ═══════ HELPERS ═══════ */

  /** Extract column keys from data, skipping internal/object columns */
  private getHeaders(rows: any[]): string[] {
    if (!rows.length) return [];
    return Object.keys(rows[0]).filter(k => {
      if (k === 'tenant_id' || k.startsWith('_')) return false;
      const v = rows[0][k];
      // Skip object columns like status_badge: {class, label}
      if (v !== null && typeof v === 'object' && !Array.isArray(v)) return false;
      return true;
    });
  }

  /** Convert any cell value to a display string */
  private cellValue(val: any): string {
    if (val == null) return '';
    if (typeof val === 'object' && !Array.isArray(val)) return val.label || val.name || '';
    if (Array.isArray(val)) return val.join(', ');
    return String(val);
  }

  /** Escape a value for CSV */
  private csvEscape(val: string): string {
    if (val.includes(',') || val.includes('"') || val.includes('\n') || val.includes('\r')) {
      return '"' + val.replace(/"/g, '""') + '"';
    }
    return val;
  }

  /** Convert snake_case key to Title Case label */
  private formatLabel(key: string): string {
    return key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
      .replace(/\bId\b/, 'ID').replace(/\bNps\b/, 'NPS');
  }

  private datestamp(): string {
    return new Date().toISOString().split('T')[0];
  }

  /** Trigger browser download of a Blob */
  private downloadBlob(blob: Blob, filename: string): void {
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    setTimeout(() => window.URL.revokeObjectURL(url), 1000);
  }

  /** Load an external script from CDN if not already present */
  private loadScript(src: string, globalCheck: string): Promise<void> {
    return new Promise((resolve, reject) => {
      // Already loaded
      if ((window as any)[globalCheck]) { resolve(); return; }

      // Script tag exists but still loading
      const existing = document.querySelector(`script[src="${src}"]`) as HTMLScriptElement;
      if (existing) {
        if (existing.dataset['loaded'] === 'true') { resolve(); return; }
        existing.addEventListener('load', () => resolve());
        existing.addEventListener('error', () => reject(new Error(`Failed to load ${src}`)));
        return;
      }

      // Create new script tag
      const script = document.createElement('script');
      script.src = src;
      script.onload = () => { script.dataset['loaded'] = 'true'; resolve(); };
      script.onerror = () => reject(new Error(`Failed to load ${src}`));
      document.head.appendChild(script);
    });
  }
}
