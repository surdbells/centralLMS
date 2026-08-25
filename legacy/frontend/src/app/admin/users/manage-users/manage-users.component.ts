import {Component, inject, OnInit, signal} from '@angular/core';
import {ActivatedRoute, Router, RouterLink} from '@angular/router';
import {CrudService} from '../../../services/crud.service';
import {HotToastService} from '@ngneat/hot-toast';
import {AsideComponent} from '../../../partials/aside/aside.component';
import {DatePipe, NgForOf, NgIf, NgOptimizedImage} from '@angular/common';
import {TopComponent} from '../../../partials/top/top.component';
import {
  TuiButton,
  TuiDialogService,
  TuiIcon,
  TuiLabel,
  TuiLoader,
  TuiPopup,
  TuiTextfieldComponent,
  TuiTextfieldDirective,
  TuiTitle
} from '@taiga-ui/core';
import {GlobalComponent} from '../../../global-component';
import {DataTablesModule} from 'angular-datatables';
import {TUI_CONFIRM, TuiDrawer} from '@taiga-ui/kit';
import {TuiResponsiveDialogService} from '@taiga-ui/addon-mobile';
import {FormControl, FormsModule} from "@angular/forms";
import {TranslatePipe} from "../../../translate.pipe";
import {TuiHeader} from '@taiga-ui/layout';
import {SideComponent} from '../../../partials/side/side.component';
import {filter} from 'rxjs';
import {AdmintopComponent} from '../../../partials/top/admintop/admintop.component';
export interface User {
  id: number;
  token: string;
  staffId: string;
  first_name: string;
  last_name: string;
  email: string;
  picture: string;
  department: string;
  job_title: string;
  level: string;
  team: string;
  branch: string;
  is_active: boolean;
  is_admin: boolean;
  is_learner: boolean;
  is_content: boolean;
  is_hr: boolean;
  is_instructor: boolean;
  last_login: string | null;
}
export interface Branch {
  branch_id: number;
  branch_name: string;
}
export interface Department {
  department_id: number;
  department_name: string;
}
export interface JobTitles {
  jobTitle_id: number;
  jobTitle_name: string;
}
export interface Levels {
  level_id: number;
  level_name: string;
}
export interface Teams {
  team_id: number;
  team_name: string;
}

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
@Component({
  selector: 'app-manage-users',
  standalone: true,
  imports: [
    DatePipe,
    FormsModule,
    NgForOf,
    NgIf,
    SideComponent,
    TopComponent,
    TuiButton,
    TuiDrawer,
    TuiHeader,
    TuiIcon,
    TuiLoader,
    TuiPopup,
    TuiTitle,
    NgOptimizedImage,
    AdmintopComponent
  ],
  templateUrl: './manage-users.component.html',
  styleUrl: './manage-users.component.css'
})
export class ManageUsersComponent implements OnInit{
  users: User[] = [];
  branches: Branch[] = [];
  department: Department[] = [];
  jobTitles: JobTitles[] = [];
  levels: Levels[] = [];
  teams: Teams[] = [];

  gamificationResponse?: UserGamificationResponse;
  statistics!: UserStatistics;
  level!: UserLevel;
  recentBadges!: RecentBadge[];
  protected readonly dialogs = inject(TuiDialogService);
  protected readonly open = signal(false);

