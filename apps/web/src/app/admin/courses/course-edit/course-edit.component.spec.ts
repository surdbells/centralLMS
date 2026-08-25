import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { of } from 'rxjs';
import { ActivatedRoute, Router } from '@angular/router';

import { CourseEditComponent } from './course-edit.component';
import { CourseService } from '../course.service';

describe('CourseEditComponent', () => {
  let component: CourseEditComponent;
  let fixture: ComponentFixture<CourseEditComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [CourseEditComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        {
          provide: CourseService,
          useValue: {
            getDropdowns: () => of({ categories: [], instructors: [], certificate_templates: [] }),
            getCourse: () => of({}),
            createCourse: () => of({}),
            updateCourse: () => of({}),
            deleteCourse: () => of({}),
            duplicateCourse: () => of({})
          }
        },
        {
          provide: ActivatedRoute,
          useValue: {
            snapshot: {
              paramMap: {
                get: () => null
              }
            }
          }
        },
        {
          provide: Router,
          useValue: {
            navigate: jasmine.createSpy('navigate'),
            // RouterLink subscribes to these when a routerLink appears in
            // the template, so a bare { navigate } fake is not enough.
            events: of(),
            createUrlTree: () => ({}),
            serializeUrl: () => '',
          }
        }
      ]
    }).compileComponents();

    fixture = TestBed.createComponent(CourseEditComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should rewrite a text field with ai polish', () => {
    component.form.title = 'Intro to Cybersecurity';

    component.rewriteFieldValue('title');

    expect(component.form.title).toContain('Cybersecurity');
    expect(component.form.title).not.toBe('Intro to Cybersecurity');
  });
});
