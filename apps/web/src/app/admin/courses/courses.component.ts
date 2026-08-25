import { decodeSessionBlob } from '../../core/utils/session-blob';
import {
  Component,
  inject,
  OnInit,
  OnDestroy,
  signal,
  ChangeDetectionStrategy,
  Output,
  EventEmitter
} from '@angular/core';
import {TopComponent} from '../../partials/top/top.component';
import {SideComponent} from '../../partials/side/side.component';
import {
  TuiButton, TuiDialog, tuiDialog,
  TuiDialogContext,
  TuiDialogService,
  TuiDialogSize,
  TuiIcon,
  TuiLoader,
  TuiPopup,
  TuiTitle
} from '@taiga-ui/core';
import {Router, RouterLink} from '@angular/router';
import {CrudService} from '../../services/crud.service';
import {HotToastService} from '@ngneat/hot-toast';
import {FormControl, FormsModule} from '@angular/forms';
import {CommonModule} from '@angular/common';
import {AdmintopComponent} from '../../partials/top/admintop/admintop.component';
import {TuiDrawer} from '@taiga-ui/kit';
import {TuiHeader} from '@taiga-ui/layout';
import {GlobalComponent} from '../../global-component';
import {CourseMiniCardComponent} from '../../course-mini-card.component';
import {CourseCardComponent} from '../../course-card.component';
import {PolymorpheusComponent, PolymorpheusContent} from '@taiga-ui/polymorpheus';
import {CourseInsightsComponent} from './course-insights/course-insights.component';
import {CourseContentComponent} from './course-content/course-content.component';
import {CourseEnrolleesComponent} from './course-enrollment/enrollees/course-enrollees.component';

export interface Course {
  id: number;
  token: string;
  course_id: number;
  title: string;
  description: string;
  objectives: string;
  summary: string;
  category: number;
  category_name: string;
  instructor: number;
  certificate: number;
  status: string;
  level: string;
  duration_minutes: number;
  is_featured: boolean;
  is_active: boolean;
  enrollment_limit: boolean;
  selfEnroll: boolean;
  start_date: string;
  end_date: string;
  thumbnail?: string;
}
export interface Category {
  category_id: number;
  category_name: string;
}
export interface Template {
  template_id: number;
  template_name: string;
}
export interface Instructor {
  user_id: number;
  user_name: string;
}
interface PreviewData {
  course: {
    course_id: number;
    title: string;
    description: string;
  };
  will_copy: {
    modules: number;
    lessons: number;
    assessments: number;
    questions: number;
  };
  suggested_title: string;
}
interface DuplicateResult {
  original_course_id: number;
  new_course_id: number;
  new_course: any;
  statistics: {
    modules_copied: number;
    lessons_copied: number;
    assessments_copied: number;
    questions_copied: number;
    options_copied: number;
  };
}
@Component({
  selector: 'app-courses',
  standalone: true,
  imports: [
    TopComponent,
    SideComponent,
    TuiIcon,
    FormsModule,
    CommonModule,
    AdmintopComponent,
    TuiButton,
    TuiDrawer,
    TuiHeader,
    TuiLoader,
    TuiPopup,
    TuiTitle,
    CourseMiniCardComponent,
    CourseCardComponent,
    CourseInsightsComponent,
    TuiDialog,
    CourseContentComponent,
    CourseEnrolleesComponent,
    RouterLink
  ],
  templateUrl: './courses.component.html',
  styleUrl: './courses.component.css'
})
export class CourseComponent implements OnInit, OnDestroy {
  @Output() duplicated = new EventEmitter<DuplicateResult>();
  @Output() cancelled = new EventEmitter<void>();
  @Output() error = new EventEmitter<string>();
  course: Course[] = [];
  category: Category[] = [];
  template: Template[] = [];
  instructor: Instructor[] = [];
  isLoading: boolean = false;
  previewData: PreviewData | null = null;  // ============================================================
  // UI STATE
  // ============================================================
  protected readonly dialogs = inject(TuiDialogService);
  protected readonly open = signal(false);
  protected readonly control = new FormControl('Some value');

  ui_controls = {
    is_loading: false
  };

  // View mode toggle
  viewMode: 'grid' | 'list' = 'grid';
   openDuplicate = false;
   openCreate = false;
   openManage = false;
   openEnrollees = false;
  // Search
  searchQuery: string = '';

  // Delete modal
  showDeleteModal: boolean = false;
  courseToDelete: Course | null = null;

  // Publish modal
  showPublishModal: boolean = false;
  courseToPublish: Course | null = null;

