import {Component, OnInit} from '@angular/core';
import {CrudService} from '../../services/crud.service';
import {Router, RouterLink} from '@angular/router';
import {CookieService} from 'ngx-cookie-service';
import {HotToastService} from '@ngneat/hot-toast';
import {GlobalComponent} from '../../global-component';
import {CommonModule} from '@angular/common';
import {FormsModule} from '@angular/forms';
import {Title, Meta} from '@angular/platform-browser';
import {TuiLoader} from '@taiga-ui/core';
import {LanguageSwitcherComponent} from '../../language-switcher.component';
import {TranslatePipe} from '../../translate.pipe';
import {DIAL_CODES, DialCode} from '../../dial-codes';

@Component({
  selector: 'app-register',
  imports: [CommonModule, FormsModule, TuiLoader, RouterLink, LanguageSwitcherComponent, TranslatePipe],
  standalone: true,
  templateUrl: './register.component.html',
  styleUrl: './register.component.css'
})
export class RegisterComponent implements OnInit{

  constructor(
    private crudService: CrudService,
    private router: Router,
    private titleService: Title, 
    private metaService: Meta,
    private cookieService: CookieService,
    private toast: HotToastService
  ) {}
  fieldTextType: boolean = false;
  dialCodes: DialCode[] = DIAL_CODES;
  codeSearch = '';
  get selectedDial(): DialCode | undefined {
    return this.dialCodes.find(d => d.code === this.register.countryCode);
  }

  filteredDialCodes(): DialCode[] {
    const q = this.codeSearch.trim().toLowerCase();
    if (!q) return this.dialCodes;
    return this.dialCodes.filter(d =>
      d.name.toLowerCase().includes(q) || d.code.includes(q)
    );
  }

  selectCode(d: DialCode) {
    this.register.countryCode = d.code;
    this.codeSearch = '';
  }

  // Optional: full E.164 if you need it
  get fullPhone(): string {
    return `${this.register.countryCode}${(this.register.phone || '').replace(/\D/g, '')}`;
  }
  ui_controls = {
    loading: false,
    registered: false
  };
  ngOnInit(): void {
    this.titleService.setTitle('Create Account | Central LMS Africa Registration');
    this.metaService.updateTag({ name: 'title', content: 'Sign Up LMS Africa | Register for Central Learning Management System' });
    this.metaService.updateTag({ name: 'description', content: 'Register for Central LMS to access corporate training, online courses, and employee development tools across Africa. Quick and secure sign-up process.' });
    this.metaService.updateTag({ name: 'keywords', content: 'register LMS Africa, sign up eLearning Nigeria, create LMS account Africa, online training registration Africa' });

  }
  register = {
    first_name: "",
    last_name: "",
    email: "",
    phone: "",
    password: "",
    confirm_password: "",
    countryCode: "+234"
  };
  confirm = {
    otp: "",
    input_otp: "",
    expires_at: 0,
    email: ""
  };
  send_otp_check = {
    first_name: "",
    email: ""
  };
  r_response = {
    otp: "",
    expires_at: 0
  };
  user_register() {
    this.register.phone = this.register.countryCode + this.register.phone;
    this.ui_controls.loading = true;
    this.crudService.post_request(this.register, GlobalComponent.UserRegister)
      .subscribe(({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.ui_controls.loading = false;
            this.success_notification(response.message);
            this.router.navigate(['/login']).then(r => console.log(r));
          }
        },
        error: (e) => {
          console.error(e);
          this.error_notification(e);
          this.ui_controls.loading = false;
        },
        complete: () => {
          console.info('complete');
        }
      }))
  }
  user_validate() {
    if (this.confirm.input_otp.length === 0) {
      this.error_notification("OTP is required");
      return;
    }
    this.ui_controls.loading = true;
    this.crudService.post_request(this.confirm, GlobalComponent.UserValidate)
      .subscribe(({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.user_register();
          }
        },
        error: (e) => {
          console.error(e);
          this.error_notification(e);
          this.ui_controls.loading = false;
        },
        complete: () => {
          console.info('complete');
        }
      }))
  }
  send_otp() {
    if (this.register.first_name.length === 0) {
      this.error_notification("First name is required");
      return;
    }
    if (this.register.last_name.length === 0) {
      this.error_notification("Last name is required");
      return;
    }
    if (this.register.email.length === 0) {
      this.error_notification("Email address is required");
      return;
    }
    if (!GlobalComponent.validateEmail(this.register.email)) {
      this.error_notification("Invalid email format provided");
      return;
    }
    if (this.register.phone.length === 0) {
      this.error_notification("Phone number is required");
      return;
    }
    if (this.register.countryCode.length === 0) {
      this.error_notification("Country code is required");
      return;
    }
    if (!GlobalComponent.validateNumber(this.register.phone)) {
      this.error_notification("Invalid phone number format provided");
      return;
    }
    if (this.register.password.length === 0) {
      this.error_notification("Password is required");
      return;
    }
    if (this.register.confirm_password.length === 0) {
      this.error_notification("Password does not match");
      return;
    }
    if (this.register.password != this.register.confirm_password) {
      this.error_notification("Password does not match");
      return;
    }
    this.send_otp_check.email = this.register.email;
    this.send_otp_check.first_name = this.register.first_name;
    this.ui_controls.loading = true;
    this.crudService.post_request(this.send_otp_check, GlobalComponent.EmailValidate)
      .subscribe(({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.ui_controls.loading = false;
            this.ui_controls.registered = true;
            this.success_notification(response.message);
            this.r_response = response.data;
            this.confirm.otp = this.r_response.otp;
            this.confirm.expires_at = this.r_response.expires_at;
            this.confirm.email = this.register.email;
          }
          if (response.response_code == 200 && response.status === "failed") {
            this.ui_controls.loading = false;
            this.error_notification(response.message);
          }
          if (response.response_code == 400 && response.status === "failed") {
            this.ui_controls.loading = false;
            this.error_notification(response.message);
          }
        },
        error: (e) => {
          console.error(e);
          this.error_notification(e);
          this.ui_controls.loading = false;
        },
        complete: () => {
          console.info('complete');
        }
      }))
  }

  error_notification(message: string) {
    this.toast.error(message);
  }
  success_notification(message: string) {
    this.toast.success(message);
  }
}

