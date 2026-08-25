// tenant-settings/tenant-settings.component.ts

import { Component, OnInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Subject } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import {
  TenantSettingsService,
  AllSettings,
  TenantInfo,
  UsageStats,
  SettingsHistoryItem,
  SettingsCategory,
  GeneralSettings,
  BrandingSettings,
  LearningSettings,
  GamificationSettings,
  NotificationSettings,
  SecuritySettings,
  StorageSettings
} from './tenant-settings.service';

type TabType = 'overview' | 'general' | 'branding' | 'learning' | 'gamification' | 'notifications' | 'security' | 'storage';

@Component({
  selector: 'app-tenant-settings',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './tenant-settings.component.html',
  styleUrls: ['./tenant-settings.component.scss']
})
export class TenantSettingsComponent implements OnInit, OnDestroy {
  private destroy$ = new Subject<void>();

  // Data
  settings: AllSettings | null = null;
  tenantInfo: TenantInfo | null = null;
  usageStats: UsageStats | null = null;
  settingsHistory: SettingsHistoryItem[] = [];

  // Active tab
  activeTab: TabType = 'overview';

  // Form copies for editing
  generalForm: GeneralSettings | null = null;
  brandingForm: BrandingSettings | null = null;
  learningForm: LearningSettings | null = null;
  gamificationForm: GamificationSettings | null = null;
  notificationsForm: NotificationSettings | null = null;
  securityForm: SecuritySettings | null = null;
  storageForm: StorageSettings | null = null;
  tenantInfoForm: Partial<TenantInfo> = {};

  // UI State
  loading = false;
  saving = false;
  hasChanges: Record<string, boolean> = {};

  // Messages
  successMessage = '';
  errorMessage = '';

  // Options for dropdowns
  timezones: string[] = [
    'Africa/Lagos',
    'Africa/Cairo',
    'Africa/Johannesburg',
    'Africa/Nairobi',
    'America/New_York',
    'America/Los_Angeles',
    'America/Chicago',
    'America/Denver',
    'Europe/London',
    'Europe/Paris',
    'Europe/Berlin',
    'Asia/Dubai',
    'Asia/Singapore',
    'Asia/Tokyo',
    'Asia/Shanghai',
    'Australia/Sydney',
    'Pacific/Auckland'
  ];

  dateFormats: { value: string; label: string }[] = [
    { value: 'Y-m-d', label: '2025-01-02 (ISO)' },
    { value: 'd/m/Y', label: '02/01/2025 (UK)' },
    { value: 'm/d/Y', label: '01/02/2025 (US)' },
    { value: 'd-m-Y', label: '02-01-2025' },
    { value: 'd M Y', label: '02 Jan 2025' },
    { value: 'F j, Y', label: 'January 2, 2025' }
  ];

  timeFormats: { value: string; label: string }[] = [
    { value: 'H:i', label: '14:30 (24-hour)' },
    { value: 'h:i A', label: '02:30 PM (12-hour)' }
  ];

  languages: { value: string; label: string }[] = [
    { value: 'en', label: 'English' },
    { value: 'fr', label: 'French' },
    { value: 'es', label: 'Spanish' },
    { value: 'de', label: 'German' },
    { value: 'pt', label: 'Portuguese' },
    { value: 'ar', label: 'Arabic' }
  ];

  currencies: { value: string; label: string }[] = [
    { value: 'NGN', label: 'Nigerian Naira (₦)' },
    { value: 'USD', label: 'US Dollar ($)' },
    { value: 'EUR', label: 'Euro (€)' },
    { value: 'GBP', label: 'British Pound (£)' },
    { value: 'ZAR', label: 'South African Rand (R)' },
    { value: 'KES', label: 'Kenyan Shilling (KSh)' },
    { value: 'GHS', label: 'Ghanaian Cedi (₵)' }
  ];

  // Modals
  showResetModal = false;
  showExportModal = false;
  showImportModal = false;
  showHistoryModal = false;
  resetCategory: SettingsCategory | null = null;
  importData = '';

  constructor(private settingsService: TenantSettingsService) {}

  ngOnInit(): void {
    this.loadAllData();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  // ==========================================
  // DATA LOADING
  // ==========================================

  loadAllData(): void {
    this.loading = true;

    // Load all settings
    this.settingsService.getAllSettings()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (settings) => {
          this.settings = settings;
          this.initializeForms();
          this.loading = false;
        },
        error: (err) => {
          this.showError(err.error?.message || 'Failed to load settings');
          this.loading = false;
        }
      });

