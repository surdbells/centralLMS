import {Component, OnInit} from '@angular/core';
import {TuiLoader} from '@taiga-ui/core';
import {CrudService} from '../../services/crud.service';
import {Router, RouterLink} from '@angular/router';
import {HotToastService} from '@ngneat/hot-toast';
import {CookieService} from 'ngx-cookie-service';
import {GlobalComponent} from '../../global-component';
import {FormsModule} from '@angular/forms';
import {CommonModule, NgClass, NgIf, NgOptimizedImage} from '@angular/common';
import {LanguageSwitcherComponent} from '../../language-switcher.component';
import {TranslatePipe} from '../../translate.pipe';
import {Title, Meta} from '@angular/platform-browser';


@Component({
  selector: 'app-login',
  imports: [
    TuiLoader,
    FormsModule,
    CommonModule,
    NgIf,
    RouterLink,
    LanguageSwitcherComponent,
    TranslatePipe
  ],
  templateUrl: './login.component.html',
  standalone: true,
  styleUrl: './login.component.css'
})
export class LoginComponent implements OnInit{
  constructor(
    private crudService: CrudService,
    private router: Router,
    private titleService: Title, 
    private metaService: Meta,
    private cookieService: CookieService,
    private toast: HotToastService
  ) {}
  user_session_string = "";
  fieldTextType: boolean = false;
    ngOnInit(): void {
      sessionStorage.clear();
      this.titleService.setTitle('Login | Central LMS Africa User Portal');
      this.metaService.updateTag({ name: 'title', content: 'LMS Login Africa | Access Your Central LMS Account' });
      this.metaService.updateTag({ name: 'description', content: 'Login to Central LMS to access your courses, training materials, and employee learning dashboard from anywhere in Africa.' });
      this.metaService.updateTag({ name: 'keywords', content: 'LMS login Africa, eLearning login Nigeria, corporate training portal Africa, employee LMS login' });

  }
  loading = false;
  login = {
    email: "",
    password: ""
  };
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
  user_login() {
    if (this.login.email.length === 0) {
      this.error_notification("Email address is required");
      return;
    }
    if (this.login.password.length === 0) {
      this.error_notification("Password is required");
      return;
    }
    if (!GlobalComponent.validateEmail(this.login.email)) {
      this.error_notification("Invalid email format provided");
      return;
    }
    this.loading = true;
    this.crudService.post_request(this.login, GlobalComponent.UserLogin)
      .subscribe(({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            if (response.data.tenant.status === "active") {
              this.loading = false;
              this.user_session = response.data;
              this.success_notification(response.message);
              if (this.user_session.is_active){
                this.user_session_string = btoa(JSON.stringify(this.user_session));
                sessionStorage.setItem('SESSION', this.user_session_string);
                sessionStorage.setItem('IS_CURRENT', 'learner');
                this.router.navigate(['/', 'account']).then(r => console.log(r));
              }else {
                this.error_notification("Your account is inactive, contact system administrator for support.")
              }
            }else {
              this.loading = false;
              this.error_notification("Your organization account is currently inactive, contact system administrator for support.")
            }
          }
          if (response.response_code == 200 && response.status === "failed") {
            this.loading = false;
            this.error_notification(response.message);
          }
          if (response.response_code == 400 && response.status === "failed") {
            this.loading = false;
            this.error_notification(response.message);
          }
        },
        error: (e) => {
          console.error(e);
          this.error_notification(e);
          this.loading = false;
        },
        complete: () => {
          console.info('complete');
        }
      }))
  }
  toggleFieldTextType() {
    this.fieldTextType = !this.fieldTextType;
  }
  error_notification(message: string) {
    this.toast.error(message);
  }
  success_notification(message: string) {
    this.toast.success(message);
  }
}

