import { decodeSessionBlob } from '../../core/utils/session-blob';
import {ChangeDetectionStrategy, Component, OnInit, signal, ViewChild} from '@angular/core';
import {SideComponent} from '../../partials/side/side.component';
import {TopComponent} from '../../partials/top/top.component';
import {Router, RouterLink, RouterLinkActive} from '@angular/router';
import {CrudService} from '../../services/crud.service';
import {HotToastService} from '@ngneat/hot-toast';
import {
  ApexAxisChartSeries,
  ApexChart,
  ChartComponent,
  ApexDataLabels,
  ApexPlotOptions,
  ApexYAxis,
  ApexLegend,
  ApexStroke,
  ApexXAxis,
  ApexFill,
  ApexTooltip, NgApexchartsModule
} from "ng-apexcharts";
import {CommonModule} from '@angular/common';
import {GlobalComponent} from '../../global-component';
import {
  TuiButton,
  TuiExpandComponent,
  TuiIcon,
  TuiLabel,
  TuiLink,
  TuiLoader,
  TuiTextfieldComponent,
  TuiTitle
} from '@taiga-ui/core';
import {DataTablesModule} from 'angular-datatables';
import {Config} from 'datatables.net';
import {TranslatePipe} from '../../translate.pipe';
import {TuiBadge, TuiChevron, TuiTab, TuiTabsWithMore} from '@taiga-ui/kit';
import {TuiItem} from '@taiga-ui/cdk';
import {TuiCardCollapsed, TuiCardLarge, TuiCardRow, TuiHeader} from '@taiga-ui/layout';
import {TuiTableTd, TuiTableTh} from '@taiga-ui/addon-table';
import {FormsModule} from '@angular/forms';
import {LearnerDashboardComponent} from '../../admin/learner-dashboard/learner-dashboard.component';
export type ChartOptions = {
  series: ApexAxisChartSeries;
  chart: ApexChart;
  dataLabels: ApexDataLabels;
  plotOptions: ApexPlotOptions;
  yaxis: ApexYAxis;
  xaxis: ApexXAxis;
  fill: ApexFill;
  tooltip: ApexTooltip;
  stroke: ApexStroke;
  legend: ApexLegend;
};
export interface UserGamificationResponse {
  success: boolean;
  data: UserGamificationData;
}
export interface UserGamificationData {
  user_id: number;
  tenant_id: number;
  statistics: UserStatistics;
  level: UserLevel;
  breakdown: UserBreakdown;
  recent_badges: RecentBadge[];
  generated_at: string;
}
export interface UserStatistics {
  total_points: number;
  total_badges: number;
  total_certificates: number;
}
export interface UserLevel {
  level_number: number;
  level_name: string;
  level_icon_url: string;
  progress_percentage: number;
}
export interface UserBreakdown {
  points_by_type: PointsByType;
  badges_by_type: BadgesByType;
}
export interface PointsByType {
  course_completion: number;
  assessment_pass: number;
  badge_earned: number;
  other: number;
}
export interface BadgesByType {
  achievement: number;
  milestone: number;
  completion: number;
  streak: number;
  participation: number;
}
export interface RecentBadge {
  user_badge_id: number;
  badge_id: number;
  badge_name: string;
  badge_icon_url: string;
  badge_type: string;
  points_value: number;
  earned_at: string;
}


export interface Note {
  note_id: number;
  tenant_id: number;
  user_id: number;
  lesson_id: number;
  title: string;
  content: string;
  lesson_timestamp: number | null;
  is_pinned: boolean;
  is_active: boolean;
  created_at: string;
  updated_at: string;
  lesson_title?: string;
  module_title?: string;
  course_title?: string;
  author_name?: string;
}
export interface NotesResponse {
  success: boolean;
  status: number;
  message: string;
  data: {
    notes: Note[];
    meta: {
      total: number;
      page: number;
      per_page: number;
      total_pages: number;
      sort_by: string;
      sort_dir: string;
    };
    links?: any;
  };
}


@Component({
  selector: 'app-user',
  standalone: true,
  templateUrl: './user.component.html',
  imports: [SideComponent, CommonModule, FormsModule, TopComponent, NgApexchartsModule, ChartComponent, TuiLoader, DataTablesModule, RouterLink, TranslatePipe, TuiTabsWithMore, TuiItem, TuiTab, TuiTextfieldComponent, TuiLabel, TuiCardCollapsed, TuiCardLarge, TuiHeader, TuiTitle, TuiLink, TuiCardRow, TuiBadge, TuiButton, TuiChevron, TuiExpandComponent, TuiTableTh, TuiTableTd, RouterLinkActive, LearnerDashboardComponent],
  styleUrl: './user.component.css'
})
export class UserComponent implements OnInit {
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
  showNoteForm: boolean = false;
  editingNote: Note | null = null;
  noteTitle: string = '';
  noteContent: string = '';
  gamificationResponse?: UserGamificationResponse;
  statistics!: UserStatistics;
  level!: UserLevel;
  recentBadges!: RecentBadge[];
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
    this.get_dashboard();
  }
  error_notification(message: string) {
    this.toast.error(message);
  }
  success_notification(message: string) {
    this.toast.success(message);
  }
  sign_out(): void {
    this.success_notification("User logged out successfully.");
    this.router.navigate(['/login']).then(r => console.log(r));
  }
  get_dashboard(): void {
    this.ui_controls.is_loading = true;
    const params = {
      user_id: this.user_session.id,
      tenant_id: this.user_session.tenant_id
    };
    this.crudService
      .get_request(GlobalComponent.UserStatistics, params)
      .subscribe({
        next: (response: any) => {
          if (response.success) {
            this.gamificationResponse = response;
            this.statistics = response.data.statistics;
            this.level = response.data.level;
            this.recentBadges = response.data.recent_badges;
            console.log(this.statistics);
            this.ui_controls.is_loading = false;
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
}
