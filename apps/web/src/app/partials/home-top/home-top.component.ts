import { Component, inject } from '@angular/core';
import {RouterLink} from '@angular/router';
import { TranslatePipe } from '../../translate.pipe';
import { BrandingService } from '../../core/branding.service';

@Component({
  selector: 'app-home-top',
  standalone: true,
  imports: [
    RouterLink,
    TranslatePipe
  ],
  templateUrl: './home-top.component.html',
  styleUrl: './home-top.component.css'
})
export class HomeTopComponent {
  /** The organisation's logo and name, painted by BrandingService. */
  readonly branding = inject(BrandingService);
}
