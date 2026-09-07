import { Component, DestroyRef, OnInit, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Router } from '@angular/router';
import { Observable, Subject, of } from 'rxjs';
import { catchError, map, switchMap, tap } from 'rxjs/operators';
import {
  IonContent, IonIcon, IonSkeletonText,
  IonRippleEffect, IonRefresher, IonRefresherContent,
} from '@ionic/angular/standalone';
import { LearnerService } from '../../core/learner.service';
import { CatalogueCourse, Enrollment } from '../../core/models';
import { initialOf, minutesLabel, thumbStyle } from '../../core/ui';
import { TranslatePipe } from '../../core/translate.pipe';
import { I18nService } from '../../core/i18n.service';
import { BrandHeaderComponent } from '../../components/brand-header/brand-header.component';
import { EmptyStateComponent } from '../../components/empty-state/empty-state.component';
import { SelectComponent, SelectOption } from '../../components/select/select.component';
import { SegmentComponent } from '../../components/segment/segment.component';
import { SearchComponent } from '../../components/search/search.component';
import { ProgressComponent } from '../../components/progress/progress.component';

type Tab = 'mine' | 'catalogue';

@Component({
  selector: 'app-courses',
  standalone: true,
  imports: [
    IonContent, IonIcon, IonSkeletonText,
    IonRippleEffect, IonRefresher, IonRefresherContent, BrandHeaderComponent,
    EmptyStateComponent, SelectComponent, SegmentComponent, SearchComponent, ProgressComponent,
    TranslatePipe,
  ],
  template: `
    <ion-content [fullscreen]="true">
      <ion-refresher slot="fixed" (ionRefresh)="reload($event)">
        <ion-refresher-content></ion-refresher-content>
      </ion-refresher>

      <app-brand-header [title]="'nav.courses' | translate" [subtitle]="'courses.subtitle' | translate"></app-brand-header>

      <div class="ca-controls">
        <app-segment [value]="tab()" (valueChange)="switch($any($event))" [segments]="tabs()"></app-segment>
        <app-search
          class="ca-search"
          [placeholder]="'courses.search_placeholder' | translate"
          (search)="onSearch($event)"></app-search>
        @if (tab() === 'catalogue' && categoryOptions().length > 2) {
          <app-select
            class="ca-filter"
            [(value)]="category"
            [options]="categoryOptions()"
            [label]="'courses.category' | translate"
            [placeholder]="'courses.all_categories' | translate"
            [searchPlaceholder]="'courses.search_categories' | translate"></app-select>
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
        } @else if (failed()) {
          <app-empty-state
            icon="cloud-offline-outline"
            [title]="'courses.load_failed' | translate"
            [message]="'common.offline' | translate"
            [actionLabel]="'common.retry' | translate"
            (action)="reload()"></app-empty-state>
        } @else if (tab() === 'mine') {
          @if (mine().length) {
            @for (c of mine(); track c.id) {
              <div class="row ion-activatable tappable rise" (click)="open(c.courseId)">
                <ion-ripple-effect></ion-ripple-effect>
                <div class="thumb" [style]="thumb(c.courseTitle || '')"><span>{{ initial(c.courseTitle || '') }}</span></div>
                <div class="body">
                  <p class="title">{{ c.courseTitle }}</p>
                  <app-progress [value]="c.progressPercentage / 100"></app-progress>
                  <p class="muted">{{ c.statusLabel }} · {{ c.progressPercentage }}%</p>
                </div>
                <ion-icon name="chevron-forward" class="chev"></ion-icon>
              </div>
            }
          } @else {
            <app-empty-state
              icon="library-outline"
              [title]="'courses.none_yet' | translate"
              [message]="'courses.none_yet_msg' | translate"
              [actionLabel]="'courses.explore_catalogue' | translate"
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
                    {{ c.categoryName || ('courses.course' | translate) }}{{ mins(c.durationMinutes) ? ' · ' + mins(c.durationMinutes) : '' }}{{ c.level ? ' · ' + c.level : '' }}
                  </p>
                </div>
                <ion-icon name="chevron-forward" class="chev"></ion-icon>
              </div>
            }
          } @else {
            <app-empty-state
              icon="search"
              [title]="query() || category() ? ('courses.no_matches' | translate) : ('courses.nothing_here' | translate)"
              [message]="emptyMessage()"
              [actionLabel]="category() ? ('courses.clear_category' | translate) : ''"
              (action)="category.set('')"></app-empty-state>
          }
        }
      </div>
    </ion-content>
  `,
  styles: [`
    .ca-controls { padding: 16px 16px 2px; display: grid; gap: 10px; }
    .ca-search { display: block; }
    .ca-filter { display: block; }
    .pad { padding: 10px 16px calc(16px + env(safe-area-inset-bottom)); }
    .row { position: relative; overflow: hidden; display: flex; align-items: center; gap: 13px; background: var(--app-surface); border: var(--app-card-border); border-radius: var(--app-r); padding: 12px; margin-bottom: 10px; box-shadow: var(--app-shadow); }
    .row-skeleton { box-shadow: none; }
    .sk-thumb { width: 54px; height: 54px; border-radius: var(--app-r-sm); flex: 0 0 auto; }
    .thumb { width: 54px; height: 54px; border-radius: var(--app-r-sm); display: grid; place-items: center; flex: 0 0 auto; }
    .thumb span { color: #fff; font-weight: 800; font-size: 1.3rem; }
    .body { flex: 1; min-width: 0; }
    .title { margin: 0 0 6px; font-weight: 600; font-size: .95rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .body .muted { margin: 6px 0 0; font-size: .78rem; }
    .chev { color: var(--ion-color-medium); flex: 0 0 auto; }
  `],
})
export class CoursesPage implements OnInit {
  private readonly learner = inject(LearnerService);
  private readonly router = inject(Router);
  private readonly i18n = inject(I18nService);

