import { CommonModule } from '@angular/common';
import {
  ChangeDetectionStrategy, Component, DestroyRef, OnDestroy, OnInit,
  computed, inject, signal,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { LiveTrainingService } from '../live-training.service';
import { TranslatePipe } from '../../translate.pipe';
import { I18nService } from '../../i18n.service';

/**
 * The in-app class. When a session is hosted on the LMS's own video (Agora),
 * this is where it happens: everyone joins one channel, sees and hears each
 * other, and — over the signalling layer — chats, raises a hand and sees who
 * else is here. The heavy SDKs are imported lazily so they only load when a
 * class actually starts.
 *
 * The SDK objects are deliberately loosely typed: the room degrades gracefully
 * (video is the floor; chat/roster are best-effort), and a signalling hiccup
 * must never take the call down.
 */
interface RoomToken {
  appId: string;
  channel: string;
  uid: string;
  displayName: string;
  rtcToken: string;
  rtmToken: string;
  role: 'host' | 'audience';
  isHost: boolean;
  expiresInSeconds: number;
  sessionTitle: string;
}

interface Participant {
  uid: string;
  name: string;
  isLocal: boolean;
  isHost: boolean;
  hasVideo: boolean;
  hasAudio: boolean;
  handUp: boolean;
}

interface ChatMessage {
  id: number;
  from: string;
  name: string;
  text: string;
  at: number;
  mine: boolean;
}

@Component({
  selector: 'app-live-room',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [CommonModule, FormsModule, TranslatePipe],
  templateUrl: './live-room.component.html',
  styleUrl: './live-room.component.scss',
})
export class LiveRoomComponent implements OnInit, OnDestroy {
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly api = inject(LiveTrainingService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly i18n = inject(I18nService);

  readonly phase = signal<'loading' | 'live' | 'left' | 'error'>('loading');
  readonly errorMsg = signal('');
  readonly sessionTitle = signal(this.i18n.t('live.room.default_title'));
  readonly isHost = signal(false);

  readonly micOn = signal(true);
  readonly camOn = signal(true);
  readonly sharing = signal(false);
  readonly handUp = signal(false);

  // Whether we captured a local mic / camera. Either can be false when the
  // device is missing or the learner denied permission — the class then runs
  // view-only rather than dropping them out of it.
  readonly hasMic = signal(true);
  readonly hasCam = signal(true);
  readonly viewOnly = computed(() => !this.hasMic() && !this.hasCam());

  readonly panel = signal<'none' | 'chat' | 'people'>('none');
  readonly participants = signal<Participant[]>([]);
  readonly messages = signal<ChatMessage[]>([]);
  readonly unread = signal(0);
  readonly chatReady = signal(false);
  chatDraft = '';

  readonly people = computed(() => this.participants());
  readonly count = computed(() => this.participants().length);
  readonly handsUp = computed(() => this.participants().filter((p) => p.handUp).length);

  private sessionId = '';
  private token: RoomToken | null = null;

  // Loosely typed SDK handles — see the class docblock.
  private rtc: any = null;
  private client: any = null;
  private micTrack: any = null;
  private camTrack: any = null;
  private screenTrack: any = null;
  private rtm: any = null;
  private readonly remotes = new Map<string, any>();
  private msgSeq = 0;
  private renewTimer: ReturnType<typeof setTimeout> | null = null;

  ngOnInit(): void {
    this.sessionId = this.route.snapshot.paramMap.get('sessionId') ?? '';
    if (!this.sessionId) {
      this.fail(this.i18n.t('live.room.err_missing_session'));
      return;
    }
    void this.join();
  }

  ngOnDestroy(): void {
    void this.teardown();
  }

  // --- lifecycle ----------------------------------------------------------

  private async join(): Promise<void> {
    let token: RoomToken;
    try {
      token = await firstValueFrom(this.api.getRoomToken(this.sessionId));
    } catch (e: unknown) {
      this.fail(this.reasonFor(e));
      return;
    }
    this.token = token;
    this.sessionTitle.set(token.sessionTitle || this.i18n.t('live.room.default_title'));
    this.isHost.set(token.isHost);

    try {
      const [{ default: AgoraRTC }, rtmMod] = await Promise.all([
        import('agora-rtc-sdk-ng'),
        import('agora-rtm'),
      ]);
      this.rtc = AgoraRTC;
      await this.startRtc(AgoraRTC, token);
      // Signalling is best-effort — a class without chat is still a class.
      this.startRtm((rtmMod as any).default, token).catch(() => this.chatReady.set(false));
      // Tokens are short-lived; refresh before they expire so nobody is dropped
      // mid-class.
      this.scheduleTokenRenewal();
      this.phase.set('live');
    } catch (e: unknown) {
      this.fail(this.i18n.t('live.room.err_start_video'));
      // eslint-disable-next-line no-console
      console.error('[live-room] rtc start failed', e);
      await this.teardown();
    }
  }

  private async startRtc(AgoraRTC: any, token: RoomToken): Promise<void> {
    const client = AgoraRTC.createClient({ mode: 'rtc', codec: 'vp8' });
    this.client = client;

    client.on('user-published', async (user: any, mediaType: string) => {
      try {
        await client.subscribe(user, mediaType);
      } catch {
        return;
      }
      this.remotes.set(String(user.uid), user);
      if (mediaType === 'audio') {
        user.audioTrack?.play();
      }
      this.upsertRemote(user);
      if (mediaType === 'video') {
        this.playInto(String(user.uid), user.videoTrack);
      }
    });
    client.on('user-unpublished', (user: any, mediaType: string) => {
      if (mediaType === 'video') {
        this.patch(String(user.uid), { hasVideo: false });
      } else {
        this.patch(String(user.uid), { hasAudio: false });
      }
    });
    client.on('user-left', (user: any) => {
      this.remotes.delete(String(user.uid));
      this.participants.update((list) => list.filter((p) => p.uid !== String(user.uid)));
    });
    // The SDK's own early warning — renew immediately rather than waiting for
    // the timer, whichever fires first.
    client.on('token-privilege-will-expire', () => { void this.renewTokens(); });

    // A failed join is a real error (bad token, no network) — let it throw to
    // the caller, which shows the error screen.
    await client.join(token.appId, token.channel, token.rtcToken, token.uid);

    // Mic and camera are best-effort. A learner who denies permission or has no
    // device still joins to watch, listen and use chat, rather than being
    // ejected from the class — so capture each independently and publish
    // whatever we got, if anything.
    const toPublish: any[] = [];
    try {
      this.micTrack = await AgoraRTC.createMicrophoneAudioTrack();
      toPublish.push(this.micTrack);
    } catch {
      this.micTrack = null;
      this.hasMic.set(false);
      this.micOn.set(false);
    }
    try {
      this.camTrack = await AgoraRTC.createCameraVideoTrack();
      toPublish.push(this.camTrack);
    } catch {
      this.camTrack = null;
      this.hasCam.set(false);
      this.camOn.set(false);
    }
    if (toPublish.length > 0) {
      // Staying in the channel matters more than publishing — swallow a publish
      // failure and remain connected as a viewer.
      try { await client.publish(toPublish); } catch { /* view-only */ }
    }

    this.participants.set([{
      uid: token.uid,
      name: token.displayName + this.i18n.t('live.room.you_suffix'),
      isLocal: true,
      isHost: token.isHost,
      hasVideo: !!this.camTrack,
      hasAudio: !!this.micTrack,
      handUp: false,
    }]);
    if (this.camTrack) { this.playInto('local', this.camTrack); }
  }

  private async startRtm(AgoraRTM: any, token: RoomToken): Promise<void> {
    const rtm = new AgoraRTM.RTM(token.appId, token.uid);
    this.rtm = rtm;

    rtm.addEventListener('message', (e: any) => this.onRtmMessage(e));
    rtm.addEventListener('presence', (e: any) => this.onRtmPresence(e));

    // The token belongs on login(), not the constructor — agora-rtm 2.x ignores
    // a constructor token, so login() would send app-id-only and the server
    // rejects it (DYNAMIC_ENABLED_BUT_STATIC_KEY), silently killing chat.
    await rtm.login({ token: token.rtmToken });
    await rtm.subscribe(token.channel, { withMessage: true, withPresence: true });
    // Announce who we are so remote tiles can show a name, not a user id.
    try {
      await rtm.presence.setState(token.channel, 'MESSAGE', { name: token.displayName, hand: 'false' });
    } catch { /* presence is optional polish */ }
    this.chatReady.set(true);
  }

  // --- controls -----------------------------------------------------------

  async toggleMic(): Promise<void> {
    if (!this.micTrack) { return; }
    const on = !this.micOn();
    await this.micTrack.setEnabled(on);
    this.micOn.set(on);
    this.patch(this.localUid(), { hasAudio: on });
  }

  async toggleCam(): Promise<void> {
    if (!this.camTrack || this.sharing()) { return; }
    const on = !this.camOn();
    await this.camTrack.setEnabled(on);
    this.camOn.set(on);
    this.patch(this.localUid(), { hasVideo: on });
  }

  async toggleShare(): Promise<void> {
    if (!this.client || !this.rtc) { return; }
    if (this.sharing()) {
      await this.stopShare();
      return;
    }
    try {
      const screen = await this.rtc.createScreenVideoTrack({}, 'disable');
      const track = Array.isArray(screen) ? screen[0] : screen;
      if (this.camTrack) { await this.client.unpublish(this.camTrack); }
      this.screenTrack = track;
      // A browser "Stop sharing" ends it outside our UI — react to it.
      track.on?.('track-ended', () => void this.stopShare());
      await this.client.publish(track);
      this.sharing.set(true);
      this.playInto('local', track);
    } catch {
      // Cancelled the picker, or no permission — nothing to do.
    }
  }

  private async stopShare(): Promise<void> {
    if (this.screenTrack) {
      try { await this.client.unpublish(this.screenTrack); } catch { /* already gone */ }
      this.screenTrack.stop?.();
      this.screenTrack.close?.();
      this.screenTrack = null;
    }
    if (this.camTrack && this.camOn()) {
      try { await this.client.publish(this.camTrack); } catch { /* */ }
      this.playInto('local', this.camTrack);
    }
    this.sharing.set(false);
  }

  async toggleHand(): Promise<void> {
    const up = !this.handUp();
    this.handUp.set(up);
    this.patch(this.localUid(), { handUp: up });
    if (this.rtm && this.token) {
      try {
        await this.rtm.presence.setState(this.token.channel, 'MESSAGE', { hand: up ? 'true' : 'false' });
      } catch { /* best-effort */ }
    }
  }

  async lowerAllHands(): Promise<void> {
    if (!this.rtm || !this.token) { return; }
    try {
      await this.rtm.publish(this.token.channel, JSON.stringify({ t: 'lowerHands' }), { channelType: 'MESSAGE' });
    } catch { /* */ }
    this.handUp.set(false);
    this.participants.update((list) => list.map((p) => ({ ...p, handUp: false })));
  }

  openPanel(which: 'chat' | 'people'): void {
    this.panel.set(this.panel() === which ? 'none' : which);
    if (which === 'chat') { this.unread.set(0); }
  }

  async sendMessage(): Promise<void> {
    const text = this.chatDraft.trim();
    if (!text || !this.rtm || !this.token) { return; }
    this.chatDraft = '';
    this.appendMessage(this.token.uid, this.token.displayName, text, true);
    try {
      await this.rtm.publish(this.token.channel, JSON.stringify({ t: 'chat', text, name: this.token.displayName }), { channelType: 'MESSAGE' });
    } catch { /* the local echo already showed it */ }
  }

  async leave(): Promise<void> {
    await this.teardown();
    this.phase.set('left');
  }

  backToTraining(): void {
    void this.router.navigate(['/live-training']);
  }

  // --- signalling handlers ------------------------------------------------

  private onRtmMessage(e: any): void {
    let payload: any;
    try { payload = JSON.parse(String(e?.message ?? '')); } catch { return; }
    const from = String(e?.publisher ?? '');
    if (payload?.t === 'chat' && typeof payload.text === 'string') {
      this.appendMessage(from, payload.name || from, payload.text, false);
    } else if (payload?.t === 'lowerHands') {
      this.handUp.set(false);
      this.participants.update((list) => list.map((p) => ({ ...p, handUp: false })));
    }
  }

  private onRtmPresence(e: any): void {
    // Snapshot on join, then join/leave/state-changed. Shapes vary by SDK
    // build, so read everything defensively.
    const applyState = (uid: string, state: any) => {
      if (!uid || uid === this.localUid()) { return; }
      const patch: Partial<Participant> = {};
      if (state?.name) { patch.name = String(state.name); }
      if (state?.hand !== undefined) { patch.handUp = String(state.hand) === 'true'; }
      this.patch(uid, patch);
    };
    try {
      const snapshot = e?.snapshot ?? e?.states;
      if (Array.isArray(snapshot)) {
        for (const s of snapshot) { applyState(String(s?.userId ?? s?.publisher ?? ''), s?.states ?? s?.stateChanged); }
      }
      if (e?.publisher) { applyState(String(e.publisher), e?.stateChanged ?? e?.states); }
    } catch { /* presence is optional */ }
  }

  // --- helpers ------------------------------------------------------------

  private upsertRemote(user: any): void {
    const uid = String(user.uid);
    const known = this.participants().find((p) => p.uid === uid);
    if (known) {
      this.patch(uid, { hasVideo: !!user.videoTrack, hasAudio: !!user.audioTrack });
      return;
    }
    this.participants.update((list) => [...list, {
      uid,
      name: uid,
      isLocal: false,
      isHost: false,
      hasVideo: !!user.videoTrack,
      hasAudio: !!user.audioTrack,
      handUp: false,
    }]);
  }

  private patch(uid: string, patch: Partial<Participant>): void {
    this.participants.update((list) => list.map((p) => (p.uid === uid ? { ...p, ...patch } : p)));
  }

  private appendMessage(from: string, name: string, text: string, mine: boolean): void {
    this.messages.update((list) => [...list, { id: ++this.msgSeq, from, name, text, at: Date.now(), mine }]);
    if (!mine && this.panel() !== 'chat') { this.unread.update((n) => n + 1); }
  }

  /** Play a track into a tile's container once Angular has rendered it. */
  private playInto(uid: string, track: any): void {
    if (!track) { return; }
    setTimeout(() => {
      try { track.play('tile-' + uid); } catch { /* element not ready */ }
    }, 60);
  }

  private localUid(): string {
    return this.token?.uid ?? 'local';
  }

  tileId(p: Participant): string {
    return 'tile-' + (p.isLocal ? 'local' : p.uid);
  }

  initial(name: string): string {
    return (name.trim()[0] ?? '?').toUpperCase();
  }

  private fail(message: string): void {
    this.errorMsg.set(message);
    this.phase.set('error');
  }

  private reasonFor(e: unknown): string {
    const err = e as { code?: string; message?: string; status?: number };
    if (err?.code === 'agora_unconfigured') {
      return this.i18n.t('live.room.err_unconfigured');
    }
    if (err?.code === 'not_in_app_session') {
      return this.i18n.t('live.room.err_not_in_app');
    }
    if (err?.code === 'forbidden' || err?.status === 403) {
      return this.i18n.t('live.room.err_forbidden');
    }
    if (err?.code === 'not_found' || err?.status === 404) {
      return this.i18n.t('live.room.err_not_found');
    }
    return err?.message || this.i18n.t('live.room.err_generic');
  }

  /** Renew the RTC + RTM tokens a minute before they expire, then reschedule. */
  private scheduleTokenRenewal(): void {
    if (this.renewTimer) { clearTimeout(this.renewTimer); this.renewTimer = null; }
    const ttl = this.token?.expiresInSeconds ?? 0;
    if (ttl <= 0) { return; }
    const delayMs = Math.max(10, ttl - 60) * 1000;
    this.renewTimer = setTimeout(() => { void this.renewTokens(); }, delayMs);
  }

  private async renewTokens(): Promise<void> {
    let fresh: RoomToken;
    try {
      fresh = await firstValueFrom(this.api.getRoomToken(this.sessionId));
    } catch {
      // A transient failure should not drop the participant — try again soon.
      if (this.renewTimer) { clearTimeout(this.renewTimer); }
      this.renewTimer = setTimeout(() => { void this.renewTokens(); }, 15000);
      return;
    }
    this.token = fresh;
    try { await this.client?.renewToken(fresh.rtcToken); } catch { /* */ }
    try { await this.rtm?.renewToken(fresh.rtmToken); } catch { /* */ }
    this.scheduleTokenRenewal();
  }

  private async teardown(): Promise<void> {
    if (this.renewTimer) { clearTimeout(this.renewTimer); this.renewTimer = null; }
    try { await this.stopShare(); } catch { /* */ }
    for (const t of [this.micTrack, this.camTrack]) {
      try { t?.stop?.(); t?.close?.(); } catch { /* */ }
    }
    this.micTrack = this.camTrack = null;
    try { await this.client?.leave(); } catch { /* */ }
    this.client = null;
    this.remotes.clear();
    try { await this.rtm?.logout(); } catch { /* */ }
    this.rtm = null;
  }
}
