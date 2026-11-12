import { Component } from '@angular/core';
import {AdminLiveTrainingComponent} from '../../live-training/admin/admin-live-training.component';

/**
 * `/trainings` — an alias for the live-training admin screen.
 *
 * The inner component renders the admin top bar, the page header and its
 * back button, so this shell only places it (it used to add a second top
 * bar and a stray back button above the header).
 */
@Component({
  selector: 'app-admin-training',
  standalone: true,
  imports: [AdminLiveTrainingComponent],
  templateUrl: './admin-training.component.html',
  styleUrl: './admin-training.component.css'
})
export class AdminTrainingComponent {}
