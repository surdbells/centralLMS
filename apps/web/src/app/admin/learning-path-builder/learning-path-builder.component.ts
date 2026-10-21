// learning-path-builder/learning-path-builder.component.ts

import { Component, OnInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterModule } from '@angular/router';
import { CdkDragDrop, DragDropModule, moveItemInArray, transferArrayItem } from '@angular/cdk/drag-drop';
import { Subject } from 'rxjs';
import { takeUntil, debounceTime, distinctUntilChanged, forkJoin } from 'rxjs';
import { LearningPathService } from '../../services/learning-path.service';
import {
  LearningPath,
  LearningPathSection,
  LearningPathItem,
  ItemCreateRequest,
  SectionCreateRequest,
  ITEM_TYPES,
  ItemType
} from '../../interfaces/learning-path.interfaces';
import {AdmintopComponent} from '../../partials/top/admintop/admintop.component';
import { TranslatePipe } from '../../translate.pipe';
import { I18nService } from '../../i18n.service';
import { ConfirmDialogService } from '../../shared/components/confirmation-dialog/confirmation-dialog.component';
import { HotToastService } from '@ngneat/hot-toast';

// Course and Assessment interfaces
interface Course {
  course_id: string;
  title: string;
  short_description?: string;
  thumbnail_url?: string;
  estimated_duration_minutes?: number;
  lessons_count?: number;
  category_name?: string;
  skill_level?: string;
  status?: string;
}

interface Assessment {
  assessment_id: string;
  title: string;
  description?: string;
  time_limit_minutes?: number;
  passing_score?: number;
  questions_count?: number;
  category_name?: string;
  assessment_type?: string;
  status?: string;
}

@Component({
  selector: 'app-learning-path-builder',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterModule, DragDropModule, AdmintopComponent, TranslatePipe],
  templateUrl: './learning-path-builder.component.html',
  styleUrls: ['./learning-path-builder.component.scss']
})
export class LearningPathBuilderComponent implements OnInit, OnDestroy {
  private destroy$ = new Subject<void>();
  private courseSearchSubject$ = new Subject<string>();
  private assessmentSearchSubject$ = new Subject<string>();

  // Data
  path: LearningPath | null = null;
  sections: LearningPathSection[] = [];
  unsectionedItems: LearningPathItem[] = [];

  // UI State
  loading = true;
  saving = false;
  hasChanges = false;

  // Modal State
  showSectionModal = false;
  showItemModal = false;
  showCourseSelector = false;
  showAssessmentSelector = false;

  // Edit State
  editingSection: LearningPathSection | null = null;
  editingItem: LearningPathItem | null = null;
  targetSectionId: string | null = null;

  // Section Form
  sectionForm: Partial<SectionCreateRequest> = {
    title: '',
    description: '',
    is_milestone: false,
    unlock_type: 'previous_section'
  };

  // Item Form
  itemForm: Partial<ItemCreateRequest> = {
    item_type: 'external_link',
    external_title: '',
    external_url: '',
    external_description: '',
    points_value: 10,
    is_required: true
  };

  // Available courses/assessments for selection
  availableCourses: Course[] = [];
  availableAssessments: Assessment[] = [];
  filteredCourses: Course[] = [];
  filteredAssessments: Assessment[] = [];
  courseSearch = '';
  assessmentSearch = '';
  loadingCourses = false;
  loadingAssessments = false;

  // Reference data
  itemTypes = ITEM_TYPES;

  // Computed values
  get totalItems(): number {
    let count = this.unsectionedItems.length;
    this.sections.forEach(s => count += (s.items?.length || 0));
    return count;
  }

  get totalSections(): number {
    return this.sections.length;
  }

  get totalPoints(): number {
    let points = 0;
    this.unsectionedItems.forEach(i => points += (i.points_value || 0));
    this.sections.forEach(s => s.items?.forEach(i => points += (i.points_value || 0)));
    return points;
  }

  get totalDuration(): number {
    let minutes = 0;
    this.unsectionedItems.forEach(i => minutes += (i.estimated_duration_minutes || 0));
    this.sections.forEach(s => s.items?.forEach(i => minutes += (i.estimated_duration_minutes || 0)));
    return minutes;
  }

