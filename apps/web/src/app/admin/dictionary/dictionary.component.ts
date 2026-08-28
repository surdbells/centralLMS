import { Component, OnInit, inject } from '@angular/core';
import { CommonModule, DatePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { TuiIcon, TuiLoader } from '@taiga-ui/core';
import { TUI_CONFIRM } from '@taiga-ui/kit';
import { TuiResponsiveDialogService } from '@taiga-ui/addon-mobile';
import { HotToastService } from '@ngneat/hot-toast';
import { Observable } from 'rxjs';
import { SideComponent } from '../../partials/side/side.component';
import { TopComponent } from '../../partials/top/top.component';
import { AdmintopComponent } from '../../partials/top/admintop/admintop.component';
import {
  LookupsService,
  type Lookup,
  type LookupInput,
  type LookupKind,
} from '../../services/lookups.service';
import { ApiError } from '../../core/api';

// ---------------------------------------------------------------------------
// The six kinds, as the screen names them
// ---------------------------------------------------------------------------
//
// Ids are strings. They were numbers, and every one of them is a UUID now.

interface Branches {
  branch_id: string;
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
  department_id: string;
  department_code: string;
  department_name: string;
  description: string;
  status: boolean;
  created_at: string;
  updated_at: string;
}

interface Teams {
  team_id: string;
  team_code: string;
  team_name: string;
  description: string;
  status: boolean;
  created_at: string;
  updated_at: string;
}

interface Levels {
  level_id: string;
  level_code: string;
  level_name: string;
  description: string;
  status: boolean;
  created_at: string;
  updated_at: string;
}

interface JobTitle {
  job_title_id: string;
  title_name: string;
  description: string;
  status: boolean;
  created_at: string;
  updated_at: string;
}

interface Category {
  category_id: string;
  name: string;
  description: string;
  status: boolean;
  created_at: string;
  updated_at: string;
}

/**
 * A tenant's own vocabulary: its branches, departments, teams, levels, job
 * titles and course categories.
 *
 * The legacy had twenty-four endpoints behind this screen — list, create,
 * update and toggle, four times over for six kinds — each with its own
 * response shape, and each reached with `id`, `tenant_id` and `token`
 * decoded from `atob(sessionStorage.SESSION)` and posted in the body. The
 * API has one resource parameterised by kind, so the six blocks here
 * differ only in what the screen calls each field, and share one read, one
 * write and one confirmation between them.
 *
 * A branch's address lives in the kind's `attributes`, which is what that
 * column is for — the alternative is four columns on a table shared by six
 * kinds, five of which would never fill them.
 */
@Component({
  selector: 'app-dictionary',
  standalone: true,
  imports: [DatePipe, FormsModule, CommonModule, SideComponent, TopComponent, TuiIcon, TuiLoader, AdmintopComponent],
  templateUrl: './dictionary.component.html',
  styleUrl: './dictionary.component.css',
})
export class DictionaryComponent implements OnInit {
  private readonly dialogs = inject(TuiResponsiveDialogService);
  private readonly lookups = inject(LookupsService);
  private readonly router = inject(Router);
  private readonly toast = inject(HotToastService);

  branches: Branches[] = [];
  departments: Departments[] = [];
  teams: Teams[] = [];
  category: Category[] = [];
  levels: Levels[] = [];
  job_title: JobTitle[] = [];

  countries: string[] = [
    'Nigeria',
    'Ghana',
    'South Africa',
    'Kenya',
    'United States',
    'United Kingdom',
    'Canada',
    'Germany',
    'United Arab Emirates',
    'India',
  ];

  ui_controls = { is_loading: false };

  branch_controls = { editing: false, viewing: true, creating: false };
  department_controls = { editing: false, viewing: true, creating: false };
  jobTitle_controls = { editing: false, viewing: true, creating: false };
  team_controls = { editing: false, viewing: true, creating: false };
  level_controls = { editing: false, viewing: true, creating: false };
  category_controls = { editing: false, viewing: true, creating: false };

  /**
   * Pagination.
   *
   * Lookups are a tenant's own vocabulary — a few dozen rows, not thousands —
   * and the API returns them whole, so this describes one page containing
   * everything rather than a window onto a larger set.
   */
  meta = { total: 0, page: 1, per_page: 10, total_pages: 1 };

  /**
   * What the pager binds to.
   *
   * It used to carry `id`, `tenant_id` and `token` as well, decoded from
   * the session blob and posted with every list request.
   */
  initial = { page: 1, per_page: 10 };

  // The forms. No `id`, `tenant_id` or `token` on any of them: those were
  // decoded out of `atob(sessionStorage.SESSION)` and posted with every
  // request, which is how a client named the organisation it was writing to.

  singleBranch = { branch_id: '', branch_name: '', branch_code: '', address: '', city: '', state: '', country: '', status: false };
  createBranch = { branch_name: '', branch_code: '', address: '', city: '', state: '', country: '' };

  singleDepartment = { department_id: '', department_code: '', department_name: '', description: '', status: false };
  createDepartment = { department_name: '', description: '' };

  singleTeam = { team_id: '', team_code: '', team_name: '', description: '', status: false };
  createTeam = { team_name: '', description: '' };

  singleLevel = { level_id: '', level_code: '', level_name: '', description: '', status: false };
  createLevel = { level_name: '', description: '' };

  singleJobTitle = { job_title_id: '', title_name: '', description: '', status: false };
  createJobTitle = { title_name: '', description: '' };

  singleCategory = { category_id: '', name: '', description: '', status: false };
  createCategory = { name: '', description: '' };

  ngOnInit(): void {
    this.listBranch();
  }

  // ==========================================
  // THE ONE IMPLEMENTATION
  // ==========================================

  /**
   * Reads one kind and hands the rows to whoever asked for them.
   *
   * `forget` first because the service caches the active list for the pickers
   * that fill themselves from it; a kind edited on this screen must not go on
   * serving the old list to the rest of the application.
   */
  private read(kind: LookupKind, into: (rows: Lookup[]) => void): void {
    this.ui_controls.is_loading = true;
    this.lookups.forget(kind);

    this.lookups.list(kind).subscribe({
      next: (rows) => {
        into(rows);
        this.meta = {
          total: rows.length,
          page: 1,
          per_page: rows.length,
          total_pages: 1,
        };
        this.ui_controls.is_loading = false;
      },
      error: (failure: ApiError) => {
        // The spinner stops either way. It used to run for ever on a failure,
        // because the legacy's success check simply did nothing when the
        // response was not `response_code === 200`.
        this.ui_controls.is_loading = false;
        this.error_notification(failure.message);
      },
    });
  }

  /** Creates or updates, then reloads and returns to the list. */
  private write(
    request: Observable<Lookup>,
    reload: () => void,
    backToList: () => void,
    said: string,
  ): void {
    this.ui_controls.is_loading = true;

    request.subscribe({
      next: () => {
        this.success_notification(said);
        reload();
        backToList();
      },
      error: (failure: ApiError) => {
        this.ui_controls.is_loading = false;
        this.error_notification(failure.message);
      },
    });
  }

  /** Asks before deactivating or reactivating anything. */
  private confirm(
    noun: string,
    name: string,
    activating: boolean,
    then: () => void,
  ): void {
    this.dialogs
      .open<boolean>(TUI_CONFIRM, {
        label: 'Confirm',
        data: {
          content: `Your ${noun} ${name} will be ${activating ? 'reactivated' : 'deactivated'}`,
          yes: activating ? 'Activate' : 'Deactivate',
          no: 'Cancel',
        },
      })
      .subscribe((confirmed) => {
        if (confirmed) {
          then();
        }
      });
  }

  // ==========================================
  // BRANCHES
  // ==========================================

  listBranch(page = 1, perPage = this.initial.per_page): void {
    this.initial.page = page;
    this.initial.per_page = perPage;

    this.read('branch', (rows) => {
      this.branches = rows.map((row) => ({
        branch_id: row.id,
        branch_code: row.code ?? '',
        branch_name: row.name,
        // A branch's address lives in the kind's `attributes`, which is what
        // that column is for — the alternative is four columns on a table
        // shared by six kinds, five of which never fill them.
        address: attribute(row, 'address'),
        city: attribute(row, 'city'),
        state: attribute(row, 'state'),
        country: attribute(row, 'country'),
        status: row.isActive,
        created_at: row.createdAt ?? '',
        updated_at: row.updatedAt ?? '',
      }));
    });
  }

  getSingleBranch(branch: Branches): void {
    this.singleBranch = { ...branch };
  }

  createABranch(): void {
    this.write(
      this.lookups.create('branch', {
        name: this.createBranch.branch_name,
        code: this.createBranch.branch_code || null,
        attributes: {
          address: this.createBranch.address,
          city: this.createBranch.city,
          state: this.createBranch.state,
          country: this.createBranch.country,
        },
      } as LookupInput),
      () => this.listBranch(),
      () => this.switchViewBranch(),
      'The branch was created.',
    );
  }

  updateABranch(): void {
    this.write(
      this.lookups.update('branch', this.singleBranch.branch_id, {
        name: this.singleBranch.branch_name,
        code: this.singleBranch.branch_code || null,
        attributes: {
          address: this.singleBranch.address,
          city: this.singleBranch.city,
          state: this.singleBranch.state,
          country: this.singleBranch.country,
        },
      } as Partial<LookupInput>),
      () => this.listBranch(),
      () => this.switchViewBranch(),
      'The branch was saved.',
    );
  }

  switchABranch(branch: string, status: boolean): void {
    this.write(
      this.lookups.setStatus('branch', branch, status),
      () => this.listBranch(),
      () => this.switchViewBranch(),
      status ? 'The branch is active again.' : 'The branch was deactivated.',
    );
  }

  startDeactivateABranch(branch: string, name: string, status: boolean): void {
    this.confirm('branch', name, status, () => this.switchABranch(branch, status));
  }

  startReactivateABranch(branch: string, name: string, status: boolean): void {
    this.confirm('branch', name, status, () => this.switchABranch(branch, status));
  }

  // ==========================================
  // DEPARTMENTS
  // ==========================================

  listDepartment(page = 1, perPage = this.initial.per_page): void {
    this.initial.page = page;
    this.initial.per_page = perPage;

    this.read('department', (rows) => {
      this.departments = rows.map((row) => ({
        department_id: row.id,
        department_code: row.code ?? '',
        department_name: row.name,
        description: row.description ?? '',
        status: row.isActive,
        created_at: row.createdAt ?? '',
        updated_at: row.updatedAt ?? '',
      }));
    });
  }

  getSingleDepartment(department: Departments): void {
    this.singleDepartment = { ...department };
  }

  createADepartment(): void {
    this.write(
      this.lookups.create('department', {
        name: this.createDepartment.department_name,
        description: this.createDepartment.description || null,
      }),
      () => this.listDepartment(),
      () => this.switchViewDepartment(),
      'The department was created.',
    );
  }

  updateADepartment(): void {
    this.write(
      this.lookups.update('department', this.singleDepartment.department_id, {
        name: this.singleDepartment.department_name,
        description: this.singleDepartment.description || null,
      }),
      () => this.listDepartment(),
      () => this.switchViewDepartment(),
      'The department was saved.',
    );
  }

  switchADepartment(department: string, status: boolean): void {
    this.write(
      this.lookups.setStatus('department', department, status),
      () => this.listDepartment(),
      () => this.switchViewDepartment(),
      status ? 'The department is active again.' : 'The department was deactivated.',
    );
  }

  startDeactivateADepartment(department: string, name: string, status: boolean): void {
    this.confirm('department', name, status, () => this.switchADepartment(department, status));
  }

  startReactivateADepartment(department: string, name: string, status: boolean): void {
    this.confirm('department', name, status, () => this.switchADepartment(department, status));
  }

  // ==========================================
  // JOB TITLES
  // ==========================================

  listJobTitle(page = 1, perPage = this.initial.per_page): void {
    this.initial.page = page;
    this.initial.per_page = perPage;

    this.read('job_title', (rows) => {
      this.job_title = rows.map((row) => ({
        job_title_id: row.id,
        title_name: row.name,
        description: row.description ?? '',
        status: row.isActive,
        created_at: row.createdAt ?? '',
        updated_at: row.updatedAt ?? '',
      }));
    });
  }

  getSingleJobTitle(jobTitle: JobTitle): void {
    this.singleJobTitle = { ...jobTitle };
  }

  createAJobTitle(): void {
    this.write(
      this.lookups.create('job_title', {
        name: this.createJobTitle.title_name,
        description: this.createJobTitle.description || null,
      }),
      () => this.listJobTitle(),
      () => this.switchViewJobTitle(),
      'The job title was created.',
    );
  }

  updateAJobTitle(): void {
    this.write(
      this.lookups.update('job_title', this.singleJobTitle.job_title_id, {
        name: this.singleJobTitle.title_name,
        description: this.singleJobTitle.description || null,
      }),
      () => this.listJobTitle(),
      () => this.switchViewJobTitle(),
      'The job title was saved.',
    );
  }

  switchAJobTitle(jobTitle: string, status: boolean): void {
    this.write(
      this.lookups.setStatus('job_title', jobTitle, status),
      () => this.listJobTitle(),
      () => this.switchViewJobTitle(),
      status ? 'The job title is active again.' : 'The job title was deactivated.',
    );
  }

  startDeactivateAJobTitle(jobTitle: string, name: string, status: boolean): void {
    this.confirm('job title', name, status, () => this.switchAJobTitle(jobTitle, status));
  }

  startReactivateAJobTitle(jobTitle: string, name: string, status: boolean): void {
    this.confirm('job title', name, status, () => this.switchAJobTitle(jobTitle, status));
  }

  // ==========================================
  // LEVELS
  // ==========================================

  listLevels(page = 1, perPage = this.initial.per_page): void {
    this.initial.page = page;
    this.initial.per_page = perPage;

    this.read('level', (rows) => {
      this.levels = rows.map((row) => ({
        level_id: row.id,
        level_code: row.code ?? '',
        level_name: row.name,
        description: row.description ?? '',
        status: row.isActive,
        created_at: row.createdAt ?? '',
        updated_at: row.updatedAt ?? '',
      }));
    });
  }

  getSingleLevel(level: Levels): void {
    this.singleLevel = { ...level };
  }

  createALevel(): void {
    this.write(
      this.lookups.create('level', {
        name: this.createLevel.level_name,
        description: this.createLevel.description || null,
      }),
      () => this.listLevels(),
      () => this.switchViewLevels(),
      'The level was created.',
    );
  }

  updateALevel(): void {
    this.write(
      this.lookups.update('level', this.singleLevel.level_id, {
        name: this.singleLevel.level_name,
        description: this.singleLevel.description || null,
      }),
      () => this.listLevels(),
      () => this.switchViewLevels(),
      'The level was saved.',
    );
  }

  switchALevel(level: string, status: boolean): void {
    this.write(
      this.lookups.setStatus('level', level, status),
      () => this.listLevels(),
      () => this.switchViewLevels(),
      status ? 'The level is active again.' : 'The level was deactivated.',
    );
  }

  startDeactivateALevel(level: string, name: string, status: boolean): void {
    this.confirm('level', name, status, () => this.switchALevel(level, status));
  }

  startReactivateALevel(level: string, name: string, status: boolean): void {
    this.confirm('level', name, status, () => this.switchALevel(level, status));
  }

  // ==========================================
  // CATEGORIES
  // ==========================================

  listCategory(page = 1, perPage = this.initial.per_page): void {
    this.initial.page = page;
    this.initial.per_page = perPage;

    this.read('category', (rows) => {
      this.category = rows.map((row) => ({
        category_id: row.id,
        name: row.name,
        description: row.description ?? '',
        status: row.isActive,
        created_at: row.createdAt ?? '',
        updated_at: row.updatedAt ?? '',
      }));
    });
  }

  getSingleCategory(category: Category): void {
    this.singleCategory = { ...category };
  }

  createACategory(): void {
    this.write(
      this.lookups.create('category', {
        name: this.createCategory.name,
        description: this.createCategory.description || null,
      }),
      () => this.listCategory(),
      () => this.switchViewCategory(),
      'The category was created.',
    );
  }

  updateACategory(): void {
    this.write(
      this.lookups.update('category', this.singleCategory.category_id, {
        name: this.singleCategory.name,
        description: this.singleCategory.description || null,
      }),
      () => this.listCategory(),
      () => this.switchViewCategory(),
      'The category was saved.',
    );
  }

  switchACategory(category: string, status: boolean): void {
    this.write(
      this.lookups.setStatus('category', category, status),
      () => this.listCategory(),
      () => this.switchViewCategory(),
      status ? 'The category is active again.' : 'The category was deactivated.',
    );
  }

  startDeactivateACategory(category: string, name: string, status: boolean): void {
    this.confirm('category', name, status, () => this.switchACategory(category, status));
  }

  startReactivateACategory(category: string, name: string, status: boolean): void {
    this.confirm('category', name, status, () => this.switchACategory(category, status));
  }

  // ==========================================
  // TEAMS
  // ==========================================

  listTeam(page = 1, perPage = this.initial.per_page): void {
    this.initial.page = page;
    this.initial.per_page = perPage;

    this.read('team', (rows) => {
      this.teams = rows.map((row) => ({
        team_id: row.id,
        team_code: row.code ?? '',
        team_name: row.name,
        description: row.description ?? '',
        status: row.isActive,
        created_at: row.createdAt ?? '',
        updated_at: row.updatedAt ?? '',
      }));
    });
  }

  getSingleTeam(team: Teams): void {
    this.singleTeam = { ...team };
  }

  createATeam(): void {
    this.write(
      this.lookups.create('team', {
        name: this.createTeam.team_name,
        description: this.createTeam.description || null,
      }),
      () => this.listTeam(),
      () => this.switchViewTeam(),
      'The team was created.',
    );
  }

  updateATeam(): void {
    this.write(
      this.lookups.update('team', this.singleTeam.team_id, {
        name: this.singleTeam.team_name,
        description: this.singleTeam.description || null,
      }),
      () => this.listTeam(),
      () => this.switchViewTeam(),
      'The team was saved.',
    );
  }

  switchATeam(team: string, status: boolean): void {
    this.write(
      this.lookups.setStatus('team', team, status),
      () => this.listTeam(),
      () => this.switchViewTeam(),
      status ? 'The team is active again.' : 'The team was deactivated.',
    );
  }

  startDeactivateATeam(team: string, name: string, status: boolean): void {
    this.confirm('team', name, status, () => this.switchATeam(team, status));
  }

  startReactivateATeam(team: string, name: string, status: boolean): void {
    this.confirm('team', name, status, () => this.switchATeam(team, status));
  }

  // ==========================================
  // WHICH PANEL IS SHOWING
  // ==========================================

  switchCreateBranch(): void { this.branch_controls = { editing: false, viewing: false, creating: true }; }
  switchEditBranch(): void { this.branch_controls = { editing: true, viewing: false, creating: false }; }
  switchViewBranch(): void {
    this.branch_controls = { editing: false, viewing: true, creating: false };
    this.ui_controls.is_loading = false;
  }

  switchCreateDepartment(): void { this.department_controls = { editing: false, viewing: false, creating: true }; }
  switchEditDepartment(): void { this.department_controls = { editing: true, viewing: false, creating: false }; }
  switchViewDepartment(): void {
    this.department_controls = { editing: false, viewing: true, creating: false };
    this.ui_controls.is_loading = false;
  }

  switchCreateJobTitle(): void { this.jobTitle_controls = { editing: false, viewing: false, creating: true }; }
  switchEditJobTitle(): void { this.jobTitle_controls = { editing: true, viewing: false, creating: false }; }
  switchViewJobTitle(): void {
    this.jobTitle_controls = { editing: false, viewing: true, creating: false };
    this.ui_controls.is_loading = false;
  }

  switchCreateLevels(): void { this.level_controls = { editing: false, viewing: false, creating: true }; }
  switchEditLevels(): void { this.level_controls = { editing: true, viewing: false, creating: false }; }
  switchViewLevels(): void {
    this.level_controls = { editing: false, viewing: true, creating: false };
    this.ui_controls.is_loading = false;
  }

  switchCreateTeam(): void { this.team_controls = { editing: false, viewing: false, creating: true }; }
  switchEditTeam(): void { this.team_controls = { editing: true, viewing: false, creating: false }; }
  switchViewTeam(): void {
    this.team_controls = { editing: false, viewing: true, creating: false };
    this.ui_controls.is_loading = false;
  }

  switchCreateCategory(): void { this.category_controls = { editing: false, viewing: false, creating: true }; }
  switchEditCategory(): void { this.category_controls = { editing: true, viewing: false, creating: false }; }
  switchViewCategory(): void {
    this.category_controls = { editing: false, viewing: true, creating: false };
    this.ui_controls.is_loading = false;
  }

  // ==========================================
  // ELSEWHERE
  // ==========================================

  success_notification(message: string): void {
    this.toast.success(message);
  }

  error_notification(message: string): void {
    this.toast.error(message);
  }

  goBack(): void {
    void this.router.navigate(['/administration']);
  }
}

/** One of a branch's address fields, or an empty string. */
function attribute(row: Lookup, name: string): string {
  const attributes = (row as unknown as Record<string, unknown>)['attributes'];

  if (attributes === null || typeof attributes !== 'object') {
    return '';
  }

  return String((attributes as Record<string, unknown>)[name] ?? '');
}
