import {Component, OnInit} from '@angular/core';
import {AsideComponent} from "../../partials/aside/aside.component";
import {FormsModule} from "@angular/forms";
import {NgIf} from "@angular/common";
import {SideComponent} from "../../partials/side/side.component";
import {TopComponent} from "../../partials/top/top.component";
import {TuiIcon, TuiLoader} from "@taiga-ui/core";
import {Router} from '@angular/router';
import {CrudService} from '../../services/crud.service';
import {HotToastService} from '@ngneat/hot-toast';
import {GlobalComponent} from '../../global-component';

@Component({
  selector: 'app-certicates',
  standalone: true,
    imports: [
        AsideComponent,
        FormsModule,
        NgIf,
        SideComponent,
        TopComponent,
        TuiIcon,
        TuiLoader
    ],
  templateUrl: './certicates.component.html',
  styleUrl: './certicates.component.css'
})
export class CerticatesComponent implements OnInit {
  constructor(
    private router: Router,
    private crudService: CrudService,
    private toast: HotToastService,
  ) {}
  ui_controls = {
    is_loading: false
  };
  user_single = {
    first_name: "",
    last_name: "",
    email: "",
    phone: "",
    avatar: ""
  }
  get_single = {
    id: 0,
    token: ""
  }
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
    if (!this.user_session.is_active){
      this.router.navigate(['/', '']).then(r => console.log(r)); return;
    }
    this.get_single.id = this.user_session.id;
    this.get_single.token = this.user_session.token;
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

  to_dashboard() {
    if (this.user_session.is_active){
      this.router.navigate(['/account']).then(r => console.log(r));
    }
  }
}