  get formattedDuration(): string {
    const hours = Math.floor(this.totalDuration / 60);
    const mins = this.totalDuration % 60;
    if (hours === 0) return `${mins}m`;
    if (mins === 0) return `${hours}h`;
    return `${hours}.${Math.round(mins / 60 * 100).toString().padStart(2, '0')}h`;
  }

  constructor(
    private route: ActivatedRoute,
    private router: Router,
    private learningPathService: LearningPathService,
    private i18n: I18nService,
    private dialog: ConfirmDialogService,
    private toast: HotToastService
  ) {}

  ngOnInit(): void {
    this.route.params.pipe(takeUntil(this.destroy$)).subscribe(params => {
      const pathId = params['id'];
      if (pathId) {
        this.loadPath(pathId);
      }
    });

    this.setupCourseSearch();
    this.setupAssessmentSearch();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  // ==========================================
  // DATA LOADING
  // ==========================================

  loadPath(pathId: string): void {
    this.loading = true;

    forkJoin({
      path: this.learningPathService.getById(pathId),
      sectionsWithItems: this.learningPathService.getSectionsWithItems(pathId),
      items: this.learningPathService.getItems(pathId)
    }).pipe(takeUntil(this.destroy$)).subscribe({
      next: ({ path, sectionsWithItems, items }) => {
        this.path = path;

        // Handle different response formats
        let sectionsArray: LearningPathSection[] = [];
        if (Array.isArray(sectionsWithItems)) {
          sectionsArray = sectionsWithItems;
        } else if (sectionsWithItems && typeof sectionsWithItems === 'object') {
          sectionsArray = (sectionsWithItems as any).sections || (sectionsWithItems as any).data || [];
        }

        this.sections = sectionsArray;

        // Handle items response format
        let itemsArray: LearningPathItem[] = [];
        if (Array.isArray(items)) {
          itemsArray = items;
        } else if (items && typeof items === 'object') {
          itemsArray = (items as any).items || (items as any).data || [];
        }

        // Get unsectioned items
        const sectionedItemIds = new Set<string>();
        sectionsArray.forEach(s => {
          if (s.items && Array.isArray(s.items)) {
            s.items.forEach(i => sectionedItemIds.add(i.item_id));
          }
        });
        this.unsectionedItems = itemsArray.filter(i => !sectionedItemIds.has(i.item_id));

        this.loading = false;
      },
      error: (error) => {
        console.error('Error loading path:', error);
        this.loading = false;
      }
    });
  }

  // ==========================================
  // SEARCH SETUP
  // ==========================================

  private setupCourseSearch(): void {
    this.courseSearchSubject$.pipe(
      debounceTime(300),
      distinctUntilChanged(),
      takeUntil(this.destroy$)
    ).subscribe(search => {
      this.filterCourses(search);
    });
  }

  private setupAssessmentSearch(): void {
    this.assessmentSearchSubject$.pipe(
      debounceTime(300),
      distinctUntilChanged(),
      takeUntil(this.destroy$)
    ).subscribe(search => {
      this.filterAssessments(search);
    });
  }

  onCourseSearchChange(): void {
    this.courseSearchSubject$.next(this.courseSearch);
  }

  onAssessmentSearchChange(): void {
    this.assessmentSearchSubject$.next(this.assessmentSearch);
  }

  private filterCourses(search: string): void {
    if (!search.trim()) {
      this.filteredCourses = [...this.availableCourses];
      return;
    }
    const searchLower = search.toLowerCase();
    this.filteredCourses = this.availableCourses.filter(c =>
      c.title.toLowerCase().includes(searchLower) ||
      (c.short_description && c.short_description.toLowerCase().includes(searchLower)) ||
      (c.category_name && c.category_name.toLowerCase().includes(searchLower))
    );
  }

  private filterAssessments(search: string): void {
    if (!search.trim()) {
      this.filteredAssessments = [...this.availableAssessments];
      return;
    }
    const searchLower = search.toLowerCase();
    this.filteredAssessments = this.availableAssessments.filter(a =>
      a.title.toLowerCase().includes(searchLower) ||
      (a.description && a.description.toLowerCase().includes(searchLower)) ||
      (a.category_name && a.category_name.toLowerCase().includes(searchLower))
    );
  }

  // ==========================================
  // SECTION MANAGEMENT
  // ==========================================

  openSectionModal(section?: LearningPathSection): void {
    if (section) {
      this.editingSection = section;
      this.sectionForm = {
        title: section.title,
        description: section.description,
        is_milestone: section.is_milestone,
        unlock_type: section.unlock_type
      };
    } else {
      this.editingSection = null;
      this.sectionForm = {
        title: '',
        description: '',
        is_milestone: false,
        unlock_type: 'previous_section'
      };
    }
    this.showSectionModal = true;
  }

  closeSectionModal(): void {
    this.showSectionModal = false;
    this.editingSection = null;
  }

  saveSection(): void {
    if (!this.path || !this.sectionForm.title) return;

    this.saving = true;

    if (this.editingSection) {
      // Update
      this.learningPathService.updateSection(this.editingSection.section_id, this.sectionForm)
        .pipe(takeUntil(this.destroy$))
        .subscribe({
          next: () => {
            this.loadPath(this.path!.path_id);
            this.closeSectionModal();
            this.saving = false;
          },
          error: () => this.saving = false
        });
    } else {
      // Create
      const data: SectionCreateRequest = {
        ...this.sectionForm as SectionCreateRequest,
        path_id: this.path.path_id
      };

      this.learningPathService.createSection(data)
        .pipe(takeUntil(this.destroy$))
        .subscribe({
          next: () => {
            this.loadPath(this.path!.path_id);
            this.closeSectionModal();
            this.saving = false;
          },
          error: () => this.saving = false
        });
    }
  }

  async deleteSection(section: LearningPathSection): Promise<void> {
    if (!(await this.dialog.confirm(this.i18n.t('paths_admin.builder.confirm_delete_section', { title: section.title }), { type: 'danger' }))) return;
    if (!this.path) return;

    this.learningPathService.deleteSection(section.section_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => this.loadPath(this.path!.path_id)
      });
  }

