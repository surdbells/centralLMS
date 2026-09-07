import { Component, DestroyRef, OnInit, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Router } from '@angular/router';
import { Observable, Subject, of } from 'rxjs';
import { catchError, switchMap, tap } from 'rxjs/operators';
import { IonContent, IonIcon, IonSkeletonText, IonRefresher, IonRefresherContent } from '@ionic/angular/standalone';
import { LearningPathService, PathEnrolment, PathSummary } from '../../core/learning-path.service';
import { BrandHeaderComponent } from '../../components/brand-header/brand-header.component';
import { EmptyStateComponent } from '../../components/empty-state/empty-state.component';
import { SegmentComponent } from '../../components/segment/segment.component';
import { SearchComponent } from '../../components/search/search.component';
import { ProgressComponent } from '../../components/progress/progress.component';
import { TranslatePipe } from '../../core/translate.pipe';
import { I18nService } from '../../core/i18n.service';

type Tab = 'mine' | 'catalogue';

@Component({
  selector: 'app-paths',
  standalone: true,
  imports: [
    IonContent, IonIcon, IonSkeletonText, IonRefresher, IonRefresherContent,
    BrandHeaderComponent, EmptyStateComponent, SegmentComponent, SearchComponent, ProgressComponent, TranslatePipe,
  ],
  template: `
    <ion-content [fullscreen]="true">
      <ion-refresher slot="fixed" (ionRefresh)="reload($event)">
        <ion-refresher-content></ion-refresher-content>
      </ion-refresher>

      <app-brand-header [title]="'paths.title' | translate" [subtitle]="'paths.subtitle' | translate" back></app-brand-header>

      <div class="ctrls">
        <app-segment [value]="tab()" (valueChange)="switch($any($event))" [segments]="tabs"></app-segment>
        @if (tab() === 'catalogue') {
          <app-search [placeholder]="'paths.search' | translate" (search)="onSearch($event)"></app-search>
        }
      </div>

      <div class="pad">
        @if (loading()) {
          @for (i of [1,2,3]; track i) {
            <ion-skeleton-text animated style="width:100%; height:96px; border-radius:16px; margin-bottom:12px;"></ion-skeleton-text>
          }
        } @else if (failed()) {
          <app-empty-state icon="cloud-offline-outline" [title]="'paths.load_failed' | translate"
            [message]="'common.offline' | translate" [actionLabel]="'common.retry' | translate" (action)="reload()"></app-empty-state>
        } @else if (tab() === 'mine') {
          @if (mine().length) {
            @for (e of mine(); track e.id) {
              <button type="button" class="path tappable rise" (click)="open(e.pathId)">
                <div class="path-top">
                  <span class="path-title">{{ e.pathTitle }}</span>
                  <span class="path-status" [class.done]="e.progress.isComplete">{{ e.progress.isComplete ? ('paths.status.completed' | translate) : statusLabel(e.status) }}</span>
                </div>
                <app-progress [value]="e.progress.percent / 100"></app-progress>
                <p class="path-meta">{{ 'paths.progress_meta' | translate:{ completed: e.progress.completed, total: e.progress.total, percent: e.progress.percent } }}</p>
              </button>
            }
          } @else {
            <app-empty-state icon="trail-sign-outline" [title]="'paths.no_paths_title' | translate"
              [message]="'paths.mine_empty_message' | translate"
              [actionLabel]="'paths.browse_catalogue' | translate" (action)="switch('catalogue')"></app-empty-state>
          }
        } @else {
          @if (catalogue().length) {
            @for (p of catalogue(); track p.id) {
              <button type="button" class="path path-cat tappable rise" (click)="open(p.id)">
                <div class="path-icon"><ion-icon name="trail-sign-outline" aria-hidden="true"></ion-icon></div>
                <div class="path-body">
                  <div class="path-chips">
                    @if (p.level) { <span class="chip">{{ levelLabel(p.level) }}</span> }
                    @if (p.categoryName) { <span class="chip ghost">{{ p.categoryName }}</span> }
                  </div>
                  <p class="path-title">{{ p.title }}</p>
                  <p class="path-meta">{{ catMeta(p) }}</p>
                </div>
                <ion-icon name="chevron-forward" class="path-chev" aria-hidden="true"></ion-icon>
              </button>
            }
          } @else {
            <app-empty-state icon="trail-sign-outline"
              [title]="(query() ? 'paths.no_matches' : 'paths.no_paths_title') | translate"
              [message]="query() ? ('paths.no_matches_message' | translate:{ query: query() }) : ('paths.catalogue_empty_message' | translate)"></app-empty-state>
          }
        }
      </div>
    </ion-content>
  `,
  styles: [`
    .ctrls { padding: 16px 16px 2px; display: grid; gap: 10px; }
    .pad { padding: 12px 16px calc(16px + env(safe-area-inset-bottom)); }
    .path { display: block; width: 100%; text-align: left; background: var(--app-surface); border: var(--app-card-border); border-radius: var(--app-r); box-shadow: var(--app-shadow); padding: 14px; margin-bottom: 12px; cursor: pointer; }

    /* Enrolled (mine) card */
    .path-top { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 10px; }
    .path-title { font-weight: 700; font-size: .98rem; color: var(--app-ink); }
    .path-status { flex: none; font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--app-muted); background: var(--app-field); padding: 3px 8px; border-radius: 999px; }
    .path-status.done { color: var(--ion-color-success); background: color-mix(in srgb, var(--ion-color-success) 15%, transparent); }
    .path-meta { margin: 8px 0 0; font-size: .78rem; color: var(--app-muted); }

    /* Catalogue card */
    .path-cat { display: flex; align-items: center; gap: 13px; }
    .path-icon { flex: 0 0 auto; width: 46px; height: 46px; border-radius: var(--app-r-sm); display: grid; place-items: center; background: var(--app-tag-bg); color: var(--app-tag-ink); }
    .path-icon ion-icon { font-size: 24px; }
    .path-body { flex: 1; min-width: 0; }
    .path-chips { display: flex; gap: 6px; margin-bottom: 4px; }
    .chip { font-size: .64rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--app-tag-ink); background: var(--app-tag-bg); padding: 2px 7px; border-radius: 999px; }
    .chip.ghost { color: var(--app-muted); background: var(--app-field); }
    .path-chev { flex: none; color: var(--app-muted); font-size: 18px; }
  `],
})
export class PathsPage implements OnInit {
  private readonly paths = inject(LearningPathService);
  private readonly router = inject(Router);
  private readonly destroyRef = inject(DestroyRef);
  private readonly i18n = inject(I18nService);

