// live-training/live-training.routes.ts

import { NgModule } from '@angular/core';
import { RouterModule, Routes } from '@angular/router';
import {AdminLiveTrainingComponent} from './admin/admin-training/admin-live-training/admin-live-training.component';
import {LearnerLiveTrainingComponent} from './learner/my-training/learner-live-training/learner-live-training.component';


const routes: Routes = [
  // Admin routes
  {
    path: 'livetraining',
    children: [
      { path: '', component: AdminLiveTrainingComponent },
      { path: 'training/:id', component: AdminLiveTrainingComponent },
      { path: 'training/:id/session/:sessionId', component: AdminLiveTrainingComponent }
    ]
  },
  // Learner routes
  {
    path: 'learner/training',
    children: [
      { path: '', component: LearnerLiveTrainingComponent },
      { path: 'training/:id', component: LearnerLiveTrainingComponent },
      { path: 'scan', component: LearnerLiveTrainingComponent }
    ]
  }
];

@NgModule({
  imports: [RouterModule.forChild(routes)],
  exports: [RouterModule]
})
export class LiveTrainingRoutingModule {}