  // ==========================================
  // ITEM MANAGEMENT
  // ==========================================

  openItemModal(sectionId: string | null, item?: LearningPathItem): void {
    this.targetSectionId = sectionId;

    if (item) {
      this.editingItem = item;
      this.itemForm = {
        item_type: item.item_type,
        external_title: item.external_title,
        external_url: item.external_url,
        external_description: item.external_description,
        points_value: item.points_value,
        is_required: item.is_required,
        estimated_duration_minutes: item.estimated_duration_minutes || undefined
      };
    } else {
      this.editingItem = null;
      this.itemForm = {
        item_type: 'external_link',
        external_title: '',
        external_url: '',
        external_description: '',
        points_value: 10,
        is_required: true
      };
    }
    this.showItemModal = true;
  }

  closeItemModal(): void {
    this.showItemModal = false;
    this.editingItem = null;
  }

  saveItem(): void {
    if (!this.path) return;

    this.saving = true;

    if (this.editingItem) {
      // Update
      this.learningPathService.updateItem(this.editingItem.item_id, this.itemForm)
        .pipe(takeUntil(this.destroy$))
        .subscribe({
          next: () => {
            this.loadPath(this.path!.path_id);
            this.closeItemModal();
            this.saving = false;
          },
          error: () => this.saving = false
        });
    } else {
      // Create
      const data: ItemCreateRequest = {
        ...this.itemForm as ItemCreateRequest,
        path_id: this.path.path_id,
        section_id: this.targetSectionId || undefined
      };

      this.learningPathService.createItem(data)
        .pipe(takeUntil(this.destroy$))
        .subscribe({
          next: () => {
            this.loadPath(this.path!.path_id);
            this.closeItemModal();
            this.saving = false;
          },
          error: () => this.saving = false
        });
    }
  }

