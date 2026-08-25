import {Component, inject, OnInit} from '@angular/core';
import {CommonModule, DatePipe} from "@angular/common";
import {FormsModule} from "@angular/forms";
import {SideComponent} from "../../partials/side/side.component";
import {TopComponent} from "../../partials/top/top.component";
import {TuiIcon, TuiLoader} from "@taiga-ui/core";
import {Router} from '@angular/router';
import {CrudService} from '../../services/crud.service';
import {HotToastService} from '@ngneat/hot-toast';
import {GlobalComponent} from '../../global-component';
import {TUI_CONFIRM} from '@taiga-ui/kit';
import {TuiResponsiveDialogService} from '@taiga-ui/addon-mobile';
import {AdmintopComponent} from '../../partials/top/admintop/admintop.component';
interface Branches {
  branch_id: number;
  branch_code: string;
  branch_name: string;
  address: string;
  city: string;
  state: string;
  country: string;
  status: boolean;
  created_at: string;
  updated_at: string;
}
interface Departments {
  department_id: number;
  department_code: string;
  department_name: string;
  description: string;
  status: boolean;
  created_at: string;
  updated_at: string;
}
interface Teams {
  team_id: number;
  team_code: string;
  team_name: string;
  description: string;
  status: boolean;
  created_at: string;
  updated_at: string;
}
interface Levels {
  level_id: number;
  level_code: string;
  level_name: string;
  description: string;
  status: boolean;
  created_at: string;
  updated_at: string;
}
interface JobTitle {
  job_title_id: number;
  title_name: string;
  description: string;
  status: boolean;
  created_at: string;
  updated_at: string;
}
interface Category {
  category_id: number;
  name: string;
  description: string;
  status: boolean;
  created_at: string;
  updated_at: string;
}
@Component({
  selector: 'app-dictionary',
  standalone: true,
  imports: [DatePipe, FormsModule, CommonModule, FormsModule, SideComponent, TopComponent, TuiIcon, TuiLoader, AdmintopComponent],
  templateUrl: './dictionary.component.html',
  styleUrl: './dictionary.component.css'
})
export class DictionaryComponent implements OnInit{
  branches: Branches[] = [];
  departments: Departments[] = [];
  teams: Teams[] = [];
  category: Category[] = [];
  levels: Levels[] = [];
  job_title: JobTitle[] = [];
  private readonly dialogs = inject(TuiResponsiveDialogService);
  countries: string[] = [
    "Nigeria",
    "Ghana",
    "South Africa",
    "Kenya",
    "United States",
    "United Kingdom",
    "Canada",
    "Germany",
    "United Arab Emirates",
    "India"
  ];

  constructor(
    private router: Router,
    private crudService: CrudService,
    private toast: HotToastService,
  ) {
  }
  ui_controls = {
    is_loading: false
  };
  branch_controls = {
    editing: false,
    viewing: false,
    creating: false
  };
  department_controls = {
    editing: false,
    viewing: false,
    creating: false
  };
  jobTitle_controls = {
    editing: false,
    viewing: false,
    creating: false
  };
  team_controls = {
    editing: false,
    viewing: false,
    creating: false
  };
  level_controls = {
    editing: false,
    viewing: false,
    creating: false
  };
  category_controls = {
    editing: false,
    viewing: false,
    creating: false
  };
  meta = {
    total: 0,
    page: 0,
    per_page: 0,
    total_pages: 0
  };

  singleBranch  = {
    id: 0,
    tenant_id: 0,
    token: "",
    branch_id: 0,
    branch_name: "",
    branch_code: "",
    address: "",
    city:  "",
    state:  "",
    country:  "",
    status:  false
  };
  createBranch = {
    id: 0,
    tenant_id: 0,
    token: "",
    branch_name: "",
    branch_code: "",
    address: "",
    city:  "",
    state:  "",
    country:  ""
  };
  switchBranch = {
    id: 0,
    tenant_id: 0,
    branch_id: 0,
    status: false,
    token: "",
  };

