import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { provideNoopAnimations } from '@angular/platform-browser/animations';
import { ComponentFixture, TestBed } from '@angular/core/testing';

import { UserSecurityComponent } from './user-security.component';

describe('VendorSecurityComponent', () => {
  let component: UserSecurityComponent;
  let fixture: ComponentFixture<UserSecurityComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [UserSecurityComponent],
      providers: [
        // Generated specs never provided these, so none of them could run.
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        provideNoopAnimations(),
      ],
    })
    .compileComponents();

    fixture = TestBed.createComponent(UserSecurityComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
