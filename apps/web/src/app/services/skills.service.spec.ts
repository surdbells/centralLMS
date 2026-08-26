import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import type { AppEnvironment } from '../../environments/environment.model';
import { APP_ENVIRONMENT } from '../core/api/api-routing';
import { SkillsService, type LearnerSkills } from './skills.service';

/**
 * The parts of the skills API that a screen can get wrong quietly.
 */
describe('SkillsService', () => {
  let skills: SkillsService;
  let http: HttpTestingController;

  const BASE = 'https://api.example.com/v1';

  beforeEach(() => {
    const environment: AppEnvironment = { production: false, api: { baseUrl: BASE } };

    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: APP_ENVIRONMENT, useValue: environment },
      ],
    });

    skills = TestBed.inject(SkillsService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('asks the server to narrow the list, rather than fetching it all', () => {
    // The admin screen used to request every skill and then assign
    // `filteredSkills = this.skills`, so its filters did nothing at all — and
    // the list it filtered was one page of a paged endpoint.
    skills.getSkills({ search: 'fire', categoryId: 'cat-1', level: 'expert', active: true }).subscribe();

    const request = http.expectOne((r) => r.url === `${BASE}/skills`);

    expect(request.request.params.get('search')).toBe('fire');
    expect(request.request.params.get('categoryId')).toBe('cat-1');
    expect(request.request.params.get('level')).toBe('expert');
    expect(request.request.params.get('active')).toBe('true');

    request.flush({ data: [] });
  });

  it('fetches one person’s held and in-progress skills in a single call', () => {
    // Five legacy endpoints — my-skills, my-skills-by-category,
    // my-skill-progress, user-skills, user-skill-progress — became one, so
    // the panels can no longer disagree about whether a skill is held.
    let received: LearnerSkills | undefined;

    skills.getMySkills().subscribe((mine) => (received = mine));

    http.expectOne(`${BASE}/me/skills`).flush({
      data: {
        held: [{ skillId: 's-1', name: 'Fire Safety' }],
        inProgress: [],
        byCategory: [],
        totals: { held: 1, inProgress: 0 },
      },
    });

    expect(received?.totals.held).toBe(1);
    expect(received?.held.length).toBe(1);
  });

  it('reads another person’s skills from a path, never from a body', () => {
    // The legacy posted `{user_id}` to a shared endpoint that checked only
    // that the caller was an admin, so any id returned any tenant's learner.
    skills.getUserSkills('user-9').subscribe();

    const request = http.expectOne(`${BASE}/users/user-9/skills`);

    expect(request.request.method).toBe('GET');
    request.flush({ data: { held: [], inProgress: [], byCategory: [], totals: { held: 0, inProgress: 0 } } });
  });

  it('replaces what a course teaches, wholesale', () => {
    // PUT, not POST: saving is "this course teaches exactly these", so a
    // skill removed in the dialog is removed on the server.
    skills
      .assignCourseSkills('course-1', [{ skillId: 's-1', proficiency: 'advanced', isPrimary: true }])
      .subscribe();

    const request = http.expectOne(`${BASE}/courses/course-1/skills`);

    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual({
      skills: [{ skillId: 's-1', proficiency: 'advanced', isPrimary: true }],
    });

    request.flush({ data: [] });
  });

  it('returns the categories as a plain list, pages or no pages', () => {
    let received: unknown[] | undefined;

    skills.getCategories(true).subscribe((categories) => (received = categories));

    const request = http.expectOne((r) => r.url === `${BASE}/skills/categories`);

    expect(request.request.params.get('active')).toBe('true');
    request.flush({ data: [{ id: 'c-1', name: 'Technical' }] });

    expect(received?.length).toBe(1);
  });
});
