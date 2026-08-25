import {Component, OnInit} from '@angular/core';
import {Router} from '@angular/router';
import {CrudService} from '../../services/crud.service';
import {HotToastService} from '@ngneat/hot-toast';
import {CommonModule} from '@angular/common';
import {FormsModule} from '@angular/forms';
import {TuiIcon} from '@taiga-ui/core';
import {TopComponent} from '../../partials/top/top.component';
import {SideComponent} from '../../partials/side/side.component';
import {AdmintopComponent} from '../../partials/top/admintop/admintop.component';
import {TenantSettingsComponent} from '../../tenant-settings/tenant-settings.component';
@Component({
  selector: 'app-branding',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon, TopComponent, SideComponent, AdmintopComponent, TenantSettingsComponent],
  templateUrl: './settings.component.html',
  styleUrl: './settings.component.css'
})
export class SettingsComponent implements OnInit {
  constructor(
    private router: Router,
    private crudService: CrudService,
    private toast: HotToastService,
  ) {
  }
  ui_controls = {
    is_loading: false,
    no_recent: false,
    viewing_dashboard: true,
    viewing_recent_orders: false,
    viewing_menu: false
  };
  session_data: any = ""
  user_session = {
    id: 0,
    token: "",
    first_name: "",
    last_name: "",
    email: "",
    phone: "",
    is_2fa: false,
    is_active: false
  };
  ngOnInit(): void {
    this.session_data = sessionStorage.getItem("SESSION");
    this.user_session = JSON.parse(atob(this.session_data));
  }
  error_notification(message: string) {
    this.toast.error(message);
  }
  success_notification(message: string) {
    this.toast.success(message);
  }
  goBack() {
    this.router.navigate(['/administration']).then(r => console.log(r));
  }

}
