import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { provideNoopAnimations } from '@angular/platform-browser/animations';
import { ComponentFixture, TestBed } from '@angular/core/testing';

import { AdmintopComponent } from './admintop.component';

describe('AdmintopComponent', () => {
  let component: AdmintopComponent;
  let fixture: ComponentFixture<AdmintopComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [AdmintopComponent],
      providers: [
        // Generated specs never provided these, so none of them could run.
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        provideNoopAnimations(),
      ],
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