  async deleteItem(item: LearningPathItem): Promise<void> {
    if (!(await this.dialog.confirm(this.i18n.t('paths_admin.builder.confirm_delete_item', { title: item.display_title || item.external_title }), { type: 'danger' }))) return;
    if (!this.path) return;

    this.learningPathService.deleteItem(this.path.path_id, item.item_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => this.loadPath(this.path!.path_id)
      });
  }

  // ==========================================
  // ADD COURSE
  // ==========================================

  openCourseSelector(sectionId: string | null): void {
    this.targetSectionId = sectionId;
    this.showCourseSelector = true;
    this.courseSearch = '';
    this.loadAvailableCourses();
  }

  closeCourseSelector(): void {
    this.showCourseSelector = false;
    this.courseSearch = '';
  }

  loadAvailableCourses(): void {
    this.loadingCourses = true;

    // Use the LearningPathService method
    this.learningPathService.getAvailableCourses()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (courses) => {
          this.availableCourses = Array.isArray(courses) ? courses : [];
          this.filteredCourses = [...this.availableCourses];
          this.loadingCourses = false;
        },
        error: () => {
          this.availableCourses = [];
          this.filteredCourses = [];
          this.loadingCourses = false;
        }
      });
  }

  addCourse(course: Course): void {
    if (!this.path) return;

    this.saving = true;

    this.learningPathService.addCourse(this.path.path_id, course.course_id, this.targetSectionId || undefined)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.loadPath(this.path!.path_id);
          this.closeCourseSelector();
          this.saving = false;
        },
        error: () => this.saving = false
      });
  }

  // ==========================================
  // ADD ASSESSMENT
  // ==========================================

  openAssessmentSelector(sectionId: string | null): void {
    this.targetSectionId = sectionId;
    this.showAssessmentSelector = true;
    this.assessmentSearch = '';
    this.loadAvailableAssessments();
  }

  closeAssessmentSelector(): void {
    this.showAssessmentSelector = false;
    this.assessmentSearch = '';
  }

  loadAvailableAssessments(): void {
    this.loadingAssessments = true;

    // Use the LearningPathService method
    this.learningPathService.getAvailableAssessments()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (assessments) => {
          this.availableAssessments = Array.isArray(assessments) ? assessments : [];
          this.filteredAssessments = [...this.availableAssessments];
          this.loadingAssessments = false;
        },
        error: () => {
          this.availableAssessments = [];
          this.filteredAssessments = [];
          this.loadingAssessments = false;
        }
      });
  }

  addAssessment(assessment: Assessment): void {
    if (!this.path) return;

    this.saving = true;

    this.learningPathService.addAssessment(this.path.path_id, assessment.assessment_id, this.targetSectionId || undefined)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.loadPath(this.path!.path_id);
          this.closeAssessmentSelector();
          this.saving = false;
        },
        error: () => this.saving = false
      });
  }

  // ==========================================
  // DRAG & DROP
  // ==========================================

  onSectionDrop(event: CdkDragDrop<LearningPathSection[]>): void {
    const sections = [...this.sections];
    moveItemInArray(sections, event.previousIndex, event.currentIndex);
    this.sections = sections;
    this.saveSectionOrder(sections);
  }

  onItemDrop(event: CdkDragDrop<LearningPathItem[]>, targetSectionId: string | null): void {
    if (!this.path) return;

    if (event.previousContainer === event.container) {
      // Reorder within same container
      const items = [...event.container.data];
      moveItemInArray(items, event.previousIndex, event.currentIndex);

      if (targetSectionId === null) {
        this.unsectionedItems = items;
      } else {
        this.sections = this.sections.map(s => {
          if (s.section_id === targetSectionId) {
            return { ...s, items };
          }
          return s;
        });
      }
    } else {
      // Move between containers
      const prevItems = [...event.previousContainer.data];
      const currItems = [...event.container.data];
      transferArrayItem(prevItems, currItems, event.previousIndex, event.currentIndex);

      // Update both containers
      // This simplified version updates the UI; real implementation tracks source
    }

    this.hasChanges = true;
    this.saveItemOrder();
  }

  saveSectionOrder(sections: LearningPathSection[]): void {
    if (!this.path) return;

    const sectionOrders = sections.map((s, index) => ({
      section_id: s.section_id,
      sort_order: index + 1
    }));

    this.learningPathService.reorderSections(sectionOrders)
      .pipe(takeUntil(this.destroy$))
      .subscribe();
  }

  saveItemOrder(): void {
    if (!this.path) return;

    const itemOrders: { item_id: string; sort_order: number; section_id?: string }[] = [];
    let globalOrder = 1;

    // Unsectioned items first
    this.unsectionedItems.forEach(item => {
      itemOrders.push({
        item_id: item.item_id,
        sort_order: globalOrder++
      });
    });

    // Then sectioned items
    this.sections.forEach(section => {
      section.items?.forEach(item => {
        itemOrders.push({
          item_id: item.item_id,
          sort_order: globalOrder++,
          section_id: section.section_id
        });
      });
    });

    this.learningPathService.reorderItems(this.path.path_id, itemOrders)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => this.hasChanges = false
      });
  }

  // ==========================================
  // PUBLISH
  // ==========================================

  async publishPath(): Promise<void> {
    if (!this.path) return;

    if (this.totalItems === 0) {
      this.toast.error(this.i18n.t('paths_admin.cannot_publish_item'));
      return;
    }

    if (await this.dialog.confirm(this.i18n.t('paths_admin.confirm_publish_path'), { type: 'warning' })) {
      this.learningPathService.publish(this.path.path_id)
        .pipe(takeUntil(this.destroy$))
        .subscribe({
          next: () => {
            this.loadPath(this.path!.path_id);
            this.toast.success(this.i18n.t('paths_admin.builder.publish_success'));
          },
          error: (error) => {
            this.toast.error(error.error?.errors?.join('\n') || this.i18n.t('paths_admin.publish_failed'));
          }
        });
    }
  }

  goToSettings(): void {
    if (this.path) {
      this.router.navigate(['/admin/learning-paths', this.path.path_id, 'edit']);
    }
  }

  goBack(): void {
    if (this.path) {
      this.router.navigate(['/admin/learning-paths', this.path.path_id]);
    } else {
      this.router.navigate(['/admin/learning-paths']);
    }
  }

  // ==========================================
  // HELPERS
  // ==========================================

  getItemTypeIcon(type: ItemType): string {
    const icons: Record<string, string> = {
      'course': 'bi-collection-play',
      'assessment': 'bi-clipboard-check',
      'external_link': 'bi-link-45deg',
      'video': 'bi-play-circle',
      'document': 'bi-file-text',
      'scorm': 'bi-box'
    };
    return icons[type] || 'bi-file';
  }

  getItemTypeLabel(type: ItemType): string {
    const labels: Record<string, string> = {
      'course': this.i18n.t('paths_admin.course'),
      'assessment': this.i18n.t('modules.assessment'),
      'external_link': this.i18n.t('paths_admin.external_link'),
      'video': this.i18n.t('paths_admin.builder.type_video'),
      'document': this.i18n.t('paths_admin.builder.type_document'),
      'scorm': this.i18n.t('paths_admin.builder.type_scorm')
    };
    return labels[type] || type;
  }

  getStatusBadgeClass(status: string): string {
    const classes: Record<string, string> = {
      'draft': 'bg-secondary',
      'published': 'bg-success',
      'archived': 'bg-warning text-dark'
    };
    return classes[status] || 'bg-secondary';
  }

  getConnectedDropLists(): string[] {
    const lists = ['unsectioned-items'];
    this.sections.forEach(s => lists.push(`section-${s.section_id}`));
    return lists;
  }

  formatDuration(minutes: number | undefined): string {
    if (!minutes) return '';
    if (minutes < 60) return `${minutes}m`;
    const hours = Math.floor(minutes / 60);
    const mins = minutes % 60;
    return mins > 0 ? `${hours}h ${mins}m` : `${hours}h`;
  }

  trackBySectionId(index: number, section: LearningPathSection): string {
    return section.section_id;
  }

  trackByItemId(index: number, item: LearningPathItem): string {
    return item.item_id;
  }

  trackByCourseId(index: number, course: Course): string {
    return course.course_id;
  }

  trackByAssessmentId(index: number, assessment: Assessment): string {
    return assessment.assessment_id;
  }
}
