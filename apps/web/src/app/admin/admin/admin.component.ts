import {Component, ViewChild} from '@angular/core';
import {TopComponent} from '../../partials/top/top.component';
import {Router, RouterLink, RouterLinkActive} from '@angular/router';
import {CrudService} from '../../services/crud.service';
import {HotToastService} from '@ngneat/hot-toast';
import {CommonModule} from '@angular/common';
import {TuiIcon, TuiLoader} from '@taiga-ui/core';
import {DataTablesModule} from 'angular-datatables';
import {TranslatePipe} from '../../translate.pipe';
import {AsideComponent} from '../../partials/aside/aside.component';
import {SideComponent} from '../../partials/side/side.component';
import {AdmintopComponent} from '../../partials/top/admintop/admintop.component';
import {AdminDashboardComponent} from '../admin-dashboard/admin-dashboard.component';
import {I18nService} from '../../i18n.service';


interface StatCard {
  title: string;
  subtitle?: string;
  value: string | number;
  delta?: string;
  icon?: string; // use Material icons names
}


interface TopLearner { name: string; role: string; points: number; avatarUrl?: string }
@Component({
  selector: 'app-admin',
  imports: [AsideComponent, CommonModule, TopComponent, TuiLoader, DataTablesModule, RouterLink, TranslatePipe, RouterLinkActive, SideComponent, TuiIcon, AdmintopComponent, AdminDashboardComponent],
  standalone: true,
  templateUrl: './admin.component.html',
  styleUrl: './admin.component.css'
})
export class AdminComponent {
  constructor(
    private router: Router,
    private crudService: CrudService,
    private toast: HotToastService,
    private i18n: I18nService,
  ) {
  }
  ui_controls = {
    is_loading: false,
    no_recent: false,
    viewing_dashboard: true,
    viewing_recent_orders: false,
    viewing_menu: false
  };
  error_notification(message: string) {
    this.toast.error(message);
  }
  success_notification(message: string) {
    this.toast.success(message);
  }
  sign_out(): void {
    this.success_notification(this.i18n.t('auth.logged_out'));
    this.router.navigate(['/']).then(r => console.log(r));
  }
  goBack() {
    this.router.navigate(['/administration']).then(r => console.log(r));
  }
}
