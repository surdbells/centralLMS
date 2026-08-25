import { ComponentFixture, TestBed } from '@angular/core/testing';

import { CerticatesComponent } from './certicates.component';

describe('CerticatesComponent', () => {
  let component: CerticatesComponent;
  let fixture: ComponentFixture<CerticatesComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [CerticatesComponent]
    })
    .compileComponents();

    fixture = TestBed.createComponent(CerticatesComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