  // Unpublish modal
  showUnPublishModal: boolean = false;
  courseToUnPublish: Course | null = null;

  // Archive modal
  showArchiveModal: boolean = false;
  courseToArchive: Course | null = null;



  // ============================================================
  // SESSION DATA
  // ============================================================
  session_data: any = "";
  user_session = {
    id: 0,
    tenant_id: 0,
    tenant: {
      id: 0,
      name: "",
      status: "",
    },
    token: "",
    first_name: "",
    last_name: "",
    email: "",
    phone: "",
    avatar: "",
    is_2fa: false,
    is_active: false,
    account_locked: false,
    is_admin: false,
    is_content: false,
    is_instructor: false,
    is_learner: false,
  };

  // ============================================================
  // FORM DATA
  // ============================================================
  single: Course = {
    id: 0,
    token: "",
    course_id: 0,
    title: "",
    description: "",
    objectives: "",
    summary: "",
    category: 0,
    category_name: "",
    instructor: 0,
    certificate: 0,
    status: "draft",
    level: "",
    duration_minutes: 0,
    is_featured: false,
    is_active: false,
    enrollment_limit: false,
    selfEnroll: false,
    start_date: "",
    end_date: ""
  };



  today(): string {
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
  }
  toDate(value: string | null): Date | null {
    if (!value) return null;

    const [year, month, day] = value.split('-').map(Number);
    return new Date(year, month - 1, day);
  }
  formatNewDate(date: Date | null): string | null {
    if (!date) return null;
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
  }
  showCreateModal: boolean = false;
  create_course = {
    id: 0,
    token: "",
    tenant_id: 0,
    title: "",
    description: "",
    objectives: "",
    summary: "",
    category: 0,
    instructor: 0,
    certificate: 0,
    level: "",
    pass_percentage: 0,
    duration_minutes: 1,
    enrollment_limit: 0,
    selfEnroll: true,
    is_mandatory: true,
    start_date: null as string | null,
    end_date: null as string | null
  };

  statistics = {
    total: 0,
    draft: 0,
    published: 0,
    archived: 0
  };
  insights = {
    token: "",
    userId: 0,
    tenantId: 0,
    courseId: 0,
    courseTitle: ''
  };
  duplicate = {
    token: "",
    user_id: 0,
    tenant_id: 0,
    course_id: 0,
    new_title: '',
    course_title: '',
    copy_modules: false,
    copy_lessons: false,
    copy_assessments: false,
    copy_questions: false,
    set_as_draft: false,
    preview: false
  };
  enrollees = {
    course_id: 0,
    course_title: ''
  };

  publish = {
    id: 0,
    token: "",
    course_id: 0,
    status: 'published'
  };

  unpublish = {
    id: 0,
    token: "",
    course_id: 0,
    status: 'draft'
  };

  meta = {
    total: 0,
    page: 0,
    per_page: 0,
    total_pages: 0
  };

  initial = {
    id: 0,
    tenant_id: 0,
    token: "",
    page: 1,
    per_page: 10,
    status: "published",
    category_id: null as number | null,
    search: ""
  };

  // Search debounce timer
  private searchTimeout: any = null;

  // ============================================================
  // CONSTRUCTOR
  // ============================================================
  constructor(
    private router: Router,
    private crudService: CrudService,
    private toast: HotToastService,
    private readonly dialogService: TuiDialogService
  ) {}

  // ============================================================
  // LIFECYCLE HOOKS
  // ============================================================
  async ngOnInit() {
    this.session_data = sessionStorage.getItem("SESSION");
    this.user_session = decodeSessionBlob(this.session_data) ?? this.user_session;

    this.publish.id = this.user_session.id;
    this.publish.token = this.user_session.token;

    this.create_course.id = this.user_session.id;
    this.create_course.tenant_id = this.user_session.tenant_id;
    this.create_course.token = this.user_session.token;
    this.create_course.instructor = this.user_session.id;

    this.insights.userId = this.user_session.id;
    this.insights.tenantId = this.user_session.tenant_id;
    this.insights.token = this.user_session.token;


    this.duplicate.user_id = this.user_session.id;
    this.duplicate.tenant_id = this.user_session.tenant_id;
    this.duplicate.token = this.user_session.token;

    this.unpublish.id = this.user_session.id;
    this.unpublish.token = this.user_session.token;

    this.single.id = this.user_session.id;
    this.single.token = this.user_session.token;

    this.initial.id = this.user_session.id;
    this.initial.tenant_id = this.user_session.tenant_id;
    this.initial.token = this.user_session.token;

    await this.list_course();
    await this.list_category();
    await this.list_templates();
    await this.list_instructor();
  }

