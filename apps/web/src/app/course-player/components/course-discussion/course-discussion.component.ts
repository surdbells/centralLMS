// course-player/components/course-discussion/course-discussion.component.ts

import { Component, EventEmitter, Input, OnDestroy, OnInit, Output } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Subject } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import { HotToastService } from '@ngneat/hot-toast';
import {
  CoursePlayerService,
  DiscussionPost,
  ReactionKind,
} from '../../services/course-player.service';
import { SessionStore } from '../../../core/auth/session.store';
import { TranslatePipe } from '../../../translate.pipe';
import { I18nService } from '../../../i18n.service';
import { RichHtmlEditorComponent } from '../../../shared/rich-html-editor/rich-html-editor.component';
import { AvatarComponent } from '../../../shared/avatar/avatar.component';
import { ConfirmDialogService } from '../../../shared/components/confirmation-dialog/confirmation-dialog.component';

/**
 * The course forum, as a self-contained unit: reactions, accepted answers,
 * pinning and locking, rich-text posts, @mentions, moderation and search.
 *
 * It takes only a course id and (optionally) the lesson being viewed, so the
 * same forum renders inside the course player's Discussion tab (under the
 * content) and on the standalone Discussions page — one implementation, two
 * homes.
 */
@Component({
  selector: 'app-course-discussion',
  standalone: true,
  imports: [CommonModule, FormsModule, TranslatePipe, RichHtmlEditorComponent, AvatarComponent],
  templateUrl: './course-discussion.component.html',
  styleUrl: './course-discussion.component.scss',
})
export class CourseDiscussionComponent implements OnInit, OnDestroy {
  /** The course whose forum this is. */
  @Input({ required: true }) courseId = '';
  /** The lesson in view, if any — lets a new post be tagged to it and the
   *  "this lesson" filter work. Null on the standalone page. */
  @Input() currentLessonId: string | null = null;
  /** The number of threads, each time the forum (re)loads — the player shows
   *  it on its Discussion tab. */
  @Output() countChange = new EventEmitter<number>();

  private destroy$ = new Subject<void>();

  discussion: DiscussionPost[] = [];
  discussionLoaded = false;
  newDiscussionPost = '';
  replyingTo: string | null = null;
  replyText = '';
  currentUserId = '';
  editingPostId: string | null = null;
  editText = '';
  discussionSearch = '';
  discussLessonOnly = false;
  readonly reactionKinds: ReactionKind[] = ['up', 'heart', 'celebrate', 'insightful'];
  readonly reactionEmoji: Record<ReactionKind, string> = {
    up: '👍', heart: '❤️', celebrate: '🎉', insightful: '💡',
  };

  constructor(
    private playerService: CoursePlayerService,
    private session: SessionStore,
    private toast: HotToastService,
    private i18n: I18nService,
    private dialog: ConfirmDialogService,
  ) {}

