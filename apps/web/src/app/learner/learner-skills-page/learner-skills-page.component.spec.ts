import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { provideNoopAnimations } from '@angular/platform-browser/animations';
import { ComponentFixture, TestBed } from '@angular/core/testing';

import { LearnerSkillsPageComponent } from './learner-skills-page.component';

describe('LearnerSkillsPageComponent', () => {
  let component: LearnerSkillsPageComponent;
  let fixture: ComponentFixture<LearnerSkillsPageComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [LearnerSkillsPageComponent],
      providers: [
        // Generated specs never provided these, so none of them could run.
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        provideNoopAnimations(),
      ],
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
