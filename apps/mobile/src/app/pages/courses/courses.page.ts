import { Component, DestroyRef, OnInit, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Router } from '@angular/router';
import { Observable, Subject, of } from 'rxjs';
import { catchError, map, switchMap, tap } from 'rxjs/operators';
import {
  IonContent, IonSegment, IonSegmentButton,
  IonLabel, IonSearchbar, IonIcon, IonProgressBar, IonSkeletonText,
  IonRippleEffect, IonRefresher, IonRefresherContent,
} from '@ionic/angular/standalone';
import { LearnerService } from '../../core/learner.service';
import { CatalogueCourse, Enrollment } from '../../core/models';
import { initialOf, minutesLabel, thumbStyle } from '../../core/ui';
import { BrandHeaderComponent } from '../../components/brand-header/brand-header.component';
import { EmptyStateComponent } from '../../components/empty-state/empty-state.component';
import { SelectComponent, SelectOption } from '../../components/select/select.component';

type Tab = 'mine' | 'catalogue';

@Component({
  selector: 'app-courses',
  standalone: true,
  imports: [
    IonContent, IonSegment, IonSegmentButton,
    IonLabel, IonSearchbar, IonIcon, IonProgressBar, IonSkeletonText,
    IonRippleEffect, IonRefresher, IonRefresherContent, BrandHeaderComponent,
    EmptyStateComponent, SelectComponent,
  ],
  template: `
    <ion-content [fullscreen]="true">
      <ion-refresher slot="fixed" (ionRefresh)="reload($event)">
        <ion-refresher-content></ion-refresher-content>
      </ion-refresher>

      <app-brand-header title="Courses"></app-brand-header>

      <div class="ca-controls">
        <ion-segment [value]="tab()" (ionChange)="switch($any($event.detail.value))">
          <ion-segment-button value="mine"><ion-label>My Courses</ion-label></ion-segment-button>
          <ion-segment-button value="catalogue"><ion-label>Catalogue</ion-label></ion-segment-button>
        </ion-segment>
        <ion-searchbar
          class="ca-search"
          [value]="query()"
          placeholder="Search courses"
          [debounce]="300"
          (ionInput)="onSearch($any($event.target).value)"></ion-searchbar>
        @if (tab() === 'catalogue' && categoryOptions().length > 2) {
          <app-select
            class="ca-filter"
            [(value)]="category"
            [options]="categoryOptions()"
            label="Category"
            placeholder="All categories"
            searchPlaceholder="Search categories"></app-select>
        }
      </div>

      <div class="pad">
        @if (loading()) {
          @for (i of [1,2,3,4,5]; track i) {
            <div class="row row-skeleton">
              <ion-skeleton-text animated class="sk-thumb"></ion-skeleton-text>
              <div class="body">
                <ion-skeleton-text animated style="width:70%; height:14px; border-radius:5px;"></ion-skeleton-text>
                <ion-skeleton-text animated style="width:45%; height:9px; margin-top:10px; border-radius:4px;"></ion-skeleton-text>
              </div>
            </div>
          }
        } @else if (tab() === 'mine') {
          @if (mine().length) {
            @for (c of mine(); track c.id) {
              <div class="row ion-activatable tappable rise" (click)="open(c.courseId)">
                <ion-ripple-effect></ion-ripple-effect>
                <div class="thumb" [style]="thumb(c.courseTitle || '')"><span>{{ initial(c.courseTitle || '') }}</span></div>
                <div class="body">
                  <p class="title">{{ c.courseTitle }}</p>
                  <ion-progress-bar [value]="c.progressPercentage / 100"></ion-progress-bar>
                  <p class="muted">{{ c.statusLabel }} · {{ c.progressPercentage }}%</p>
                </div>
                <ion-icon name="chevron-forward" class="chev"></ion-icon>
              </div>
            }
          } @else {
            <app-empty-state
              icon="library-outline"
              title="No courses yet"
              message="You're not enrolled in anything. Explore what's on offer."
              actionLabel="Explore the catalogue"
              (action)="switch('catalogue')"></app-empty-state>
          }
        } @else {
          @if (visibleCatalogue().length) {
            @for (c of visibleCatalogue(); track c.id) {
              <div class="row ion-activatable tappable rise" (click)="open(c.id)">
                <ion-ripple-effect></ion-ripple-effect>
                <div class="thumb" [style]="thumb(c.title)"><span>{{ initial(c.title) }}</span></div>
                <div class="body">
                  <p class="title">{{ c.title }}</p>
                  <p class="muted">
                    {{ c.categoryName || 'Course' }}{{ mins(c.durationMinutes) ? ' · ' + mins(c.durationMinutes) : '' }}{{ c.level ? ' · ' + c.level : '' }}
                  </p>
                </div>
                <ion-icon name="chevron-forward" class="chev"></ion-icon>
              </div>
            }
          } @else {
            <app-empty-state
              icon="search"
              [title]="query() || category() ? 'No matches' : 'Nothing here yet'"
              [message]="emptyMessage()"
              [actionLabel]="category() ? 'Clear category' : ''"
              (action)="category.set('')"></app-empty-state>
          }
        }
      </div>
    </ion-content>
  `,
  styles: [`
    .ca-controls { padding: 2px 12px 0; }
    .ca-search { --box-shadow: none; --background: var(--app-field); --border-radius: var(--app-r-sm); padding: 8px 4px 2px; }
    .ca-filter { display: block; padding: 4px 4px 2px; }
    .pad { padding: 8px 16px calc(16px + env(safe-area-inset-bottom)); }
    .row { position: relative; overflow: hidden; display: flex; align-items: center; gap: 12px; background: var(--app-surface); border: var(--app-card-border); border-radius: var(--app-r-sm); padding: 12px; margin-bottom: 10px; box-shadow: var(--app-shadow); }
    .row-skeleton { box-shadow: none; }
    .sk-thumb { width: 52px; height: 52px; border-radius: 12px; flex: 0 0 auto; }
    .thumb { width: 52px; height: 52px; border-radius: 12px; display: grid; place-items: center; flex: 0 0 auto; }
    .thumb span { color: #fff; font-weight: 800; font-size: 1.3rem; }
    .body { flex: 1; min-width: 0; }
    .title { margin: 0 0 6px; font-weight: 700; font-size: .95rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .body .muted { margin: 6px 0 0; font-size: .78rem; }
    .chev { color: var(--ion-color-medium); flex: 0 0 auto; }
    .empty { text-align: center; padding: 48px 16px; color: var(--ion-color-medium); }
    .empty ion-icon { font-size: 42px; }
    .empty p { margin: 12px 0 6px; }
    .empty a { color: var(--ion-color-primary); font-weight: 600; }
  `],
})
export class CoursesPage implements OnInit {
  private readonly learner = inject(LearnerService);
  private readonly router = inject(Router);