  readonly tabs = [
    { value: 'mine', label: this.i18n.t('paths.tab_mine') },
    { value: 'catalogue', label: this.i18n.t('paths.tab_catalogue') },
  ];

  readonly tab = signal<Tab>('mine');
  readonly query = signal('');
  readonly loading = signal(true);
  readonly failed = signal(false);
  readonly mine = signal<PathEnrolment[]>([]);
  readonly catalogue = signal<PathSummary[]>([]);

  private readonly load$ = new Subject<CustomEvent | undefined>();

  ngOnInit(): void {
    this.load$
      .pipe(
        tap((ev) => { if (!ev) { this.loading.set(true); } this.failed.set(false); }),
        switchMap((ev) => {
          const tab = this.tab();
          const req: Observable<Array<PathEnrolment | PathSummary>> =
            tab === 'mine' ? this.paths.mine() : this.paths.catalogue(this.query().trim() || undefined);
          return req.pipe(
            tap({ error: () => this.failed.set(true) }),
            catchError(() => of([] as PathEnrolment[] | PathSummary[])),
            tap(() => (ev?.target as { complete?: () => void })?.complete?.()),
            tap((rows) => {
              if (tab === 'mine') { this.mine.set(rows as PathEnrolment[]); }
              else { this.catalogue.set(rows as PathSummary[]); }
            }),
          );
        }),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe(() => this.loading.set(false));

    this.load$.next(undefined);
  }

  switch(tab: Tab): void {
    if (tab && tab !== this.tab()) {
      this.tab.set(tab);
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

  open(pathId: string): void {
    void this.router.navigate(['/paths', pathId]);
  }

  statusLabel(s: string): string {
    const map: Record<string, string> = {
      active: 'paths.status.active', pending: 'paths.status.pending', completed: 'paths.status.completed',
      expired: 'paths.status.expired', withdrawn: 'paths.status.withdrawn',
    };
    const key = map[s];
    return key ? this.i18n.t(key) : s;
  }

  levelLabel(l: string): string {
    const map: Record<string, string> = {
      beginner: 'paths.level.beginner', intermediate: 'paths.level.intermediate', advanced: 'paths.level.advanced',
      expert: 'paths.level.expert', all_levels: 'paths.level.all_levels',
    };
    const key = map[l];
    return key ? this.i18n.t(key) : l;
  }

  catMeta(p: PathSummary): string {
    const parts = [`${p.itemCount} ${this.i18n.t(p.itemCount === 1 ? 'paths.item' : 'paths.items')}`];
    if (p.estimatedHours) { parts.push(this.i18n.t('paths.hours_short', { hours: p.estimatedHours })); }
    if (p.enrolmentCount) { parts.push(this.i18n.t('paths.enrolled_count', { count: p.enrolmentCount })); }
    return parts.join(' · ');
  }
}
