import { ComponentFixture, TestBed } from '@angular/core/testing';

import { AdmintopComponent } from './admintop.component';

describe('AdmintopComponent', () => {
  let component: AdmintopComponent;
  let fixture: ComponentFixture<AdmintopComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [AdmintopComponent]
    })
    .compileComponents();

    fixture = TestBed.createComponent(AdmintopComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
