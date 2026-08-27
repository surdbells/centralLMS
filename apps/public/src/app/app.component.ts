import { Component } from '@angular/core';
import { RouterOutlet } from '@angular/router';

/**
 * The marketing site.
 *
 * Nothing here signs anybody in. It used to share a bundle with the
 * application, so a visitor reading the pricing page downloaded the course
 * player, the assessment engine and every administration screen before they
 * had decided whether they wanted an account at all.
 */
@Component({
  selector: 'app-root',
  standalone: true,
  imports: [RouterOutlet],
  template: '<router-outlet />',
})
export class AppComponent {
}
