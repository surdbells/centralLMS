import { Component } from '@angular/core';
import { RouterOutlet } from '@angular/router';

/**
 * The operator console.
 *
 * A separate deployment from both the marketing site and the tenant
 * application: the people who run the platform, not the people who use it.
 */
@Component({
  selector: 'app-root',
  standalone: true,
  imports: [RouterOutlet],
  template: '<router-outlet />',
})
export class AppComponent {
}