  ngOnInit(): void {
    this.currentUserId = this.session.userId() ?? '';
    this.loadDiscussion();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  private loadDiscussion(): void {
    if (!this.courseId) return;
    this.playerService.getDiscussion(this.courseId)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (posts) => {
          this.discussion = posts;
          this.discussionLoaded = true;
          this.countChange.emit(posts.length);
        },
        error: () => { this.discussion = []; this.discussionLoaded = true; },
      });
  }

  submitDiscussionPost(): void {
    const text = this.newDiscussionPost.trim();
    if (!text || !this.courseId) return;

    this.playerService
      .postToDiscussion(this.courseId, text, null, this.currentLessonId)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => { this.newDiscussionPost = ''; this.loadDiscussion(); },
        error: (err) => this.toast.error(err?.message || this.i18n.t('course_player.toast_post_failed')),
      });
  }

  startReply(postId: string): void {
    this.replyingTo = this.replyingTo === postId ? null : postId;
    this.replyText = '';
  }

  submitReply(parentId: string): void {
    const text = this.replyText.trim();
    if (!text || !this.courseId) return;

    this.playerService
      .postToDiscussion(this.courseId, text, parentId, this.currentLessonId)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => { this.replyingTo = null; this.replyText = ''; this.loadDiscussion(); },
        error: (err) => this.toast.error(err?.message || this.i18n.t('course_player.toast_reply_failed')),
      });
  }

  deleteDiscussionPost(postId: string): void {
    if (!this.courseId) return;
    this.playerService.deleteDiscussionPost(this.courseId, postId)
      .pipe(takeUntil(this.destroy$))
      .subscribe({ next: () => this.loadDiscussion() });
  }

  // --- premium forum: reactions, best answer, moderation, inline edit --------

  /** A moderator (course author/instructor) may pin, lock and accept answers. */
  get canModerate(): boolean {
    return this.session.can()('course.publish');
  }

  /** The visible threads, after the free-text search and the lesson filter. */
  get visibleDiscussion(): DiscussionPost[] {
    const q = this.discussionSearch.trim().toLowerCase();
    const lessonId = this.discussLessonOnly ? this.currentLessonId : null;

    return this.discussion.filter((post) => {
      if (lessonId && post.lessonId !== lessonId) {
        return false;
      }
      if (!q) {
        return true;
      }
      if (post.body.toLowerCase().includes(q) || post.authorName.toLowerCase().includes(q)) {
        return true;
      }
      return (post.replies ?? []).some(
        (r) => r.body.toLowerCase().includes(q) || r.authorName.toLowerCase().includes(q),
      );
    });
  }

  /**
   * A post body with @mentions wrapped for styling. The body is already
   * sanitized HTML (server + Angular), and this only wraps @tokens that fall
   * in text, never inside a tag, so Angular's binding sanitizer keeps it safe.
   */
  bodyHtml(body: string): string {
    if (!body.includes('@')) return body;
    return body.replace(
      /(<[^>]+>)|(?<![\w@])@[\p{L}][\p{L}0-9._-]*/gu,
      (match, tag) => (tag ? tag : `<span class="cp-mention">${match}</span>`),
    );
  }

  hasReacted(post: DiscussionPost, kind: ReactionKind): boolean {
    return (post.myReactions ?? []).includes(kind);
  }

  reactionCount(post: DiscussionPost, kind: ReactionKind): number {
    return post.reactions?.[kind] ?? 0;
  }

  /** Toggle a reaction, updating the counts in place so the UI feels instant. */
  toggleReaction(post: DiscussionPost, kind: ReactionKind): void {
    if (!this.courseId) return;

    const mine = new Set(post.myReactions ?? []);
    const reactions = { ...(post.reactions ?? {}) };
    const had = mine.has(kind);

    // Optimistic: flip locally first.
    if (had) {
      mine.delete(kind);
      reactions[kind] = Math.max(0, (reactions[kind] ?? 1) - 1);
      if (reactions[kind] === 0) delete reactions[kind];
    } else {
      mine.add(kind);
      reactions[kind] = (reactions[kind] ?? 0) + 1;
    }
    post.myReactions = [...mine];
    post.reactions = reactions;

    this.playerService.reactToPost(this.courseId, post.id, kind)
      .pipe(takeUntil(this.destroy$))
      .subscribe({ error: () => this.loadDiscussion() });
  }

  /** The question's author, or a moderator, may accept one reply as the answer. */
  canAcceptAnswer(thread: DiscussionPost): boolean {
    return this.canModerate || thread.authorId === this.currentUserId;
  }

  markAnswer(reply: DiscussionPost): void {
    if (!this.courseId) return;
    this.playerService.markAnswer(this.courseId, reply.id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => this.loadDiscussion(),
        error: (err) => this.toast.error(err?.message || this.i18n.t('course_player.toast_post_failed')),
      });
  }

  togglePin(post: DiscussionPost): void {
    if (!this.courseId) return;
    this.playerService.pinPost(this.courseId, post.id, !post.isPinned)
      .pipe(takeUntil(this.destroy$))
      .subscribe({ next: () => this.loadDiscussion() });
  }

  toggleLock(post: DiscussionPost): void {
    if (!this.courseId) return;
    this.playerService.lockThread(this.courseId, post.id, !post.isLocked)
      .pipe(takeUntil(this.destroy$))
      .subscribe({ next: () => this.loadDiscussion() });
  }

  canEditPost(post: DiscussionPost): boolean {
    return this.canModerate || post.authorId === this.currentUserId;
  }

  startEdit(post: DiscussionPost): void {
    this.editingPostId = post.id;
    this.editText = post.body;
  }

  cancelEdit(): void {
    this.editingPostId = null;
    this.editText = '';
  }

  saveEdit(post: DiscussionPost): void {
    const text = this.editText.trim();
    if (!text || !this.courseId) return;

    this.playerService.editDiscussionPost(this.courseId, post.id, text)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => { this.cancelEdit(); this.loadDiscussion(); },
        error: (err) => this.toast.error(err?.message || this.i18n.t('course_player.toast_post_failed')),
      });
  }

  /** You can report anyone's post but your own. */
  canReport(post: DiscussionPost): boolean {
    return post.authorId !== this.currentUserId && !post.isHidden;
  }

  async reportPost(post: DiscussionPost): Promise<void> {
    if (!this.courseId) return;
    const reason = await this.dialog.prompt(this.i18n.t('course_player.report_prompt'), {
      confirmText: this.i18n.t('course_player.report'),
    });
    if (reason === null) return; // cancelled

    this.playerService.reportPost(this.courseId, post.id, reason)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => this.toast.success(this.i18n.t('course_player.report_thanks')),
        error: (err) => this.toast.error(err?.message || this.i18n.t('course_player.toast_post_failed')),
      });
  }

  toggleHide(post: DiscussionPost): void {
    if (!this.courseId) return;
    this.playerService.hidePost(this.courseId, post.id, !post.isHidden)
      .pipe(takeUntil(this.destroy$))
      .subscribe({ next: () => this.loadDiscussion() });
  }

  dismissReports(post: DiscussionPost): void {
    if (!this.courseId) return;
    this.playerService.dismissReports(this.courseId, post.id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({ next: () => this.loadDiscussion() });
  }
}
