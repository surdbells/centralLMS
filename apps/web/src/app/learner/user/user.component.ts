import { Component } from '@angular/core';
import { TopComponent } from '../../partials/top/top.component';
import { LearnerDashboardComponent } from '../../admin/learner-dashboard/learner-dashboard.component';

/**
 * The page at `/account`: the bar across the top, and the learner's dashboard.
 *
 * That is all its template has ever contained. Behind those six lines sat 293
 * more — notes, pagination, a note editor, gamification statistics, a level, a
 * badge list, Apex charts, DataTables — none of it read by anything, and its
 * `ngOnInit` fired a request at `user/statistics` on the legacy host every
 * time the page opened. That request was the last thing on this screen still
 * talking to it, and the only visible sign of the rest was a console error.
 *
 * The notes screen it was presumably cut from is at `/notes`, and it works.
 */
@Component({
  selector: 'app-user',
  standalone: true,
  templateUrl: './user.component.html',
  imports: [TopComponent, LearnerDashboardComponent],
  styleUrl: './user.component.css',
})
export class UserComponent {
}
