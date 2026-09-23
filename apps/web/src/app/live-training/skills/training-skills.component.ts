// training-skills.component.ts
// Component for managing training skills assignment

import { Component, OnInit, Input, inject } from '@angular/core';
import { Router, ActivatedRoute } from '@angular/router';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { LiveTrainingService, LiveTraining } from '../live-training.service';
import {
  TrainingSkill,
  Skill,
  SkillCategory,
  SkillAssignmentResult
} from '../live-training.interfaces';
import {AdmintopComponent} from '../../partials/top/admintop/admintop.component';
import { TranslatePipe } from '../../translate.pipe';
import { I18nService } from '../../i18n.service';

@Component({
  selector: 'app-training-skills',
  standalone: true,
  imports: [CommonModule, FormsModule, AdmintopComponent, TranslatePipe],
  templateUrl: './training-skills.component.html',
  styleUrls: ['./training-skills.component.scss']
})
export class TrainingSkillsComponent implements OnInit {
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly i18n = inject(I18nService);

  @Input() trainingId!: string;

  // The programme whose skills are being managed is chosen here when the screen
  // is opened without one (the admin "Skills" button carries no context).
  trainings: LiveTraining[] = [];

  // Assigned skills
  assignedSkills: TrainingSkill[] = [];

  // Available skills
  availableSkills: Skill[] = [];
  skillCategories: SkillCategory[] = [];

  // Selection
  selectedSkill: Skill | null = null;
  proficiencyLevel: 'beginner' | 'intermediate' | 'advanced' | 'expert' = 'beginner';
  isRequired = true;

  // Search/Filter
  skillSearchQuery = '';
  selectedCategory: string | null = null;

  // State
  loading = false;
  searchingSkills = false;
  adding = false;
  showAddSkillModal = false;

  constructor(private liveTrainingService: LiveTrainingService) {}

  ngOnInit(): void {
    // A trainingId may arrive as an @Input (embedded) or a ?trainingId= query
    // param (deep link); otherwise the admin picks one from the list below.
    const fromRoute = this.route.snapshot.queryParamMap.get('trainingId');
    if (!this.trainingId && fromRoute) {
      this.trainingId = fromRoute;
    }
    this.loadTrainings();
    this.loadSkillCategories();
    if (this.trainingId) {
      this.loadTrainingSkills();
    }
  }

  loadTrainings(): void {
    this.liveTrainingService.getTrainings().subscribe({
      next: (trainings) => { this.trainings = trainings; },
      error: () => { /* the picker simply stays empty */ }
    });
  }

  onTrainingChange(): void {
    this.assignedSkills = [];
    if (this.trainingId) {
      this.loadTrainingSkills();
    }
  }

  loadTrainingSkills(): void {
    if (!this.trainingId) { return; }
    this.loading = true;
    this.liveTrainingService.getTrainingSkills(this.trainingId).subscribe({
      next: (skills) => {
        this.assignedSkills = skills;
        this.loading = false;
      },
      error: () => {
        this.loading = false;
      }
    });
  }

  loadSkillCategories(): void {
    this.liveTrainingService.getSkillsCategories().subscribe({
      next: (categories) => {
        this.skillCategories = categories;
      }
    });
  }

  searchSkills(): void {
    this.searchingSkills = true;
    const filters = {
      search: this.skillSearchQuery || undefined,
      category_id: this.selectedCategory || undefined,
      training_id: this.trainingId
    };

    this.liveTrainingService.searchSkills(filters).subscribe({
      next: (skills) => {
        this.availableSkills = skills;
        this.searchingSkills = false;
      },
      error: () => {
        this.searchingSkills = false;
      }
    });
  }

  selectSkill(skill: Skill): void {
    this.selectedSkill = skill;
  }

  addSkill(): void {
    if (!this.selectedSkill) return;

    const skill: TrainingSkill = {
      training_id: this.trainingId,
      skill_id: this.selectedSkill.skill_id,
      proficiency_level: this.proficiencyLevel,
      is_required: this.isRequired
    };

    this.adding = true;
    this.liveTrainingService.assignSkills(this.trainingId, [skill]).subscribe({
      next: (result) => {
        this.loadTrainingSkills();
        this.closeAddSkillModal();
        this.adding = false;
      },
      error: (err) => {
        alert(this.i18n.t('live.skills.add_failed') + (err.error?.message || err.message));
        this.adding = false;
      }
    });
  }

  removeSkill(skill: TrainingSkill): void {
    if (!confirm(this.i18n.t('live.skills.remove_confirm', { name: skill.skill_name }))) return;

    this.liveTrainingService.removeSkill(this.trainingId, skill.skill_id).subscribe({
      next: () => {
        this.loadTrainingSkills();
      },
      error: (err) => {
        alert(this.i18n.t('live.skills.remove_failed') + (err.error?.message || err.message));
      }
    });
  }

  closeAddSkillModal(): void {
    this.showAddSkillModal = false;
    this.selectedSkill = null;
    this.skillSearchQuery = '';
    this.selectedCategory = null;
    this.proficiencyLevel = 'beginner';
    this.isRequired = true;
    this.availableSkills = [];
  }

  getProficiencyBadgeClass(level: string): string {
    return this.liveTrainingService.getProficiencyBadgeClass(level);
  }

  getProficiencyDisplayName(level: string): string {
    return this.liveTrainingService.getProficiencyDisplayName(level);
  }

  /** Back to the programme list this screen was opened from. */
  goBack(): void {
    void this.router.navigate(['/live-training/admin']);
  }
}
