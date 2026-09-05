import { Component, OnInit, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import {
  IonContent, IonHeader, IonToolbar, IonTitle, IonSegment, IonSegmentButton,
  IonLabel, IonSearchbar, IonIcon, IonProgressBar, IonSkeletonText,
  IonRippleEffect, IonRefresher, IonRefresherContent,
} from '@ionic/angular/standalone';
import { LearnerService } from '../../core/learner.service';
import { CatalogueCourse, Enrollment } from '../../core/models';
import { initialOf, minutesLabel, thumbStyle } from '../../core/ui';

type Tab = 'mine' | 'catalogue';

@Component({
  selector: 'app-courses',
  standalone: true,
  imports: [
    IonContent, IonHeader, IonToolbar, IonTitle, IonSegment, IonSegmentButton,
    IonLabel, IonSearchbar, IonIcon, IonProgressBar, IonSkeletonText,
    IonRippleEffect, IonRefresher, IonRefresherContent,
  ],
  template: `
    <ion-header [translucent]="true">
      <ion-toolbar>
        <ion-title>Courses</ion-title>
      </ion-toolbar>
      <ion-toolbar>
        <ion-segment [value]="tab()" (ionChange)="switch($any($event.detail.value))">
          <ion-segment-button value="mine"><ion-label>My Courses</ion-label></ion-segment-button>
          <ion-segment-button value="catalogue"><ion-label>Catalogue</ion-label></ion-segment-button>
        </ion-segment>
      </ion-toolbar>
      <ion-toolbar>
        <ion-searchbar
          [value]="query()"
          placeholder="Search courses"
          [debounce]="300"
          (ionInput)="onSearch($any($event.target).value)"></ion-searchbar>
      </ion-toolbar>
    </ion-header>

    <ion-content [fullscreen]="true">
      <ion-refresher slot="fixed" (ionRefresh)="reload($event)">
        <ion-refresher-content></ion-refresher-content>
      </ion-refresher>

      <div class="pad">
        @if (loading()) {
          @for (i of [1,2,3,4]; track i) {
            <ion-skeleton-text animated style="width:100%; height:72px; border-radius:14px; margin-bottom:10px;"></ion-skeleton-text>
          }
        } @else if (tab() === 'mine') {
          @if (mine().length) {
            @for (c of mine(); track c.id) {
              <div class="row ion-activatable" (click)="open(c.courseId)">
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
            <div class="empty">
              <ion-icon name="library-outline"></ion-icon>
              <p>You're not enrolled in anything yet.</p>
              <a (click)="switch('catalogue')">Explore the catalogue →</a>
            </div>
          }
        } @else {
          @if (catalogue().length) {
            @for (c of catalogue(); track c.id) {
              <div class="row ion-activatable" (click)="open(c.id)">
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
            <div class="empty">
              <ion-icon name="search"></ion-icon>
              <p>No courses{{ query() ? ' match “' + query() + '”' : ' available' }}.</p>
            </div>
          }
        }
      </div>
    </ion-content>
  `,
  styles: [`
    .pad { padding: 12px 16px calc(16px + env(safe-area-inset-bottom)); }
    .row { position: relative; overflow: hidden; display: flex; align-items: center; gap: 12px; background: var(--ion-card-background); border-radius: 14px; padding: 12px; margin-bottom: 10px; box-shadow: 0 4px 14px rgba(0,0,0,.05); }
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
  readonly loading = signal(true);
  readonly mine = signal<Enrollment[]>([]);
  readonly catalogue = signal<CatalogueCourse[]>([]);

  readonly thumb = thumbStyle;
  readonly initial = initialOf;
  readonly mins = minutesLabel;

  ngOnInit(): void {
    this.load();
  }

  switch(tab: Tab): void {
    if (tab && tab !== this.tab()) {
      this.tab.set(tab);
      this.load();
    }
  }

  onSearch(value: string): void {
    this.query.set(value ?? '');
    this.load();
  }

  reload(ev?: CustomEvent): void {
    this.load(ev);
  }

  private load(ev?: CustomEvent): void {
    const q = this.query().trim() || undefined;
    if (!ev) {
      this.loading.set(true);
    }
    const done = () => {
      this.loading.set(false);
      (ev?.target as { complete?: () => void })?.complete?.();
    };
    if (this.tab() === 'mine') {
      this.learner.myEnrollments(undefined, q).subscribe({
        next: (rows) => { this.mine.set(rows); done(); },
        error: () => { this.mine.set([]); done(); },
      });
    } else {
      this.learner.catalogue(q).subscribe({
        next: (rows) => { this.catalogue.set(rows); done(); },
        error: () => { this.catalogue.set([]); done(); },
      });
    }
  }

  open(courseId: string): void {
    void this.router.navigate(['/courses', courseId]);
  }
}