  singleDepartment = {
    id: 0,
    tenant_id: 0,
    token: "",
    department_id: 0,
    department_code: "",
    department_name: "",
    description: "",
    status:  false
  };
  createDepartment = {
    id: 0,
    tenant_id: 0,
    token: "",
    department_name: "",
    description: ""
  };
  switchDepartment = {
    id: 0,
    tenant_id: 0,
    department_id: 0,
    status: false,
    token: "",
  };

  singleTeam = {
    id: 0,
    tenant_id: 0,
    token: "",
    team_id: 0,
    team_code: "",
    team_name: "",
    description: "",
    status:  false
  };
  createTeam = {
    id: 0,
    tenant_id: 0,
    token: "",
    team_name: "",
    description: ""
  };
  switchTeam = {
    id: 0,
    tenant_id: 0,
    token: "",
    status: false,
    team_id: 0
  };

  singleLevel = {
    id: 0,
    tenant_id: 0,
    token: "",
    level_id: 0,
    level_code: "",
    level_name: "",
    description: "",
    status:  false
  };
  createLevel = {
    id: 0,
    tenant_id: 0,
    token: "",
    level_name: "",
    description: ""
  };
  switchLevel = {
    id: 0,
    tenant_id: 0,
    level_id: 0,
    status: false,
    token: "",
  };

  singleJobTitle = {
    id: 0,
    tenant_id: 0,
    token: "",
    job_title_id: 0,
    title_name: "",
    description: "",
    status:  false
  };
  createJobTitle = {
    id: 0,
    tenant_id: 0,
    token: "",
    title_name: "",
    description: ""
  };
  switchJobTitle = {
    id: 0,
    tenant_id: 0,
    job_title_id: 0,
    status: false,
    token: "",
  };

  singleCategory = {
    id: 0,
    tenant_id: 0,
    category_id: 0,
    token: "",
    name: "",
    description: "",
    parent_id: 0,
    status:  false
  };
  createCategory = {
    id: 0,
    tenant_id: 0,
    token: "",
    name: "",
    description: "",
    parent_id: 0
  };
  switchCategory = {
    id: 0,
    tenant_id: 0,
    category_id: 0,
    status: false,
    token: "",
  };

