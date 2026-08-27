import { Component } from '@angular/core';
import {LearnerSkillsComponent} from '../../learner/learner-skills-page/skills/learner-skills.component';
import {TopComponent} from '../../partials/top/top.component';
import {TuiIcon} from '@taiga-ui/core';
import {AdminSkillsComponent} from './skills/admin-skills.component';
import {Router} from '@angular/router';
import {CrudService} from '../../services/crud.service';
import {HotToastService} from '@ngneat/hot-toast';
import {AdmintopComponent} from '../../partials/top/admintop/admintop.component';

@Component({
  selector: 'app-admin-skills-page',
  standalone: true,
  imports: [
    LearnerSkillsComponent,
    TopComponent,
    TuiIcon,
    AdminSkillsComponent,
    AdmintopComponent,
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
