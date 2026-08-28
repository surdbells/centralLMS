// training-skills.component.ts
// Component for managing training skills assignment

import { Component, OnInit, Input, inject } from '@angular/core';
import { Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { LiveTrainingService } from '../live-training.service';
import {
  TrainingSkill,
  Skill,
  SkillCategory,
  SkillAssignmentResult
} from '../live-training.interfaces';
import {AdmintopComponent} from '../../partials/top/admintop/admintop.component';

@Component({
  selector: 'app-training-skills',
  standalone: true,
  imports: [CommonModule, FormsModule, AdmintopComponent],
  templateUrl: './training-skills.component.html',
  styleUrls: ['./training-skills.component.scss']
})
export class TrainingSkillsComponent implements OnInit {
  private readonly router = inject(Router);

  @Input() trainingId!: string;

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
    this.loadTrainingSkills();
    this.loadSkillCategories();
  }

  loadTrainingSkills(): void {
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
        alert('Failed to add skill: ' + (err.error?.message || err.message));
        this.adding = false;
      }
    });
  }

  removeSkill(skill: TrainingSkill): void {
    if (!confirm(`Remove "${skill.skill_name}" from this training?`)) return;

    this.liveTrainingService.removeSkill(this.trainingId, skill.skill_id).subscribe({
      next: () => {
        this.loadTrainingSkills();
      },
      error: (err) => {
        alert('Failed to remove skill: ' + (err.error?.message || err.message));
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
