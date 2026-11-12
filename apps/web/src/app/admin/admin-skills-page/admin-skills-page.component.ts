import { Component } from '@angular/core';
import {LearnerSkillsComponent} from '../../learner/learner-skills-page/skills/learner-skills.component';
import {TopComponent} from '../../partials/top/top.component';
import {TuiIcon} from '@taiga-ui/core';
import {AdminSkillsComponent} from './skills/admin-skills.component';
import {Router} from '@angular/router';
import {Location} from '@angular/common';
import {CrudService} from '../../services/crud.service';
import {HotToastService} from '@ngneat/hot-toast';
import {AdmintopComponent} from '../../partials/top/admintop/admintop.component';
import {TranslatePipe} from '../../translate.pipe';

@Component({
  selector: 'app-admin-skills-page',
  standalone: true,
  imports: [
    LearnerSkillsComponent,
    TopComponent,
    TuiIcon,
    AdminSkillsComponent,
    AdmintopComponent,
    TranslatePipe,
  ],
  templateUrl: './admin-skills-page.component.html',
  styleUrl: './admin-skills-page.component.css'
})
export class AdminSkillsPageComponent {
  constructor(
    private router: Router,
    private crudService: CrudService,
    private toast: HotToastService,
    private location: Location,
  ) {}
  ui_controls = {
    is_loading: false
  };
  /**
   * Where "back" goes: the previous page, or the admin dashboard when there
   * is no history (fresh tab / deep link). It used to send admins to the
   * learner dashboard (/account).
   */
  goBack(): void {
    if (window.history.length > 1) {
      this.location.back();
      return;
    }
    void this.router.navigate(['/administration']);
  }
  error_notification(message: string) {
    this.toast.error(message);
  }
  success_notification(message: string) {
    this.toast.success(message);
  }

}
