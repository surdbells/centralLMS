import { ComponentFixture, TestBed } from '@angular/core/testing';

import { LearnerSkillsPageComponent } from './learner-skills-page.component';

describe('LearnerSkillsPageComponent', () => {
  let component: LearnerSkillsPageComponent;
  let fixture: ComponentFixture<LearnerSkillsPageComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [LearnerSkillsPageComponent]
    })
    .compileComponents();

    fixture = TestBed.createComponent(LearnerSkillsPageComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