  session_data: any = ""
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
  async ngOnInit(): Promise<void> {
    this.session_data = sessionStorage.getItem("SESSION");
    this.user_session = JSON.parse(atob(this.session_data));
    await this.InitInterface();
    await this.listBranch();
  }
  initial = {
    id: 0,
    tenant_id: 0,
    token: "",
    page: 1,
    per_page: 10
  }
  InitInterface(){
    // GET ALL RECORDS
    this.initial.id = this.user_session.id;
    this.initial.tenant_id = this.user_session.tenant_id;
    this.initial.token = this.user_session.token;

    // BRANCH
    this.singleBranch.id = this.user_session.id;
    this.singleBranch.tenant_id = this.user_session.tenant_id;
    this.singleBranch.token = this.user_session.token;

    this.createBranch.id = this.user_session.id;
    this.createBranch.tenant_id = this.user_session.tenant_id;
    this.createBranch.token = this.user_session.token;

    this.switchBranch.id = this.user_session.id;
    this.switchBranch.tenant_id = this.user_session.tenant_id;
    this.switchBranch.token = this.user_session.token;

    // DEPARTMENT
    this.singleDepartment.id = this.user_session.id;
    this.singleDepartment.tenant_id = this.user_session.tenant_id;
    this.singleDepartment.token = this.user_session.token;

    this.createDepartment.id = this.user_session.id;
    this.createDepartment.tenant_id = this.user_session.tenant_id;
    this.createDepartment.token = this.user_session.token;

    this.switchDepartment.id = this.user_session.id;
    this.switchDepartment.tenant_id = this.user_session.tenant_id;
    this.switchDepartment.token = this.user_session.token;

    // TEAM
    this.singleTeam.id = this.user_session.id;
    this.singleTeam.tenant_id = this.user_session.tenant_id;
    this.singleTeam.token = this.user_session.token;

    this.createTeam.id = this.user_session.id;
    this.createTeam.tenant_id = this.user_session.tenant_id;
    this.createTeam.token = this.user_session.token;

    this.switchTeam.id = this.user_session.id;
    this.switchTeam.tenant_id = this.user_session.tenant_id;
    this.switchTeam.token = this.user_session.token;

    // LEVEL
    this.singleLevel.id = this.user_session.id;
    this.singleLevel.tenant_id = this.user_session.tenant_id;
    this.singleLevel.token = this.user_session.token;

    this.createLevel.id = this.user_session.id;
    this.createLevel.tenant_id = this.user_session.tenant_id;
    this.createLevel.token = this.user_session.token;

    this.switchLevel.id = this.user_session.id;
    this.switchLevel.tenant_id = this.user_session.tenant_id;
    this.switchLevel.token = this.user_session.token;

    // LEVEL
    this.singleJobTitle.id = this.user_session.id;
    this.singleJobTitle.tenant_id = this.user_session.tenant_id;
    this.singleJobTitle.token = this.user_session.token;

    this.createJobTitle.id = this.user_session.id;
    this.createJobTitle.tenant_id = this.user_session.tenant_id;
    this.createJobTitle.token = this.user_session.token;

    this.switchJobTitle.id = this.user_session.id;
    this.switchJobTitle.tenant_id = this.user_session.tenant_id;
    this.switchJobTitle.token = this.user_session.token;

    // COURSE CATEGORY
    this.singleCategory.id = this.user_session.id;
    this.singleCategory.tenant_id = this.user_session.tenant_id;
    this.singleCategory.token = this.user_session.token;

    this.createCategory.id = this.user_session.id;
    this.createCategory.tenant_id = this.user_session.tenant_id;
    this.createCategory.token = this.user_session.token;

    this.switchCategory.id = this.user_session.id;
    this.switchCategory.tenant_id = this.user_session.tenant_id;
    this.switchCategory.token = this.user_session.token;

  }
  success_notification(message: string) {
    this.toast.success(message);
  }
  error_notification(message: string) {
    this.toast.error(message);
  }

