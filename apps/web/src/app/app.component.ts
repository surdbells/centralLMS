import { TuiRoot } from "@taiga-ui/core";
import { Component, inject } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { BrandingService } from './core/branding.service';

@Component({
  selector: 'app-root',
  imports: [RouterOutlet, TuiRoot],
  templateUrl: './app.component.html',
  standalone: true,
  styleUrl: './app.component.css'
})
export class AppComponent {
  title = 'ThreeBayti';

  /**
   * Injected for its effect, which paints the organisation's colours onto
   * the root element whenever the session names a different one.
   *
   * Nothing reads this field; the service has to be constructed, and the
   * root component is the one thing alive for as long as the application.
   */
  private readonly branding = inject(BrandingService);
}
