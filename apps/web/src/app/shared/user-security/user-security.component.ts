import { decodeSessionBlob } from '../../core/utils/session-blob';
import {Component, OnInit} from '@angular/core';
import {SideComponent} from "../../partials/side/side.component";
import {TopComponent} from "../../partials/top/top.component";
import {TuiIcon, TuiLoader} from "@taiga-ui/core";
import {Router} from '@angular/router';
import {CrudService} from '../../services/crud.service';
import {HotToastService} from '@ngneat/hot-toast';
import {GlobalComponent} from '../../global-component';
import {CommonModule} from '@angular/common';
import {FormsModule} from '@angular/forms';
import {AsideComponent} from '../../partials/aside/aside.component';

@Component({
  selector: 'app-user-security',
  standalone: true,
  imports: [
    TopComponent,
    CommonModule,
    FormsModule,
    TuiIcon,
    TuiLoader
  ],
  templateUrl: './user-security.component.html',
  styleUrl: './user-security.component.css'
})
export class UserSecurityComponent implements OnInit {
  constructor(
    private router: Router,
    private crudService: CrudService,
    private toast: HotToastService,
  ) {}
  ui_controls = {
    is_loading: false
  };
  update_password = {
    id: 0,
    token: "",
    first_name: "",
    email: "",
    password: "",
    confirm_password: ""
  }
  session_data: any = ""
  user_session = {
    id: 0,
    token: "",
    first_name: "",
    last_name: "",
    email: "",
    phone: "",
    is_2fa: false,
    is_active: false
  };
  ngOnInit(): void {
    this.session_data = sessionStorage.getItem("SESSION");
    this.user_session = decodeSessionBlob(this.session_data) ?? this.user_session;
    if (!this.user_session.is_active){
      this.router.navigate(['/', '']).then(r => console.log(r)); return;
    }
  }
  goBack() {
    if (this.user_session.is_active){
      this.router.navigate(['/account']).then(r => console.log(r));
    }
  }
  error_notification(message: string) {
    this.toast.error(message);
  }
  success_notification(message: string) {
    this.toast.success(message);
  }
  password_change() {
    this.update_password.id = this.user_session.id;
    this.update_password.token = this.user_session.token;
    this.update_password.first_name = this.user_session.first_name;
    this.update_password.email = this.user_session.email;
    if (this.update_password.password.length === 0) {
      this.error_notification("Password is required");
      return;
    }
    if (this.update_password.confirm_password.length === 0) {
      this.error_notification("Password does not match");
      return;
    }
    if (this.update_password.password != this.update_password.confirm_password) {
      this.error_notification("Password does not match");
      return;
    }
    this.ui_controls.is_loading = true;

    this.crudService.post_request(this.update_password, GlobalComponent.updateUserPassword)
      .subscribe(({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.ui_controls.is_loading = false;
            this.update_password.password = "";
            this.update_password.confirm_password = "";
            this.success_notification(response.message);
          }
          if (response.response_code === 200 && response.status === "failed") {
            this.ui_controls.is_loading = false;
            this.error_notification(response.message);
          }
          if (response.response_code === 400 && response.status === "failed") {
            this.ui_controls.is_loading = false;
            this.error_notification(response.message);
          }
        },
        error: (e) => {
          console.error(e);
          this.error_notification(e);
          this.ui_controls.is_loading = false;
        },
        complete: () => {
          console.info('complete');
        }
      }))
  }
}