    // Load tenant info
    this.settingsService.getTenantInfo()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (info) => {
          this.tenantInfo = info;
          this.tenantInfoForm = {
            tenant_name: info.tenant_name,
            domain: info.domain,
            logo_url: info.logo_url
          };
        }
      });

    // Load usage stats
    this.settingsService.getUsageStats()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (stats) => this.usageStats = stats
      });
  }

  private initializeForms(): void {
    if (!this.settings) return;

    this.generalForm = { ...this.settings.general };
    this.brandingForm = { ...this.settings.branding };
    this.learningForm = { ...this.settings.learning };
    this.gamificationForm = { ...this.settings.gamification };
    this.notificationsForm = { ...this.settings.notifications };
    this.securityForm = { ...this.settings.security };
    this.storageForm = { ...this.settings.storage };

    // Reset change tracking
    this.hasChanges = {};
  }

  // ==========================================
  // TAB NAVIGATION
  // ==========================================

  setActiveTab(tab: TabType): void {
    this.activeTab = tab;
    this.clearMessages();
  }

  // ==========================================
  // CHANGE DETECTION
  // ==========================================

  markAsChanged(category: string): void {
    this.hasChanges[category] = true;
  }

  // ==========================================
  // SAVE SETTINGS
  // ==========================================

  saveGeneralSettings(): void {
    if (!this.generalForm) return;
    this.saveCategory('general', this.generalForm);
  }

  saveBrandingSettings(): void {
    if (!this.brandingForm) return;
    this.saveCategory('branding', this.brandingForm);
  }

  saveLearningSettings(): void {
    if (!this.learningForm) return;
    this.saveCategory('learning', this.learningForm);
  }

  saveGamificationSettings(): void {
    if (!this.gamificationForm) return;
    this.saveCategory('gamification', this.gamificationForm);
  }

  saveNotificationSettings(): void {
    if (!this.notificationsForm) return;
    this.saveCategory('notifications', this.notificationsForm);
  }

  saveSecuritySettings(): void {
    if (!this.securityForm) return;
    this.saveCategory('security', this.securityForm);
  }

  saveStorageSettings(): void {
    if (!this.storageForm) return;
    this.saveCategory('storage', this.storageForm);
  }

  private saveCategory(category: SettingsCategory, settings: Record<string, any>): void {
    this.saving = true;
    this.clearMessages();

    this.settingsService.updateCategorySettings(category, settings)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (result) => {
          if (result.success) {
            this.showSuccess(result.message);
            this.hasChanges[category] = false;
            // Update the main settings object
            if (this.settings) {
              (this.settings as any)[category] = { ...settings };
            }
          } else {
            this.showError(result.message);
            if (result.errors) {
              const errorMessages = Object.entries(result.errors)
                .map(([key, msg]) => `${key}: ${msg}`)
                .join(', ');
              this.showError(`Validation errors: ${errorMessages}`);
            }
          }
          this.saving = false;
        },
        error: (err) => {
          this.showError(err.error?.message || 'Failed to save settings');
          this.saving = false;
        }
      });
  }

  saveTenantInfo(): void {
    this.saving = true;
    this.clearMessages();

    this.settingsService.updateTenantInfo(this.tenantInfoForm)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (result) => {
          if (result.success) {
            this.showSuccess('Tenant info updated');
            this.hasChanges['tenant'] = false;
            // Update tenant info
            if (this.tenantInfo) {
              Object.assign(this.tenantInfo, this.tenantInfoForm);
            }
          } else {
            this.showError(result.message);
          }
          this.saving = false;
        },
        error: (err) => {
          this.showError(err.error?.message || 'Failed to update tenant info');
          this.saving = false;
        }
      });
  }

  // ==========================================
  // RESET TO DEFAULTS
  // ==========================================

  openResetModal(category: SettingsCategory): void {
    this.resetCategory = category;
    this.showResetModal = true;
  }

  confirmReset(): void {
    if (!this.resetCategory) return;

    this.saving = true;
    this.settingsService.resetCategoryToDefaults(this.resetCategory)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (result) => {
          this.showSuccess(result.message);
          this.closeResetModal();
          this.loadAllData(); // Reload to get defaults
          this.saving = false;
        },
        error: (err) => {
          this.showError(err.error?.message || 'Failed to reset settings');
          this.saving = false;
        }
      });
  }

  closeResetModal(): void {
    this.showResetModal = false;
    this.resetCategory = null;
  }

  // ==========================================
  // EXPORT/IMPORT
  // ==========================================

  openExportModal(): void {
    this.showExportModal = true;
  }

  exportSettings(): void {
    this.settingsService.exportSettings()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (data) => {
          const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
          const url = window.URL.createObjectURL(blob);
          const a = document.createElement('a');
          a.href = url;
          a.download = `tenant-settings-${data.tenant_id}-${new Date().toISOString().split('T')[0]}.json`;
          a.click();
          window.URL.revokeObjectURL(url);
          this.showExportModal = false;
        },
        error: (err) => {
          this.showError(err.error?.message || 'Failed to export settings');
        }
      });
  }

  openImportModal(): void {
    this.importData = '';
    this.showImportModal = true;
  }

  confirmImport(): void {
    if (!this.importData.trim()) {
      this.showError('Please paste the settings JSON');
      return;
    }

    let parsedSettings: any;
    try {
      parsedSettings = JSON.parse(this.importData);
    } catch {
      this.showError('Invalid JSON format');
      return;
    }

    const settings = parsedSettings.settings || parsedSettings;

    this.saving = true;
    this.settingsService.importSettings(settings)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (result) => {
          this.showSuccess('Settings imported successfully');
          this.showImportModal = false;
          this.loadAllData();
          this.saving = false;
        },
        error: (err) => {
          this.showError(err.error?.message || 'Failed to import settings');
          this.saving = false;
        }
      });
  }

  closeImportModal(): void {
    this.showImportModal = false;
    this.importData = '';
  }

  // ==========================================
  // HISTORY
  // ==========================================

  openHistoryModal(): void {
    this.settingsService.getSettingsHistory(50)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (history) => {
          this.settingsHistory = history;
          this.showHistoryModal = true;
        },
        error: (err) => {
          this.showError(err.error?.message || 'Failed to load history');
        }
      });
  }

  closeHistoryModal(): void {
    this.showHistoryModal = false;
  }

  formatHistoryChanges(changes: Record<string, any>): string {
    if (!changes) return '';
    return Object.entries(changes)
      .map(([key, value]) => `${key}: ${JSON.stringify(value)}`)
      .join(', ');
  }

  // ==========================================
  // HELPER METHODS
  // ==========================================

  getStoragePercentage(): number {
    if (!this.usageStats || !this.usageStats.storage_limit_bytes) return 0;
    return Math.min(100, (this.usageStats.storage_used_bytes / this.usageStats.storage_limit_bytes) * 100);
  }

  formatBytes(bytes: number): string {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
  }

  formatStorageLimit(): string {
    if (!this.usageStats) return '-';
    return this.formatBytes(this.usageStats.storage_limit_bytes);
  }

  formatDate(dateString: string): string {
    if (!dateString) return '-';
    return new Date(dateString).toLocaleDateString();
  }

  formatDateTime(dateString: string): string {
    if (!dateString) return '-';
    return new Date(dateString).toLocaleString();
  }

  getCategoryLabel(category: string): string {
    const labels: Record<string, string> = {
      general: 'General',
      branding: 'Branding',
      learning: 'Learning',
      gamification: 'Gamification',
      notifications: 'Notifications',
      security: 'Security',
      storage: 'Storage',
      integrations: 'Integrations',
      tenant_info: 'Tenant Info'
    };
    return labels[category] || category;
  }

  // ==========================================
  // MESSAGES
  // ==========================================

  private showSuccess(message: string): void {
    this.successMessage = message;
    this.errorMessage = '';
    setTimeout(() => this.successMessage = '', 5000);
  }

  private showError(message: string): void {
    this.errorMessage = message;
    this.successMessage = '';
  }

  private clearMessages(): void {
    this.successMessage = '';
    this.errorMessage = '';
  }

  // ==========================================
  // ARRAY HELPERS (for reminder days, etc.)
  // ==========================================

  getReminderDaysString(): string {
    return this.learningForm?.mandatory_course_reminder_days?.join(', ') || '';
  }

  setReminderDays(value: string): void {
    if (this.learningForm) {
      this.learningForm.mandatory_course_reminder_days = value
        .split(',')
        .map(s => parseInt(s.trim()))
        .filter(n => !isNaN(n) && n > 0);
      this.markAsChanged('learning');
    }
  }

  getAllowedFileTypesString(): string {
    return this.storageForm?.allowed_file_types?.join(', ') || '';
  }

  setAllowedFileTypes(value: string): void {
    if (this.storageForm) {
      this.storageForm.allowed_file_types = value
        .split(',')
        .map(s => s.trim().toLowerCase())
        .filter(s => s.length > 0);
      this.markAsChanged('storage');
    }
  }
}