  readonly tab = signal<Tab>('mine');
  readonly query = signal('');
  readonly category = signal('');
  readonly loading = signal(true);
  readonly mine = signal<Enrollment[]>([]);
  readonly catalogue = signal<CatalogueCourse[]>([]);

  /** The catalogue's distinct categories, as select options ("All" first). */
  readonly categoryOptions = computed<SelectOption[]>(() => {
    const names = Array.from(
      new Set(this.catalogue().map((c) => c.categoryName).filter((n): n is string => !!n)),
    ).sort((a, b) => a.localeCompare(b));
    return [{ value: '', label: 'All categories' }, ...names.map((n) => ({ value: n, label: n }))];
  });

  /** The catalogue narrowed to the chosen category (client-side). */
  readonly visibleCatalogue = computed(() => {
    const cat = this.category();
    return cat ? this.catalogue().filter((c) => c.categoryName === cat) : this.catalogue();
  });

  readonly emptyMessage = computed(() => {
    if (this.category()) {
      return `No courses in “${this.category()}”${this.query() ? ` matching “${this.query()}”` : ''}.`;
    }
    return this.query() ? `No courses match “${this.query()}”.` : 'No courses are available right now.';
  });

  readonly thumb = thumbStyle;
  readonly initial = initialOf;
  readonly mins = minutesLabel;

  private readonly destroyRef = inject(DestroyRef);
  // Loads run through switchMap so a newer request (a keystroke, a tab switch)
  // cancels the one in flight — no stale, out-of-order results and no empty
  // state flashing while an older request is still resolving.
  private readonly load$ = new Subject<CustomEvent | undefined>();

  ngOnInit(): void {
    this.load$
      .pipe(
        tap((ev) => { if (!ev) { this.loading.set(true); } }),
        switchMap((ev) => {
          const q = this.query().trim() || undefined;
          const tab = this.tab();
          const req: Observable<Enrollment[] | CatalogueCourse[]> =
            tab === 'mine' ? this.learner.myEnrollments(undefined, q) : this.learner.catalogue(q);
          return req.pipe(
            map((rows) => ({ ev, tab, rows })),
            catchError(() => of({ ev, tab, rows: [] as Enrollment[] | CatalogueCourse[] })),
          );
        }),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe(({ ev, tab, rows }) => {
        if (tab === 'mine') {
          this.mine.set(rows as Enrollment[]);
        } else {
          this.catalogue.set(rows as CatalogueCourse[]);
        }
        this.loading.set(false);
        (ev?.target as { complete?: () => void })?.complete?.();
      });

    this.load$.next(undefined);
  }

  switch(tab: Tab): void {
    if (tab && tab !== this.tab()) {
      this.tab.set(tab);
      this.category.set('');
      this.load$.next(undefined);
    }
  }

  onSearch(value: string): void {
    this.query.set(value ?? '');
    this.load$.next(undefined);
  }

  reload(ev?: CustomEvent): void {
    this.load$.next(ev);
  }

  open(courseId: string): void {
    void this.router.navigate(['/courses', courseId]);
  }
}
