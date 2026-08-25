import { decodeSessionBlob } from '../../core/utils/session-blob';
import {Component, OnInit, signal} from '@angular/core';
import {LearnerDashboardComponent} from "../../admin/learner-dashboard/learner-dashboard.component";
import {TopComponent} from "../../partials/top/top.component";
import {Router} from '@angular/router';
import {CrudService} from '../../services/crud.service';
import {HotToastService} from '@ngneat/hot-toast';
import {GlobalComponent} from '../../global-component';
import {Note, RecentBadge, UserGamificationResponse, UserLevel, UserStatistics} from '../user/user.component';
import {NgIf} from '@angular/common';
import {TuiIcon, TuiLoader} from '@taiga-ui/core';

@Component({
  selector: 'app-notes',
  standalone: true,
  imports: [
    LearnerDashboardComponent,
    TopComponent,
    NgIf,
    TuiIcon,
    TuiLoader
  ],
  templateUrl: './notes.component.html',
  styleUrl: './notes.component.css'
})
export class NotesComponent implements OnInit {
  public readonly collapsed = signal(true);
  notes: Note[] = [];
  filteredNotes: Note[] = [];
  loading: boolean = false;
  error: string = '';
  searchQuery: string = '';
  filterPinned: boolean = false;

  // Pagination
  currentPage: number = 1;
  totalPages: number = 1;
  perPage: number = 20;
  totalNotes: number = 0;
  editingNote: Note | null = null;
  constructor(
    private router: Router,
    private crudService: CrudService,
    private toast: HotToastService,
  ) {
  }
  ui_controls = {
    is_loading: false,
    no_recent: false,
    is_dashboard: true,
    is_learning: false,
    is_catalog: false,
    is_report: false,
    is_note: false
  };
  initial = {
    token: "",
    user_id: 0,
    tenant_id: 0,
    page: 1,
    per_page: 5
  };
  meta = {
    total: 0,
    page: 0,
    per_page: 0,
    total_pages: 0
  };
  session_data: any = ""
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
  ngOnInit(): void {
    this.session_data = sessionStorage.getItem("SESSION");
    this.user_session = decodeSessionBlob(this.session_data) ?? this.user_session;
    this.initial.user_id = this.user_session.id;
    this.initial.tenant_id = this.user_session.tenant_id;
    this.initial.token = this.user_session.token;
    this.get_notes();
  }
  error_notification(message: string) {
    this.toast.error(message);
  }
  success_notification(message: string) {
    this.toast.success(message);
  }
  get_notes(page: number =  1, per_page: number =  10): void {
    this.initial.page = page;
    this.initial.per_page = per_page;
    this.ui_controls.is_loading = true;
    this.crudService
      .post_request(this.initial, GlobalComponent.UserNotes)
      .subscribe({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            this.notes = response.data.notes;
            this.meta = response.data.meta;
            this.totalNotes = response.data.meta.total;
            this.ui_controls.is_loading = false;
          }
          if (response.response_code == 400 && response.status === "failed") {
            this.error_notification(response.message);
          }
        },
        error: (error) => {
          console.error('Dashboard fetch failed:', error);
        },
        complete: () => {
          this.ui_controls.is_loading = false;
        }
      });
  }
  goBack() {
    if (this.user_session.is_active){
      this.router.navigate(['/account']).then(r => console.log(r));
    }else{
      this.router.navigate(['/login']).then(r => console.log(r));

    }
  }
}
