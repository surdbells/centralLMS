import { ComponentFixture, TestBed } from '@angular/core/testing';

import { MyTrainingComponent } from './my-training.component';

describe('MyTrainingComponent', () => {
  let component: MyTrainingComponent;
  let fixture: ComponentFixture<MyTrainingComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [MyTrainingComponent]
    })
    .compileComponents();

    fixture = TestBed.createComponent(MyTrainingComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
