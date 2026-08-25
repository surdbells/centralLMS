import { ComponentFixture, TestBed } from '@angular/core/testing';

import { AdminSkillsPageComponent } from './admin-skills-page.component';

describe('AdminSkillsPageComponent', () => {
  let component: AdminSkillsPageComponent;
  let fixture: ComponentFixture<AdminSkillsPageComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [AdminSkillsPageComponent]
    })
    .compileComponents();

    fixture = TestBed.createComponent(AdminSkillsPageComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