  ngOnDestroy() {
    // Clean up search timeout
    if (this.searchTimeout) {
      clearTimeout(this.searchTimeout);
    }
  }

  // ============================================================
  // NAVIGATION
  // ============================================================
  goBack() {
    this.router.navigate(['/administration']).then(r => console.log(r));
  }

  onPreview(): void {
    this.isLoading = true;
    this.previewData = null;
    this.duplicate.preview = true;
    this.crudService.post_request(this.duplicate, GlobalComponent.courseDuplicate)
      .subscribe({
        next: (response) => {
          if (response.status === 'success') {
            this.previewData = response.data;
            if (!this.duplicate.new_title && response.data.suggested_title) {
              this.duplicate.new_title = response.data.suggested_title;
            }
          } else {
            this.error.emit(response.message || 'Failed to generate preview');
          }
          this.isLoading = false;
        },
        error: (err) => {
          this.error.emit('Failed to connect to server');
          this.isLoading = false;
          console.error('Preview error:', err);
        }
      });
  }
  onDuplicate(): void {
    this.isLoading = true;
    this.isLoading = true;
    this.duplicate.user_id = this.user_session.id;
    this.duplicate.token = this.user_session.token;
    this.duplicate.tenant_id = this.user_session.tenant_id;
    this.duplicate.preview = false;
    this.crudService.post_request(this.duplicate, GlobalComponent.courseDuplicate)
      .subscribe({
        next: (response) => {
          if (response.status === 'success') {
            this.openDuplicate = false;
            this.duplicated.emit(response.data);
            this.success_notification(response.message);
            this.list_course();
          } else {
            this.error.emit(response.message || 'Failed to duplicate course');
          }
          this.isLoading = false;
        },
        error: (err) => {
          this.error.emit('Failed to connect to server');
          this.isLoading = false;
          console.error('Duplicate error:', err);
        }
      });
  }
  selectAll(value: boolean): void {
    this.duplicate.copy_modules = value;
    this.duplicate.copy_lessons = value;
    this.duplicate.copy_assessments = value;
    this.duplicate.copy_questions = value;
  }
  // ============================================================
  // NOTIFICATIONS
  // ============================================================
  success_notification(message: string) {
    this.toast.success(message);
  }
  error_notification(message: string) {
    this.toast.error(message);
  }
  // ============================================================
  // API CALLS
  // ============================================================
  list_course(page: number = 1, per_page: number = 4) {
    this.initial.page = page;
    this.initial.per_page = per_page;
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.initial, GlobalComponent.listCourse)
      .subscribe({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.course = response.data.courses;
            this.meta = response.data.meta;
            this.statistics = response.data.statistics;
            this.ui_controls.is_loading = false;
          }
        },
        error: (e) => {
          console.error(e);
          this.error_notification("Failed to load courses");
          this.ui_controls.is_loading = false;
        }
      });
  }
  list_category() {
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.initial, GlobalComponent.activeCategory)
      .subscribe({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.category = response.data;
            this.ui_controls.is_loading = false;
          }
        },
        error: (e) => {
          console.error(e);
          this.ui_controls.is_loading = false;
        }
      });
  }
  list_templates() {
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.initial, GlobalComponent.activeTemplate)
      .subscribe({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.template = response.data;
            this.ui_controls.is_loading = false;
          }
        },
        error: (e) => {
          console.error(e);
          this.ui_controls.is_loading = false;
        }
      });
  }
  list_instructor() {
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.initial, GlobalComponent.InstructorList)
      .subscribe({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.instructor = response.data;
            this.ui_controls.is_loading = false;
          }
        },
        error: (e) => {
          console.error(e);
          this.ui_controls.is_loading = false;
        }
      });
  }
  // ============================================================
  // FILTER METHODS
  // ============================================================
  filterByStatus(status: string) {
    this.initial.status = status;
    this.list_course(1, this.initial.per_page);
  }

  onSearchChange() {
    // Clear existing timeout
    if (this.searchTimeout) {
      clearTimeout(this.searchTimeout);
    }

    // Debounce search - wait 400ms after user stops typing
    this.searchTimeout = setTimeout(() => {
      this.initial.search = this.searchQuery;
      this.list_course(1, this.initial.per_page);
    }, 400);
  }
  clearSearch() {
    this.searchQuery = '';
    this.initial.search = '';
    if (this.searchTimeout) {
      clearTimeout(this.searchTimeout);
    }
    this.list_course(1, this.initial.per_page);
  }
  clearFilters() {
    this.initial.status = 'published';
    this.initial.category_id = null;
    this.initial.search = '';
    this.searchQuery = '';
    if (this.searchTimeout) {
      clearTimeout(this.searchTimeout);
    }
    this.list_course(1, this.initial.per_page);
  }
  hasActiveFilters(): boolean {
    return this.initial.status !== '' ||
      this.initial.category_id !== null ||
      this.searchQuery.trim() !== '';
  }

  // ============================================================
  // DELETE METHODS
  // ============================================================
  confirmDelete(course: Course) {
    this.courseToDelete = course;
    this.showDeleteModal = true;
  }
  cancelDelete() {
    this.courseToDelete = null;
    this.showDeleteModal = false;
  }
  executeDelete() {
    if (!this.courseToDelete) return;
    const deletePayload = {
      id: this.user_session.id,
      tenant_id: this.user_session.tenant_id,
      token: this.user_session.token,
      course_id: this.courseToDelete.course_id,
      status: 'delete'
    };
    this.ui_controls.is_loading = true;
    this.crudService.post_request(deletePayload, GlobalComponent.swapStatusCourse)
      .subscribe({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.success_notification(response.message);
            this.list_course(this.initial.page, this.initial.per_page);
          } else {
            this.error_notification(response.message);
          }
          this.ui_controls.is_loading = false;
          this.showDeleteModal = false;
          this.courseToDelete = null;
        },
        error: (e) => {
          console.error(e);
          this.error_notification("Failed to delete course");
          this.ui_controls.is_loading = false;
          this.showDeleteModal = false;
          this.courseToDelete = null;
        }
      });
  }

  // ============================================================
  // PUBLISH METHODS
  // ============================================================
  confirmPublish(course: Course) {
    this.courseToPublish = course;
    this.showPublishModal = true;
  }
  cancelPublish() {
    this.courseToPublish = null;
    this.showPublishModal = false;
  }
  executePublish() {
    if (!this.courseToPublish) return;
    const publishPayload = {
      id: this.user_session.id,
      tenant_id: this.user_session.tenant_id,
      token: this.user_session.token,
      course_id: this.courseToPublish.course_id,
      status: 'published'
    };
    this.ui_controls.is_loading = true;
    this.crudService.post_request(publishPayload, GlobalComponent.swapStatusCourse)
      .subscribe({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.success_notification(response.message);
            this.list_course(this.initial.page, this.initial.per_page);
          } else {
            this.error_notification(response.message);
          }
          this.ui_controls.is_loading = false;
          this.showPublishModal = false;
          this.courseToPublish = null;
        },
        error: (e) => {
          console.error(e);
          this.error_notification("Failed to publish course");
          this.ui_controls.is_loading = false;
          this.showPublishModal = false;
          this.courseToPublish = null;
        }
      });
  }

  // ============================================================
  // UNPUBLISH METHODS
  // ============================================================
  confirmUnPublish(course: Course) {
    this.courseToUnPublish = course;
    this.showUnPublishModal = true;
  }
  cancelUnPublish() {
    this.courseToUnPublish = null;
    this.showUnPublishModal = false;
  }
  executeUnPublish() {
    if (!this.courseToUnPublish) return;
    const UnPublishPayload = {
      id: this.user_session.id,
      tenant_id: this.user_session.tenant_id,
      token: this.user_session.token,
      course_id: this.courseToUnPublish.course_id,
      status: 'draft'
    };
    this.ui_controls.is_loading = true;
    this.crudService.post_request(UnPublishPayload, GlobalComponent.swapStatusCourse)
      .subscribe({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.success_notification(response.message);
            this.list_course(this.initial.page, this.initial.per_page);
          } else {
            this.error_notification(response.message);
          }
          this.ui_controls.is_loading = false;
          this.showUnPublishModal = false;
          this.courseToUnPublish = null;
        },
        error: (e) => {
          console.error(e);
          this.error_notification("Failed to unpublish course");
          this.ui_controls.is_loading = false;
          this.showUnPublishModal = false;
          this.courseToUnPublish = null;
        }
      });
  }

  // ============================================================
  // ARCHIVE METHODS
  // ============================================================
  confirmArchive(course: Course) {
    this.courseToArchive = course;
    this.showArchiveModal = true;
  }
  cancelArchive() {
    this.courseToArchive = null;
    this.showArchiveModal = false;
  }
  executeArchive() {
    if (!this.courseToArchive) return;
    const ArchivePayload = {
      id: this.user_session.id,
      tenant_id: this.user_session.tenant_id,
      token: this.user_session.token,
      course_id: this.courseToArchive.course_id,
      status: 'archived'
    };
    this.ui_controls.is_loading = true;
    this.crudService.post_request(ArchivePayload, GlobalComponent.swapStatusCourse)
      .subscribe({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.success_notification(response.message);
            this.list_course(this.initial.page, this.initial.per_page);
          } else {
            this.error_notification(response.message);
          }
          this.ui_controls.is_loading = false;
          this.showArchiveModal = false;
          this.courseToArchive = null;
        },
        error: (e) => {
          console.error(e);
          this.error_notification("Failed to unpublish course");
          this.ui_controls.is_loading = false;
          this.showArchiveModal = false;
          this.courseToArchive = null;
        }
      });
  }

  // ===========================================================
  // CREATE COURSE METHOD
  // ============================================================
  confirmCreate() {
    this.router.navigate(['/courses/create']).then(r => console.log(r));
  }
  manageCourse() {
    this.openManage = true;
  }
  cancelManageCourse() {
    this.openManage = false;
  }
  cancelCreate() {
    this.resetCreateForm();
    this.openCreate = false;
  }
  executeCreate() {
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.create_course, GlobalComponent.createCourse)
      .subscribe({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.success_notification(response.message);
            this.cancelCreate();
            this.initial.status = "";
            this.list_course();
          } else {
            this.error_notification(response.message);
          }
          this.ui_controls.is_loading = false;
          this.cancelCreate();
        },
        error: (e) => {
          console.error(e);
          this.error_notification("Failed to create course");
          this.ui_controls.is_loading = false;
          this.showCreateModal = false;
        }
      });
  }
  getStatusClass(status: string): string {
    const statusClasses: Record<string, string> = {
      'draft': 'status-draft',
      'published': 'status-published',
      'archived': 'status-archived'
    };
    return statusClasses[status] || 'status-draft';
  }
  getStatusIcon(status: string): string {
    const statusIcons: Record<string, string> = {
      'draft': 'bi bi-file-earmark',
      'published': 'bi bi-check-circle-fill',
      'archived': 'bi bi-archive-fill'
    };
    return statusIcons[status] || 'bi bi-file-earmark';
  }
  getStatusLabel(status: string): string {
    const labels: Record<string, string> = {
      'draft': 'Draft',
      'published': 'Published',
      'archived': 'Archived'
    };
    return labels[status] || status;
  }

  formatDate(dateString: string): string {
    if (!dateString) return '';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', {
      month: 'short',
      day: 'numeric',
      year: 'numeric'
    });
  }

  // ============================================================
  // FORM METHODS
  // ============================================================
  private resetCreateForm() {
    this.create_course = {
      id: this.user_session.id,
      tenant_id: this.user_session.tenant_id,
      token: this.user_session.token,
      title: "",
      description: "",
      objectives: "",
      summary: "",
      category: 0,
      instructor: 0,
      certificate: 0,
      level: "",
      pass_percentage: 0,
      duration_minutes: 0,
      enrollment_limit: 0,
      selfEnroll: true,
      is_mandatory: true,
      start_date: null as string | null,
      end_date: null as string | null
    };
  }

  // ============================================================
  // PAGINATION
  // ============================================================
  paginate() {
    this.list_course(1, this.initial.per_page);
  }

  showCourseInsights(courseId: number, courseTitle: string): void {
    this.dialogs.open(
      new PolymorpheusComponent(CourseInsightsComponent),
      {
        size: 'page',
        closeable: true,
        dismissible: true,
        data: {
          token: this.insights.token,
          userId: this.insights.userId,
          tenantId: this.insights.tenantId,
          courseId: courseId,
          courseTitle: courseTitle
        }
      }
    ).subscribe();
  }
  duplicateCourse(c: Course): void {
    this.duplicate.course_id = c.course_id;
    this.duplicate.course_title = c.title;
    this.openDuplicate = true;
  }
  courseEnrollees(c: Course): void {
    this.enrollees.course_id = c.course_id;
    this.enrollees.course_title = c.title;
    this.openEnrollees = true;
  }
  editCourse(c: Course) {

  }

  manage_course(id: number, title:string) {
    this.router.navigate(
      ['/', 'manageCourse'],
      { queryParams: {id, title} }
    ).then(r => console.log(r));
  }

  edit_course(courseId: number, title: string): void {
    this.router.navigate(['/courses', courseId, 'edit']);
  }
}
