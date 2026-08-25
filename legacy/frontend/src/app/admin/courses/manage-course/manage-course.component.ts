import {Component, inject, OnInit, signal} from '@angular/core';
import {AdmintopComponent} from '../../../partials/top/admintop/admintop.component';
import {TuiIcon} from '@taiga-ui/core';
import {GlobalComponent} from '../../../global-component';
import {ActivatedRoute, Router} from '@angular/router';
import {CrudService} from '../../../services/crud.service';
import {HotToastService} from '@ngneat/hot-toast';
import {CourseContentComponent} from '../course-content/course-content.component';
@Component({
  selector: 'app-manage-users',
  standalone: true,
  imports: [
    AdmintopComponent,
    TuiIcon,
    CourseContentComponent
  ],
  templateUrl: './manage-course.component.html',
  styleUrl: './manage-course.component.css'
})
export class ManageCourseComponent {
  session_data: any = ""
  course_id: any = ""
  courseTitle: any = ""
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
  constructor(
    private router: Router,
    private route: ActivatedRoute,
    private crudService: CrudService,
    private toast: HotToastService,
  ) {}
  async ngOnInit() {
    this.session_data = sessionStorage.getItem("SESSION");
    this.user_session = GlobalComponent.decodeBase64(this.session_data);
    this.course_id = Number(this.route.snapshot.queryParamMap.get('id'));
    this.courseTitle = <string>this.route.snapshot.queryParamMap.get('title');
  }
}
