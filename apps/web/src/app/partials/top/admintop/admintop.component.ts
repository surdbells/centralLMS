import { decodeSessionBlob } from '../../../core/utils/session-blob';
import {Component, OnInit} from '@angular/core';
import {LanguageSwitcherComponent} from "../../../language-switcher.component";
import {CommonModule, NgForOf, NgIf} from "@angular/common";
import {Router, RouterLink, RouterLinkActive} from "@angular/router";
import {TuiIcon} from "@taiga-ui/core";
import {Notifications} from '../../../class/notifications';
import {CrudService} from '../../../services/crud.service';
import {HotToastService} from '@ngneat/hot-toast';
import {GlobalComponent} from '../../../global-component';

@Component({
  selector: 'app-admintop',
  standalone: true,
    imports: [
        LanguageSwitcherComponent,
        NgForOf,
        NgIf,
        CommonModule,
        RouterLink,
        RouterLinkActive,
        TuiIcon
    ],
  templateUrl: './admintop.component.html',
  styleUrl: './admintop.component.css'
})
export class AdmintopComponent  implements OnInit {
  notifications?: Notifications[];

  constructor(
    private router: Router,
    private crudService: CrudService,
    private toast: HotToastService,
  ) {}
  ui_controls = {
    is_loading: false,
    count: 0
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
    this.notification.id =  this.user_session.id;
    this.notification.token =  this.user_session.token;
    // this.get_notifications();
  }
  error_notification(message: string) {
    this.toast.error(message);
  }
  success_notification(message: string) {
    this.toast.success(message);
  }
  notification = {
    id: 0,
    token: ""
  }
  get_notifications() {
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.notification, GlobalComponent.getNotifications)
      .subscribe(({
        next: (response: any) => {
          if (response.status === 'success') {
            this.ui_controls.is_loading = false;
            this.ui_controls.count = response.message;
            this.notifications = response.data;
          } else {
            this.ui_controls.is_loading = false;
          }
          this.ui_controls.is_loading = false;
        },
        error: (e: any) => {
          console.error(e);
          this.error_notification(typeof e === 'string' ? e : 'Request failed');
          this.ui_controls.is_loading = false;
        },
        complete: () => {
          this.ui_controls.is_loading = false;
        }
      }));
  }
  mark_notifications() {
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.notification, GlobalComponent.markNotifications)
      .subscribe(({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === 'success') {
            this.ui_controls.is_loading = false;
            this.success_notification(response.message);
            this.get_notifications();
          } else {
            this.error_notification(response.message);
            this.ui_controls.is_loading = false;
          }

        },
        error: (e: any) => {
          console.error(e);
          this.error_notification(typeof e === 'string' ? e : 'Request failed');
          this.ui_controls.is_loading = false;
        },
        complete: () => {
          // optional
        }
      }));
  }
  sign_out(): void {
    localStorage.clear();
    sessionStorage.clear();
    this.success_notification("User logged out successfully.");
    this.router.navigate(['/']).then(r => console.log(r));
  }

  protected readonly sessionStorage = sessionStorage;
}
