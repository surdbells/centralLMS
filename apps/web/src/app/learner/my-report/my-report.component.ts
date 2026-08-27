import { Component } from '@angular/core';
import {MyLearningComponent} from "../learning/my-learning/my-learning.component";
import {TopComponent} from "../../partials/top/top.component";
import {TuiIcon} from "@taiga-ui/core";
import {LearnerReportComponent} from './learner-report/learner-report.component';
import {Router} from '@angular/router';
import {CrudService} from '../../services/crud.service';
import {HotToastService} from '@ngneat/hot-toast';

@Component({
  selector: 'app-my-report',
  standalone: true,
  imports: [
    MyLearningComponent,
    TopComponent,
    TuiIcon,
    LearnerReportComponent
  ],
  templateUrl: './my-report.component.html',
  styleUrl: './my-report.component.css'
})
export class MyReportComponent {
  constructor(
    private router: Router,
    private crudService: CrudService,
    private toast: HotToastService,
  ) {}
  ui_controls = {
    is_loading: false
  };
  /**
   * Where "back" goes.
   *
   * It used to depend on `is_active`, decoded out of the legacy session
   * blob — so an expired or malformed blob quietly sent somebody to the
   * sign-in page from a screen they were entitled to be on. Whether there
   * is a session at all is the router guard's business; this is a link.
   */
  goBack(): void {
    void this.router.navigate(['/account']);
  }
  error_notification(message: string) {
    this.toast.error(message);
  }
  success_notification(message: string) {
    this.toast.success(message);
  }

}
