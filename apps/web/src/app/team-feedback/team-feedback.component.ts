import { Component, OnDestroy, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { HotToastService } from '@ngneat/hot-toast';
import { TranslatePipe } from '../translate.pipe';
import { I18nService } from '../i18n.service';
import { StarRatingComponent } from '../shared/star-rating/star-rating.component';
import { BehaviourFeedbackService, type TeamFeedbackRow } from '../services/behaviour-feedback.service';
import { SkeletonComponent } from '../shared/skeleton/skeleton.component';
import { AvatarComponent } from '../shared/avatar/avatar.component';
import { AdmintopComponent } from '../partials/top/admintop/admintop.component';
import { TopComponent } from '../partials/top/top.component';
import { SessionStore } from '../core/auth/session.store';

type SaveState = 'idle' | 'saving' | 'saved' | 'error';
type Filter = 'all' | 'pending' | 'rated';

/** One report × course row, with its own save lifecycle. */
interface FeedbackItem {
  row: TeamFeedbackRow;
  /** The rating the server is known to hold (from load or the last successful
   *  save). Counts and filters use this, so an unsaved rating is not "rated". */
  persistedRating: number | null;
  state: SaveState;
  /** Shown when a note was typed before a rating was given. */
  needsRating: boolean;
  inFlight: boolean;
  queued: boolean;
  noteTimer: ReturnType<typeof setTimeout> | null;
  savedTimer: ReturnType<typeof setTimeout> | null;
}

interface ReportGroup {
  userId: string;
  name: string;
  avatar: string | null;
  subtitle: string;
  items: FeedbackItem[];
}

/** A group as currently shown: the whole group, and the rows that pass the filter. */
interface VisibleGroup {
  group: ReportGroup;
  items: FeedbackItem[];
}

const NOTE_DEBOUNCE_MS = 900;
const GUIDE_KEY = 'central.teamFeedback.guideCollapsed.';

/**
 * A line manager's behaviour-change feedback (Kirkpatrick L3): their direct
 * reports' completed courses, each rated 1–5 for how much the training changed
 * the person's work, with an optional note. Feeds the admin Analytics "business
 * impact" figure.
 *
 * Line managers are often not administrators, so the page wears the admin bar
 * only for people who can reach administration (same rule the bars use) and
 * the learner bar otherwise.
 */
@Component({
  selector: 'app-team-feedback',
  standalone: true,
  imports: [
    CommonModule, FormsModule, RouterLink, TranslatePipe, StarRatingComponent, SkeletonComponent,
    AvatarComponent, AdmintopComponent, TopComponent,
  ],
  templateUrl: './team-feedback.component.html',
  styleUrls: ['./team-feedback.component.scss'],
})
export class TeamFeedbackComponent implements OnInit, OnDestroy {
  private readonly svc = inject(BehaviourFeedbackService);
  private readonly session = inject(SessionStore);
  private readonly toast = inject(HotToastService);
  private readonly i18n = inject(I18nService);

  /** Same rule as the admin/learner top bars use to offer administration. */
  readonly isAdmin: boolean;
  /** The manager team dashboard runs `report.view` reports. */
  readonly canTeamReport: boolean;
  readonly backLink: string;
  readonly scale = [1, 2, 3, 4, 5];

  loading = true;
  failed = false;
  groups: ReportGroup[] = [];
  /** The groups/rows currently shown — recomputed on filter/search change only,
   *  so a row does not vanish from "Needs rating" the moment it is rated. */
  visible: VisibleGroup[] = [];

  filter: Filter = 'all';
  query = '';
  guideCollapsed = false;

  constructor() {
    const can = this.session.can();
    this.isAdmin = can('user.manage') || can('course.publish') || can('settings.manage');
    this.canTeamReport = can('report.view');
    this.backLink = this.isAdmin ? '/administration' : '/account';
  }

  ngOnInit(): void {
    this.guideCollapsed = this.readGuide();
    this.load();
  }

  ngOnDestroy(): void {
    // Flush any note still waiting on its debounce (and give failed or queued
    // edits one more attempt with their newest values), then drop timers.
    for (const item of this.allItems) {
      const pendingNote = !!item.noteTimer;
      if (item.noteTimer) {
        clearTimeout(item.noteTimer);
        item.noteTimer = null;
      }
      if (item.row.rating && (pendingNote || item.state === 'error' || item.queued)) {
        this.save(item);
      }
      if (item.savedTimer) {
        clearTimeout(item.savedTimer);
      }
    }
  }

  // --- data ---------------------------------------------------------------

  load(): void {
    this.loading = true;
    this.failed = false;

    this.svc.pending().subscribe({
      next: (rows) => {
        this.groups = this.group(rows ?? []);
        this.applyFilters();
        this.loading = false;
      },
      error: () => {
        this.failed = true;
        this.loading = false;
      },
    });
  }

  private group(rows: TeamFeedbackRow[]): ReportGroup[] {
    const byUser = new Map<string, ReportGroup>();

    for (const row of rows) {
      let group = byUser.get(row.user_id);
      if (!group) {
        const parts = [row.job_title, row.department].filter((p): p is string => !!p && p.trim() !== '');
        group = {
          userId: row.user_id,
          name: row.name || '—',
          avatar: row.avatar_url ?? null,
          subtitle: parts.join(' · '),
          items: [],
        };
        byUser.set(row.user_id, group);
      }
      const rating = row.rating ? Number(row.rating) : null;
      group.items.push({
        row: { ...row, rating },
        persistedRating: rating,
        state: 'idle',
        needsRating: false,
        inFlight: false,
        queued: false,
        noteTimer: null,
        savedTimer: null,
      });
    }

    return [...byUser.values()];
  }

  private get allItems(): FeedbackItem[] {
    return this.groups.flatMap((g) => g.items);
  }

  // --- KPIs ---------------------------------------------------------------

  get peopleCount(): number {
    return this.groups.length;
  }

  get pendingCount(): number {
    return this.allItems.filter((i) => !i.persistedRating).length;
  }

  get ratedCount(): number {
    return this.allItems.filter((i) => !!i.persistedRating).length;
  }

  get totalCount(): number {
    return this.allItems.length;
  }

  get averageLabel(): string {
    const rated = this.allItems.filter((i) => !!i.persistedRating);
    if (rated.length === 0) {
      return '—';
    }
    const avg = rated.reduce((sum, i) => sum + (i.persistedRating ?? 0), 0) / rated.length;
    return avg.toFixed(1);
  }

  ratedIn(group: ReportGroup): number {
    return group.items.filter((i) => !!i.persistedRating).length;
  }

  ratedPct(group: ReportGroup): number {
    return group.items.length === 0 ? 0 : Math.round((this.ratedIn(group) / group.items.length) * 100);
  }

  // --- filters ------------------------------------------------------------

  setFilter(filter: Filter): void {
    this.filter = filter;
    this.applyFilters();
  }

  onQuery(): void {
    this.applyFilters();
  }

  clearFilters(): void {
    this.filter = 'all';
    this.query = '';
    this.applyFilters();
  }

  get hasFilters(): boolean {
    return this.filter !== 'all' || this.query.trim() !== '';
  }

  applyFilters(): void {
    const q = this.query.trim().toLowerCase();

    this.visible = this.groups
      .map((g) => {
        const personHit = q !== '' && g.name.toLowerCase().includes(q);
        const items = g.items.filter((i) => {
          if (this.filter === 'pending' && i.persistedRating) return false;
          if (this.filter === 'rated' && !i.persistedRating) return false;
          if (q === '' || personHit) return true;
          return (i.row.course_title ?? '').toLowerCase().includes(q);
        });
        return { group: g, items };
      })
      .filter((g) => g.items.length > 0);
  }

  // --- guide --------------------------------------------------------------

  toggleGuide(): void {
    this.guideCollapsed = !this.guideCollapsed;
    try {
      localStorage.setItem(this.guideKey, this.guideCollapsed ? '1' : '0');
    } catch {
      /* storage unavailable: the choice just isn't remembered */
    }
  }

  private readGuide(): boolean {
    try {
      return localStorage.getItem(this.guideKey) === '1';
    } catch {
      return false;
    }
  }

  private get guideKey(): string {
    return GUIDE_KEY + (this.session.userId() ?? 'anon');
  }

  // --- saving -------------------------------------------------------------

  trackGroup = (_: number, v: VisibleGroup): string => v.group.userId;
  trackItem = (_: number, i: FeedbackItem): string => i.row.user_id + ':' + i.row.course_id;

  rate(item: FeedbackItem, rating: number): void {
    item.row.rating = rating;
    item.needsRating = false;
    if (item.noteTimer) {
      clearTimeout(item.noteTimer);
      item.noteTimer = null;
    }
    this.save(item);
  }

  noteChanged(item: FeedbackItem): void {
    if (!item.row.rating) {
      item.needsRating = (item.row.comments ?? '').trim() !== '';
      return;
    }
    if (item.noteTimer) {
      clearTimeout(item.noteTimer);
    }
    item.noteTimer = setTimeout(() => {
      item.noteTimer = null;
      this.save(item);
    }, NOTE_DEBOUNCE_MS);
  }

  noteBlur(item: FeedbackItem): void {
    if (item.noteTimer) {
      clearTimeout(item.noteTimer);
      item.noteTimer = null;
      this.save(item);
    }
  }

  retry(item: FeedbackItem): void {
    this.save(item);
  }

  private save(item: FeedbackItem): void {
    if (!item.row.rating) {
      return;
    }
    if (item.inFlight) {
      // Send the latest values once the current request settles.
      item.queued = true;
      return;
    }

    item.inFlight = true;
    item.state = 'saving';
    if (item.savedTimer) {
      clearTimeout(item.savedTimer);
      item.savedTimer = null;
    }

    const { user_id, course_id, rating, comments } = item.row;

    this.svc.submit(user_id, course_id, rating, comments).subscribe({
      next: () => {
        item.inFlight = false;
        item.persistedRating = rating;
        if (item.queued) {
          item.queued = false;
          this.save(item);
          return;
        }
        item.state = 'saved';
        item.savedTimer = setTimeout(() => {
          if (item.state === 'saved') {
            item.state = 'idle';
          }
          item.savedTimer = null;
        }, 2500);
      },
      error: () => {
        item.inFlight = false;
        // Retry (or leaving the page) resends the newest values, queued edits included.
        item.queued = false;
        item.state = 'error';
        this.toast.error(
          this.i18n.t('feedback.save_failed_toast', { name: item.row.name, course: item.row.course_title }),
        );
      },
    });
  }
}
