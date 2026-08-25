import { decodeSessionBlob } from '../../core/utils/session-blob';
import { Component } from '@angular/core';
import {LearnerReportComponent} from "../my-report/learner-report/learner-report.component";
import {TopComponent} from "../../partials/top/top.component";
import {TuiIcon} from "@taiga-ui/core";
import {Router} from '@angular/router';
import {CrudService} from '../../services/crud.service';
import {HotToastService} from '@ngneat/hot-toast';
import {LearnerSkillsComponent} from './skills/learner-skills.component';

@Component({
  selector: 'app-learner-skills-page',
  standalone: true,
  imports: [
    LearnerReportComponent,
    TopComponent,
    TuiIcon,
    LearnerSkillsComponent
  ],
  templateUrl: './learner-skills-page.component.html',
  styleUrl: './learner-skills-page.component.css'
})
export class LearnerSkillsPageComponent {
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
    this.user_session = decodeSessionBlob(this.session_data) ?? this.user_session;
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