  listBranch(page: number =  1, per_page: number =  10) {
    this.initial.page = page;
    this.initial.per_page = per_page;
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.initial, GlobalComponent.listBranch)
      .subscribe(({ next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.branches = response.data.branch;
            this.meta = response.data.meta;
            this.ui_controls.is_loading = false;
          }
        }
      }))
  }
  getSingleBranch(branch: Branches) {
    this.singleBranch.branch_id = branch.branch_id
    this.singleBranch.branch_name = branch.branch_name
    this.singleBranch.branch_code = branch.branch_code
    this.singleBranch.address = branch.address
    this.singleBranch.city = branch.city
    this.singleBranch.country = branch.country
    this.singleBranch.state = branch.state
    this.singleBranch.status = branch.status
  }
  createABranch() {
    console.log(this.createBranch);
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.createBranch, GlobalComponent.createBranch)
      .subscribe(({ next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.ui_controls.is_loading = false;
            this.success_notification(response.message);
            this.listBranch();
            this.switchViewBranch();
          }
        }
      }))
  }
  updateABranch() {
    console.log(this.singleBranch);
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.singleBranch, GlobalComponent.updateBranch)
      .subscribe(({ next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.ui_controls.is_loading = false;
            this.success_notification(response.message);
            this.listBranch();
            this.switchViewBranch();
          }
        }
      }))
  }
  switchABranch(branch: number, status: boolean) {
    console.log(this.switchBranch);
    this.switchBranch.branch_id = branch;
    this.switchBranch.status = status;
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.switchBranch, GlobalComponent.switchBranch)
      .subscribe(({ next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.ui_controls.is_loading = false;
            this.success_notification(response.message);
            this.listBranch();
            this.switchViewBranch();
          }
        }
      }))
  }
  startDeactivateABranch(branch: number, name: string, status: boolean) {
    this.dialogs
      .open<boolean>(TUI_CONFIRM, {
        label: 'Confirm',
        data: {
          content: 'Your branch '+ name +' will be deactivated',
          yes: 'Deactivate',
          no: 'Cancel',
        },
      })
      .subscribe((response) => {
        if (response){
          this.switchABranch(branch,status);
        }
      });
  }
  startReactivateABranch(branch: number, name: string, status: boolean) {
    this.dialogs
      .open<boolean>(TUI_CONFIRM, {
        label: 'Confirm',
        data: {
          content: 'Your branch '+ name +' will be reactivated',
          yes: 'Activate',
          no: 'Cancel',
        },
      })
      .subscribe((response) => {
        if (response){
          this.switchABranch(branch,status);
        }
      });
  }

  listDepartment(page: number =  1, per_page: number =  10) {
    this.initial.page = page;
    this.initial.per_page = per_page;
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.initial, GlobalComponent.listDepartment)
      .subscribe(({ next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.departments = response.data.department;
            this.meta = response.data.meta;
            this.ui_controls.is_loading = false;
          }
        }
      }))
  }
  getSingleDepartment(department: Departments) {
    this.singleDepartment.department_id = department.department_id
    this.singleDepartment.department_name = department.department_name
    this.singleDepartment.description = department.description
    this.singleDepartment.status = department.status
  }
  createADepartment() {
    console.log(this.createDepartment);
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.createDepartment, GlobalComponent.createDepartment)
      .subscribe(({ next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.ui_controls.is_loading = false;
            this.success_notification(response.message);
            this.listDepartment();
            this.switchViewDepartment();
          }
        }
      }))
  }
  updateADepartment() {
    console.log(this.singleDepartment);
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.singleDepartment, GlobalComponent.updateDepartment)
      .subscribe(({ next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.ui_controls.is_loading = false;
            this.success_notification(response.message);
            this.listDepartment();
            this.switchViewDepartment();
          }
        }
      }))
  }
  switchADepartment(department: number, status: boolean) {
    console.log(this.switchDepartment);
    this.switchDepartment.department_id = department;
    this.switchDepartment.status = status;
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.switchDepartment, GlobalComponent.switchDepartment)
      .subscribe(({ next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.ui_controls.is_loading = false;
            this.success_notification(response.message);
            this.listDepartment();
            this.switchViewDepartment();
          }
        }
      }))
  }
  startDeactivateADepartment(department: number, name: string, status: boolean) {
    this.dialogs
      .open<boolean>(TUI_CONFIRM, {
        label: 'Confirm',
        data: {
          content: 'Your department '+ name +' will be deactivated',
          yes: 'Deactivate',
          no: 'Cancel',
        },
      })
      .subscribe((response) => {
        if (response){
          this.switchADepartment(department,status);
        }
      });
  }
  startReactivateADepartment(department: number, name: string, status: boolean) {
    this.dialogs
      .open<boolean>(TUI_CONFIRM, {
        label: 'Confirm',
        data: {
          content: 'Your department '+ name +' will be reactivated',
          yes: 'Activate',
          no: 'Cancel',
        },
      })
      .subscribe((response) => {
        if (response){
          this.switchADepartment(department,status);
        }
      });
  }


  listJobTitle(page: number =  1, per_page: number =  10) {
    this.initial.page = page;
    this.initial.per_page = per_page;
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.initial, GlobalComponent.listJobTitle)
      .subscribe(({ next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.job_title = response.data.job_title;
            this.meta = response.data.meta;
            this.ui_controls.is_loading = false;
          }
        }
      }))
  }
  getSingleJobTitle(jobTitle: JobTitle) {
    this.singleJobTitle.job_title_id = jobTitle.job_title_id
    this.singleJobTitle.title_name = jobTitle.title_name
    this.singleJobTitle.description = jobTitle.description
    this.singleJobTitle.status = jobTitle.status
  }
  createAJobTitle() {
    console.log(this.createJobTitle);
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.createJobTitle, GlobalComponent.createJobTitle)
      .subscribe(({ next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.ui_controls.is_loading = false;
            this.success_notification(response.message);
            this.listJobTitle();
            this.switchViewJobTitle();
          }
        }
      }))
  }
  updateAJobTitle() {
    console.log(this.singleJobTitle);
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.singleJobTitle, GlobalComponent.UpdateJobTitle)
      .subscribe(({ next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.ui_controls.is_loading = false;
            this.success_notification(response.message);
            this.listJobTitle();
            this.switchViewJobTitle();
          }
        }
      }))
  }
  switchAJobTitle(jobTitle: number, status: boolean) {
    console.log(this.switchJobTitle);
    this.switchJobTitle.job_title_id = jobTitle;
    this.switchJobTitle.status = status;
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.switchJobTitle, GlobalComponent.switchJobTitle)
      .subscribe(({ next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.ui_controls.is_loading = false;
            this.success_notification(response.message);
            this.listJobTitle();
            this.switchViewJobTitle();
          }
        }
      }))
  }
  startDeactivateAJobTitle(jobTitle: number, name: string, status: boolean) {
    this.dialogs
      .open<boolean>(TUI_CONFIRM, {
        label: 'Confirm',
        data: {
          content: 'Your Job Title '+ name +' will be deactivated',
          yes: 'Deactivate',
          no: 'Cancel',
        },
      })
      .subscribe((response) => {
        if (response){
          this.switchAJobTitle(jobTitle,status);
        }
      });
  }
  startReactivateAJobTitle(jobTitle: number, name: string, status: boolean) {
    this.dialogs
      .open<boolean>(TUI_CONFIRM, {
        label: 'Confirm',
        data: {
          content: 'Your Job Title '+ name +' will be reactivated',
          yes: 'Activate',
          no: 'Cancel',
        },
      })
      .subscribe((response) => {
        if (response){
          this.switchAJobTitle(jobTitle,status);
        }
      });
  }


  listLevels(page: number =  1, per_page: number =  10) {
    this.initial.page = page;
    this.initial.per_page = per_page;
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.initial, GlobalComponent.listLevels)
      .subscribe(({ next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.levels = response.data.levels;
            this.meta = response.data.meta;

            this.ui_controls.is_loading = false;
          }
        }
      }))
  }
  getSingleLevel(level: Levels) {
    this.singleLevel.level_id = level.level_id
    this.singleLevel.level_name = level.level_name
    this.singleLevel.description = level.description
    this.singleLevel.status = level.status
  }
  createALevel() {
    console.log(this.createLevel);
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.createLevel, GlobalComponent.createLevels)
      .subscribe(({ next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.ui_controls.is_loading = false;
            this.success_notification(response.message);
            this.listLevels();
            this.switchViewLevels();
          }
        }
      }))
  }
  updateALevel() {
    console.log(this.singleLevel);
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.singleLevel, GlobalComponent.UpdateLevels)
      .subscribe(({ next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.ui_controls.is_loading = false;
            this.success_notification(response.message);
            this.listLevels();
            this.switchViewLevels();
          }
        }
      }))
  }
  switchALevel(level: number, status: boolean) {
    console.log(this.switchLevel);
    this.switchLevel.level_id = level;
    this.switchLevel.status = status;
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.switchLevel, GlobalComponent.switchLevels)
      .subscribe(({ next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.ui_controls.is_loading = false;
            this.success_notification(response.message);
            this.listLevels();
            this.switchViewLevels();
          }
        }
      }))
  }
  startDeactivateALevel(level: number, name: string, status: boolean) {
    this.dialogs
      .open<boolean>(TUI_CONFIRM, {
        label: 'Confirm',
        data: {
          content: 'Your level '+ name +' will be deactivated',
          yes: 'Deactivate',
          no: 'Cancel',
        },
      })
      .subscribe((response) => {
        if (response){
          this.switchALevel(level,status);
        }
      });
  }
  startReactivateALevel(level: number, name: string, status: boolean) {
    this.dialogs
      .open<boolean>(TUI_CONFIRM, {
        label: 'Confirm',
        data: {
          content: 'Your level '+ name +' will be reactivated',
          yes: 'Activate',
          no: 'Cancel',
        },
      })
      .subscribe((response) => {
        if (response){
          this.switchALevel(level,status);
        }
      });
  }

  listCategory(page: number =  1, per_page: number =  10) {
    this.initial.page = page;
    this.initial.per_page = per_page;
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.initial, GlobalComponent.listCategory)
      .subscribe(({ next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.category = response.data.categories;
            this.meta = response.data.meta;
            this.ui_controls.is_loading = false;
          }
        }
      }))
  }
  getSingleCategory(category: Category) {
    this.singleCategory.id = category.category_id
    this.singleCategory.name = category.name
    this.singleCategory.description = category.description
    this.singleCategory.status = category.status
  }
  createACategory() {
    console.log(this.createCategory);
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.createCategory, GlobalComponent.createCategory)
      .subscribe(({ next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.ui_controls.is_loading = false;
            this.success_notification(response.message);
            this.listCategory();
            this.switchViewCategory();
          }
        }
      }))
  }
  updateACategory() {
    console.log(this.singleCategory);
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.singleCategory, GlobalComponent.UpdateCategory)
      .subscribe(({ next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.ui_controls.is_loading = false;
            this.success_notification(response.message);
            this.listCategory();
            this.switchViewCategory();
          }
        }
      }))
  }
  switchACategory(category: number, status: boolean) {
    console.log(this.switchCategory);
    this.switchCategory.category_id = category;
    this.switchCategory.status = status;
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.switchCategory, GlobalComponent.switchCategory)
      .subscribe(({ next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.ui_controls.is_loading = false;
            this.success_notification(response.message);
            this.listCategory();
            this.switchViewCategory();
          }
        }
      }))
  }
  startDeactivateACategory(category: number, name: string, status: boolean) {
    this.dialogs
      .open<boolean>(TUI_CONFIRM, {
        label: 'Confirm',
        data: {
          content: 'Your category '+ name +' will be deactivated',
          yes: 'Deactivate',
          no: 'Cancel',
        },
      })
      .subscribe((response) => {
        if (response){
          this.switchACategory(category,status);
        }
      });
  }
  startReactivateACategory(category: number, name: string, status: boolean) {
    this.dialogs
      .open<boolean>(TUI_CONFIRM, {
        label: 'Confirm',
        data: {
          content: 'Your category '+ name +' will be reactivated',
          yes: 'Activate',
          no: 'Cancel',
        },
      })
      .subscribe((response) => {
        if (response){
          this.switchACategory(category,status);
        }
      });
  }

  listTeam(page: number =  1, per_page: number =  10) {
    this.initial.page = page;
    this.initial.per_page = per_page;
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.initial, GlobalComponent.listTeam)
      .subscribe(({ next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.teams = response.data.teams;
            this.meta = response.data.meta;
            this.ui_controls.is_loading = false;
          }
        }
      }))
  }
  getSingleTeam(team: Teams) {
    this.singleTeam.team_id = team.team_id
    this.singleTeam.team_name = team.team_name
    this.singleTeam.description = team.description
    this.singleTeam.status = team.status
  }
  createATeam() {
    console.log(this.createTeam);
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.createTeam, GlobalComponent.createTeam)
      .subscribe(({ next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.ui_controls.is_loading = false;
            this.success_notification(response.message);
            this.listTeam();
            this.switchViewTeam();
          }
        }
      }))
  }
  updateATeam() {
    console.log(this.singleTeam);
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.singleTeam, GlobalComponent.UpdateTeam)
      .subscribe(({ next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.ui_controls.is_loading = false;
            this.success_notification(response.message);
            this.listTeam();
            this.switchViewTeam();
          }
        }
      }))
  }
  switchATeam(team: number, status: boolean) {
    console.log(this.switchTeam);
    this.switchTeam.team_id = team;
    this.switchTeam.status = status;
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.switchTeam, GlobalComponent.switchTeam)
      .subscribe(({ next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.ui_controls.is_loading = false;
            this.success_notification(response.message);
            this.listTeam();
            this.switchViewTeam();
          }
        }
      }))
  }
  startDeactivateATeam(team: number, name: string, status: boolean) {
    this.dialogs
      .open<boolean>(TUI_CONFIRM, {
        label: 'Confirm',
        data: {
          content: 'Your team '+ name +' will be deactivated',
          yes: 'Deactivate',
          no: 'Cancel',
        },
      })
      .subscribe((response) => {
        if (response){
          this.switchATeam(team,status);
        }
      });
  }
  startReactivateATeam(team: number, name: string, status: boolean) {
    this.dialogs
      .open<boolean>(TUI_CONFIRM, {
        label: 'Confirm',
        data: {
          content: 'Your team '+ name +' will be reactivated',
          yes: 'Activate',
          no: 'Cancel',
        },
      })
      .subscribe((response) => {
        if (response){
          this.switchATeam(team,status);
        }
      });
  }


  switchCreateBranch(){
    this.branch_controls.editing = false;
    this.branch_controls.viewing = false;
    this.branch_controls.creating = true;
  };
  switchEditBranch(){
    this.branch_controls.creating = false;
    this.branch_controls.viewing = false;
    this.branch_controls.editing = true;
  };
  switchViewBranch(){
    this.branch_controls.creating = false;
    this.branch_controls.editing = false;
    this.branch_controls.viewing = true;
    this.ui_controls.is_loading = false;
  };

  switchCreateDepartment(){
    this.department_controls.editing = false;
    this.department_controls.viewing = false;
    this.department_controls.creating = true;
  };
  switchEditDepartment(){
    this.department_controls.creating = false;
    this.department_controls.viewing = false;
    this.department_controls.editing = true;
  };
  switchViewDepartment(){
    this.department_controls.creating = false;
    this.department_controls.editing = false;
    this.department_controls.viewing = true;
    this.ui_controls.is_loading = false;
  };

  switchCreateJobTitle(){
    this.jobTitle_controls.editing = false;
    this.jobTitle_controls.viewing = false;
    this.jobTitle_controls.creating = true;
  };
  switchEditJobTitle(){
    this.jobTitle_controls.creating = false;
    this.jobTitle_controls.viewing = false;
    this.jobTitle_controls.editing = true;
  };
  switchViewJobTitle(){
    this.jobTitle_controls.creating = false;
    this.jobTitle_controls.editing = false;
    this.jobTitle_controls.viewing = true;
    this.ui_controls.is_loading = false;
  };

  switchCreateLevels(){
    this.level_controls.editing = false;
    this.level_controls.viewing = false;
    this.level_controls.creating = true;
  };
  switchEditLevels(){
    this.level_controls.creating = false;
    this.level_controls.viewing = false;
    this.level_controls.editing = true;
  };
  switchViewLevels(){
    this.level_controls.creating = false;
    this.level_controls.editing = false;
    this.level_controls.viewing = true;
    this.ui_controls.is_loading = false;
  };

  switchCreateTeam(){
    this.team_controls.editing = false;
    this.team_controls.viewing = false;
    this.team_controls.creating = true;
  };
  switchEditTeam(){
    this.team_controls.creating = false;
    this.team_controls.viewing = false;
    this.team_controls.editing = true;
  };
  switchViewTeam(){
    this.team_controls.creating = false;
    this.team_controls.editing = false;
    this.team_controls.viewing = true;
    this.ui_controls.is_loading = false;
  };

  switchCreateCategory(){
    this.category_controls.editing = false;
    this.category_controls.viewing = false;
    this.category_controls.creating = true;
  };
  switchEditCategory(){
    this.category_controls.creating = false;
    this.category_controls.viewing = false;
    this.category_controls.editing = true;
  };
  switchViewCategory(){
    this.category_controls.creating = false;
    this.category_controls.editing = false;
    this.category_controls.viewing = true;
    this.ui_controls.is_loading = false;
  };

  goBack() {
    this.router.navigate(['/administration']).then(r => console.log(r));
  }


}
