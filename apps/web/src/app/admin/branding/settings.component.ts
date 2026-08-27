import { Component, inject } from '@angular/core';
import { Router } from '@angular/router';
import { TuiIcon } from '@taiga-ui/core';
import { AdmintopComponent } from '../../partials/top/admintop/admintop.component';
import { TenantSettingsComponent } from '../../tenant-settings/tenant-settings.component';

/**
 * The page at `/settings`: a bar, a heading, and the settings themselves.
 *
 * That is all its template has ever contained. Behind it sat a `CrudService`,
 * a session blob decoded out of `sessionStorage` and a `ui_controls` object,
 * none of it read by anything on the page.
 */
@Component({
  selector: 'app-branding',
  standalone: true,
  imports: [TuiIcon, AdmintopComponent, TenantSettingsComponent],
  templateUrl: './settings.component.html',
  styleUrl: './settings.component.css',
})
export class SettingsComponent {
  private readonly router = inject(Router);

  goBack(): void {
    void this.router.navigate(['/administration']);
  }
}
