import { ComponentFixture, TestBed } from '@angular/core/testing';

import { AdminTrainingComponent } from './admin-training.component';

describe('AdminTrainingComponent', () => {
  let component: AdminTrainingComponent;
  let fixture: ComponentFixture<AdminTrainingComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [AdminTrainingComponent]
    })
    .compileComponents();

    fixture = TestBed.createComponent(AdminTrainingComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