  readonly tabs = computed(() => {
    this.i18n.lang();
    return [
      { value: 'mine', label: this.i18n.t('actions.my_courses') },
      { value: 'catalogue', label: this.i18n.t('courses.catalogue') },
    ];
  });

  readonly tab = signal<Tab>('mine');
  readonly query = signal('');
  readonly category = signal('');
  readonly loading = signal(true);
  readonly failed = signal(false);
  readonly mine = signal<Enrollment[]>([]);
  readonly catalogue = signal<CatalogueCourse[]>([]);

  /** The catalogue's distinct categories, as select options ("All" first). */
  readonly categoryOptions = computed<SelectOption[]>(() => {
    this.i18n.lang();
    const names = Array.from(
      new Set(this.catalogue().map((c) => c.categoryName).filter((n): n is string => !!n)),
    ).sort((a, b) => a.localeCompare(b));
    return [{ value: '', label: this.i18n.t('courses.all_categories') }, ...names.map((n) => ({ value: n, label: n }))];
  });

  /** The catalogue narrowed to the chosen category (client-side). */
  readonly visibleCatalogue = computed(() => {
    const cat = this.category();
    return cat ? this.catalogue().filter((c) => c.categoryName === cat) : this.catalogue();
  });

  readonly emptyMessage = computed(() => {
    this.i18n.lang();
    if (this.category()) {
      return this.query()
        ? this.i18n.t('courses.empty_in_category_matching', { category: this.category(), query: this.query() })
        : this.i18n.t('courses.empty_in_category', { category: this.category() });
    }
    return this.query()
      ? this.i18n.t('courses.empty_matching', { query: this.query() })
      : this.i18n.t('courses.empty_none_available');
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
        tap((ev) => { if (!ev) { this.loading.set(true); } this.failed.set(false); }),
        switchMap((ev) => {
          const q = this.query().trim() || undefined;
          const tab = this.tab();
          const req: Observable<Enrollment[] | CatalogueCourse[]> =
            tab === 'mine' ? this.learner.myEnrollments(undefined, q) : this.learner.catalogue(q);
          return req.pipe(
            map((rows) => ({ ev, tab, rows, error: false })),
            catchError(() => of({ ev, tab, rows: [] as Enrollment[] | CatalogueCourse[], error: true })),
          );
        }),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe(({ ev, tab, rows, error }) => {
        this.failed.set(error);
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