  protected readonly control = new FormControl('Some value');
  ui_controls = {
    is_loading: false
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
  single = {
    id: 0,
    token: "",
    tenant_id: 0,
    user_id: 0,
    staffId: "",
    first_name: "",
    last_name: "",
    email: "",
    phone: "",
    department: 0,
    job_title: 0,
    level: 0,
    team: 0,
    branch: 0,
    course_count: 0,
    activity_count: 0,
    course_started: 0,
    course_completed: 0,
    completion_rate: 0,
    departmentName: "",
    job_titleName: "",
    levelName: "",
    teamName: "",
    branchName: "",
    picture: "",
    days_since_last_login: "",
    last_login: "",
    is_admin: false,
    is_learner: false,
    is_content: false,
    is_hr: false,
    is_instructor: false,
    is_active: false,
  };
  password_c= {
    id: 0,
    token: "",
    user_id: 0,
    tenant_id: 0,
    password: ""
  };
  constructor(
    private router: Router,
    private route: ActivatedRoute,
    private crudService: CrudService,
    private toast: HotToastService,
  ) {}
  async ngOnInit() {
    this.session_data = sessionStorage.getItem("SESSION");
    this.user_session = GlobalComponent.decodeBase64(this.session_data);
    this.session_data = sessionStorage.getItem("SESSION");
    this.user_session = JSON.parse(atob(this.session_data));

    this.single.id = Number(this.route.snapshot.queryParamMap.get('id'));
    this.single.first_name = <string>this.route.snapshot.queryParamMap.get('firstName');
    this.single.last_name = <string>this.route.snapshot.queryParamMap.get('lastName');


    this.activate.id = this.user_session.id;
    this.activate.token = this.user_session.token;
    this.activate.tenant_id = this.user_session.tenant_id;

    this.deactivate.id = this.user_session.id;
    this.deactivate.token = this.user_session.token;
    this.deactivate.tenant_id = this.user_session.tenant_id;

    this.password_c.id = this.user_session.id;
    this.password_c.token = this.user_session.token;
    this.password_c.tenant_id = this.user_session.tenant_id;


    this.single.id = this.user_session.id;
    this.single.token = this.user_session.token;
    this.single.tenant_id = this.user_session.tenant_id;

    this.initial.id = this.user_session.id;
    this.initial.user_id = Number(this.route.snapshot.queryParamMap.get('id'));
    this.initial.tenant_id = this.user_session.tenant_id;
    this.initial.token = this.user_session.token;

    this.getProfile();
    this.get_dashboard();
    await this.list_branches();
    await this.list_departments();
    await this.list_teams();
    await this.list_level();
    await this.list_title();
  }
  activate = {
    id: 0,
    token: "",
    user_id: 0,
    tenant_id: 0,
    status: true
  }
  deactivate = {
    id: 0,
    token: "",
    user_id: 0,
    tenant_id: 0,
    status: false
  }

  goBack() {
    this.router.navigate(['/users']).then(r => console.log(r));
  }
  meta = {
    total: 0,
    page: 0,
    per_page: 0,
    total_pages: 0
  };
  initial = {
    id: 0,
    user_id: 0,
    tenant_id: 0,
    token: ""
  }

  success_notification(message: string) {
    this.toast.success(message);
  }
  error_notification(message: string) {
    this.toast.error(message);
  }
  getProfile() {
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.initial, GlobalComponent.SingleUser)
      .subscribe(({ next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.single = response.data;
            this.ui_controls.is_loading = false;
          }
        }
      }))
  }
  get_dashboard(): void {
    this.ui_controls.is_loading = true;
    const params = {
      user_id: Number(this.route.snapshot.queryParamMap.get('id')),
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
  list_branches() {
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.initial, GlobalComponent.activeBranch)
      .subscribe(({ next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.branches = response.data;
            this.ui_controls.is_loading = false;
          }
        }
      }))
  }
  list_departments() {
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.initial, GlobalComponent.activeDepartment)
      .subscribe(({ next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.department = response.data;
            this.ui_controls.is_loading = false;
          }
        }
      }))
  }
  list_teams() {
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.initial, GlobalComponent.activeTeam)
      .subscribe(({ next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.teams = response.data;
            this.ui_controls.is_loading = false;
          }
        }
      }))
  }
  list_level() {
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.initial, GlobalComponent.activeLevels)
      .subscribe(({ next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.levels = response.data;
            this.ui_controls.is_loading = false;
          }
        }
      }))
  }
  list_title() {
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.initial, GlobalComponent.activeJobTitle)
      .subscribe(({ next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.jobTitles = response.data;
            this.ui_controls.is_loading = false;
          }
        }
      }))
  }
  update_user() {
    if (this.single.first_name.length === 0) {
      this.error_notification("First name is required");
      return;
    }
    if (this.single.last_name.length === 0) {
      this.error_notification("Last name is required");
      return;
    }
    if (this.single.email.length === 0) {
      this.error_notification("Email address is required");
      return;
    }
    if (!GlobalComponent.validateEmail(this.single.email)) {
      this.error_notification("Invalid email format provided");
      return;
    }
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.single, GlobalComponent.UserUpdate)
      .subscribe(({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.ui_controls.is_loading = false;
            this.success_notification(response.message);
            this.getProfile();
          }else{
            this.error_notification(response.message);
            this.ui_controls.is_loading = false;
          }
        },
        error: (e) => {
          console.error(e);
          this.error_notification("Unable to complete your request at this time.");
          this.ui_controls.is_loading = false;
        },
        complete: () => {
          console.info('complete');
          this.ui_controls.is_loading = false;
        }
      }))
  }

  public CloseForm(): void {
    this.open.set(false);
  }

  Activity() {

  }
  switchViewActivity() {

  }
  switchViewProfile() {

  }
  switchViewCourse() {

  }
  Course() {

  }
  switchViewReports() {

  }
  Reports() {

  }
  Notes() {

  }
  switchViewNotes() {

  }


  start_activate(user: number, name: string) {
    this.dialogs
      .open<boolean>(TUI_CONFIRM, {
        label: 'Confirm',
        data: {
          content: name +' account will be activated ?',
          yes: 'Activate',
          no: 'Cancel',
        },
      })
      .subscribe((response) => {
        if (response){
          this.activate_user(user);
        }
      });
  }
  activate_user(user: number){
    this.ui_controls.is_loading = true;
    this.activate.user_id = user;
    this.crudService.post_request(this.activate, GlobalComponent.UserStatus)
      .subscribe(({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.ui_controls.is_loading = false;
            this.success_notification(response.message);
            this.getProfile();
            this.get_dashboard();
          }
        }
      }))
  }
  start_deactivate(user: number, name: string) {
    this.dialogs
      .open<boolean>(TUI_CONFIRM, {
        label: 'Confirm',
        data: {
          content: ''+ name +' will be deactivated?',
          yes: 'Deactivate',
          no: 'Cancel',
        },
      })
      .subscribe((response) => {
        if (response){
          this.deactivate_user(user);
        }
      });
  }
  deactivate_user(user: number){
    this.ui_controls.is_loading = true;
    this.deactivate.user_id = user;
    this.crudService.post_request(this.deactivate, GlobalComponent.UserStatus)
      .subscribe(({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.ui_controls.is_loading = false;
            this.success_notification(response.message);
            this.getProfile();
          }
        }
      }))
  }

  public onClose(): void {
    this.open.set(false);
    this.dialogs
      .open(TUI_CONFIRM, {
        label: 'Cancel editing form?',
        size: 's',
        data: {
          content: 'You have unsaved changes that will be lost',
        },
      })
      .pipe(filter(Boolean))
      .subscribe(() => {

      });
  }

 update_password() {
    this.password_c.user_id = this.single.id
      this.ui_controls.is_loading = true;
      this.crudService.post_request(this.password_c, GlobalComponent.UserPassword)
        .subscribe(({
          next: (response) => {
            if (response.response_code === 200 && response.status === "success") {
              this.ui_controls.is_loading = false;
              this.success_notification(response.message);
            }else{
              this.error_notification(response.message);
              this.ui_controls.is_loading = false;
            }
          },
          error: (e) => {
            console.error(e);
            this.error_notification("Unable to complete your request at this time.");
            this.ui_controls.is_loading = false;
          },
          complete: () => {
            console.info('complete');
          }
        }))
  }
}
