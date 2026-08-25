import { Component } from '@angular/core';
import {LearnerSkillsComponent} from '../../learner/learner-skills-page/skills/learner-skills.component';
import {TopComponent} from '../../partials/top/top.component';
import {TuiIcon} from '@taiga-ui/core';
import {AdminSkillsComponent} from './skills/admin-skills.component';
import {Router} from '@angular/router';
import {CrudService} from '../../services/crud.service';
import {HotToastService} from '@ngneat/hot-toast';
import {AdmintopComponent} from '../../partials/top/admintop/admintop.component';
import {CourseEnrollmentComponent} from '../courses/course-enrollment/course-enrollment.component';

@Component({
  selector: 'app-admin-skills-page',
  standalone: true,
  imports: [
    LearnerSkillsComponent,
    TopComponent,
    TuiIcon,
    AdminSkillsComponent,
    AdmintopComponent,
    CourseEnrollmentComponent
  ],
  templateUrl: './admin-skills-page.component.html',
  styleUrl: './admin-skills-page.component.css'
})
export class AdminSkillsPageComponent {
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
