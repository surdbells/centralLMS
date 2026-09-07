import {Component} from '@angular/core';
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
import {TranslatePipe} from '../../translate.pipe';
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
        LearnerDashboardComponent,
        TranslatePipe
    ],
  templateUrl: './learning.component.html',
  styleUrl: './learning.component.css'
})
export class LearningComponent {
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
