import {Component, OnInit} from '@angular/core';
import {Router} from '@angular/router';
import {CrudService} from '../../services/crud.service';
import {HotToastService} from '@ngneat/hot-toast';
import {CommonModule, NgIf} from '@angular/common';
import {TopComponent} from '../../partials/top/top.component';
import {TuiIcon, TuiLoader} from '@taiga-ui/core';
import {FormsModule} from '@angular/forms';
import {AdmintopComponent} from '../../partials/top/admintop/admintop.component';
import {TenantSettingsComponent} from '../../tenant-settings/tenant-settings.component';
import {MyLearningComponent} from './my-learning/my-learning.component';
import {LearnerDashboardComponent} from "../../admin/learner-dashboard/learner-dashboard.component";
@Component({
  selector: 'app-learning',
  standalone: true,
    imports: [
        NgIf,
        TopComponent,
        TuiIcon,
        CommonModule,
        FormsModule,
        TuiLoader,
        AdmintopComponent,
        TenantSettingsComponent,
        MyLearningComponent,
        LearnerDashboardComponent
    ],
  templateUrl: './learning.component.html',
  styleUrl: './learning.component.css'
})
export class LearningComponent implements OnInit {
  constructor(
    private router: Router,
    private crudService: CrudService,
    private toast: HotToastService,
  ) {}
  ui_controls = {
    is_loading: false
  };
  session_data: any = ""
  user_session = {
    id: 0,
    token: "",
    first_name: "",
    last_name: "",
    email: "",
    phone: "",
    avatar: "",
    id_front: "",
    id_back: "",
    is_2fa: false,
    is_active: false
  };
  ngOnInit(): void {
    this.session_data = sessionStorage.getItem("SESSION");
    this.user_session = JSON.parse(atob(this.session_data));
  }
  goBack() {
    if (this.user_session.is_active){
      this.router.navigate(['/account']).then(r => console.log(r));
    }else{
      this.router.navigate(['/login']).then(r => console.log(r));

    }
  }
  error_notification(message: string) {
    this.toast.error(message);
  }
  success_notification(message: string) {
    this.toast.success(message);
  }

}
