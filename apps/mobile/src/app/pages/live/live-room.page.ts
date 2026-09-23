import {
  ChangeDetectionStrategy, Component, OnDestroy, OnInit,
  computed, inject, signal,
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { IonIcon, IonSpinner } from '@ionic/angular/standalone';
import { firstValueFrom } from 'rxjs';
import { LiveService, RoomToken } from '../../core/live.service';
import { TranslatePipe } from '../../core/translate.pipe';
import { I18nService } from '../../core/i18n.service';

/**
 * The in-app live class on mobile — a full-screen room over Agora's browser
 * SDKs, running inside the Capacitor WebView. Same shape as the web room:
 * video is the floor, and chat / roster / raise-hand over the signalling layer
 * are best-effort so a hiccup there never drops the call. The SDKs load lazily
 * so they only cost anything when a class actually starts.
 *
 * Native builds need camera + microphone permissions declared in the (un-
 * committed) iOS/Android projects — NSCameraUsageDescription / NSMicrophone-
 * UsageDescription and CAMERA / RECORD_AUDIO — for getUserMedia to work.
 */
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
  name: string;
  text: string;
  mine: boolean;
}

@Component({
  selector: 'app-live-room',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [CommonModule, FormsModule, IonIcon, IonSpinner, TranslatePipe],
  template: `
    @if (phase() === 'loading') {
      <div class="status"><ion-spinner name="crescent"></ion-spinner><p>{{ 'live.joining' | translate }}</p></div>
    } @else if (phase() === 'error') {
      <div class="status">
        <ion-icon name="videocam-off-outline"></ion-icon>
        <p>{{ errorMsg() }}</p>
        <button class="ghost" (click)="back()">{{ 'common.back' | translate }}</button>
      </div>
    } @else if (phase() === 'left') {
      <div class="status">
        <ion-icon name="checkmark-circle-outline"></ion-icon>
        <p>{{ 'live.you_left' | translate }}</p>
        <button class="ghost" (click)="back()">{{ 'common.back' | translate }}</button>
      </div>
    } @else {
      <div class="room">
        <header class="bar">
          <span class="dot"></span>
          <span class="title">{{ sessionTitle() }}</span>
          @if (viewOnly()) { <span class="view-only"><ion-icon name="eye-outline"></ion-icon> {{ 'live.view_only' | translate }}</span> }
          <span class="meta"><ion-icon name="people"></ion-icon> {{ count() }}</span>
        </header>

        <div class="grid" [attr.data-n]="count() > 6 ? 'many' : count()">
          @for (p of people(); track p.uid) {
            <div class="tile" [class.local]="p.isLocal">
              <div class="video" [id]="tileId(p)"></div>
              @if (!p.hasVideo) { <div class="avatar">{{ initial(p.name) }}</div> }
              @if (p.handUp) { <span class="hand"><ion-icon name="hand-left"></ion-icon></span> }
              <div class="label">
                @if (!p.hasAudio) { <ion-icon name="mic-off" class="muted"></ion-icon> }
                <span class="name">{{ p.name }}</span>
                @if (p.isHost) { <span class="host">{{ 'live.host' | translate }}</span> }
              </div>
            </div>
          }
        </div>

        @if (panel()) {
          <div class="sheet">
            <div class="sheet-tabs">
              <button [class.on]="panel() === 'chat'" (click)="openPanel('chat')">{{ 'live.chat' | translate }}</button>
              <button [class.on]="panel() === 'people'" (click)="panel.set('people')">{{ 'live.people_count' | translate: { count: count() } }}</button>
              <button class="close" (click)="panel.set(null)" [attr.aria-label]="'common.close' | translate"><ion-icon name="close"></ion-icon></button>
            </div>
            @if (panel() === 'chat') {
              <div class="log">
                @if (!chatReady()) { <p class="hint">{{ 'live.chat_connecting' | translate }}</p> }
                @for (m of messages(); track m.id) {
                  <div class="msg" [class.mine]="m.mine">
                    @if (!m.mine) { <span class="msg-name">{{ m.name }}</span> }
                    <span>{{ m.text }}</span>
                  </div>
                } @empty { @if (chatReady()) { <p class="hint">{{ 'live.no_messages' | translate }}</p> } }
              </div>
              <form class="input" (ngSubmit)="send()">
                <input [(ngModel)]="draft" name="draft" [placeholder]="'live.message_placeholder' | translate" [disabled]="!chatReady()" autocomplete="off" />
                <button type="submit" [disabled]="!chatReady() || !draft.trim()" [attr.aria-label]="'live.send' | translate"><ion-icon name="send"></ion-icon></button>
              </form>
            } @else {
              <ul class="plist">
                @for (p of people(); track p.uid) {
                  <li>
                    <span class="pav">{{ initial(p.name) }}</span>
                    <span class="pname">{{ p.name }} @if (p.isHost) { <em>{{ 'live.host' | translate }}</em> }</span>
                    @if (p.handUp) { <ion-icon name="hand-left" class="ph"></ion-icon> }
                    <ion-icon [name]="p.hasAudio ? 'mic' : 'mic-off'" class="pm" [class.off]="!p.hasAudio"></ion-icon>
                  </li>
                }
              </ul>
              @if (isHost() && handsUp() > 0) { <button class="lower" (click)="lowerAllHands()">{{ 'live.lower_all_hands' | translate }}</button> }
            }
          </div>
        }

        <footer class="controls">
          <button (click)="toggleMic()" [class.off]="!micOn()" [disabled]="!hasMic()"><ion-icon [name]="micOn() ? 'mic' : 'mic-off'"></ion-icon></button>
          <button (click)="toggleCam()" [class.off]="!camOn()" [disabled]="sharing() || !hasCam()"><ion-icon [name]="camOn() ? 'videocam' : 'videocam-off'"></ion-icon></button>
          <button (click)="toggleShare()" [class.on]="sharing()"><ion-icon name="desktop-outline"></ion-icon></button>
          <button (click)="toggleHand()" [class.on]="handUp()"><ion-icon name="hand-left"></ion-icon></button>
          <button (click)="openPanel('chat')" [class.on]="panel() === 'chat'">
            <ion-icon name="chatbubble-ellipses"></ion-icon>
            @if (unread() > 0) { <b class="badge">{{ unread() }}</b> }
          </button>
          <button (click)="openPanel('people')" [class.on]="panel() === 'people'"><ion-icon name="people"></ion-icon></button>
          <button class="leave" (click)="leave()"><ion-icon name="exit-outline"></ion-icon></button>
        </footer>
      </div>
    }
  `,
  styles: [`
    :host { position: fixed; inset: 0; z-index: 200; display: block; background: #0e1116; color: #e9edf3;
      font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; }
    .status { height: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center;
      gap: 16px; text-align: center; padding: 24px calc(24px + env(safe-area-inset-left)); }
    .status ion-icon { font-size: 3rem; color: #6b7688; }
    .status p { margin: 0; max-width: 380px; line-height: 1.5; color: #c4cbd6; }
    .status .ghost { border: 1px solid #2a3342; background: transparent; color: #e9edf3; border-radius: 999px; padding: 10px 22px; font-weight: 600; }

    .room { height: 100%; display: flex; flex-direction: column; }
    .bar { flex: none; display: flex; align-items: center; gap: 9px; padding: calc(env(safe-area-inset-top) + 10px) 16px 10px;
      border-bottom: 1px solid #1c2230; }
    .bar .dot { width: 8px; height: 8px; border-radius: 50%; background: #e0665f; flex: none; animation: pulse 2s infinite; }
    .bar .title { font-weight: 600; flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .bar .meta { display: flex; align-items: center; gap: 5px; color: #9aa4b4; font-size: .9rem; flex: none; }
    .bar .view-only { display: inline-flex; align-items: center; gap: 4px; flex: none; color: #cbd2dc;
      background: rgba(255,255,255,.09); border-radius: 999px; padding: 3px 9px; font-size: .74rem; }
    .bar .view-only ion-icon { font-size: 13px; }
    @keyframes pulse { 50% { opacity: .4; } }

    .grid { flex: 1; display: grid; gap: 8px; padding: 10px; align-content: center; grid-template-columns: 1fr; min-height: 0; overflow-y: auto; }
    .grid[data-n='2'], .grid[data-n='3'], .grid[data-n='4'] { grid-template-columns: repeat(2, 1fr); }
    .grid[data-n='5'], .grid[data-n='6'], .grid[data-n='many'] { grid-template-columns: repeat(2, 1fr); align-content: start; }

    .tile { position: relative; overflow: hidden; border-radius: 14px; background: #171d28; border: 1px solid #232b3a; aspect-ratio: 3 / 4; min-height: 0; }
    .tile.local { border-color: #6f9bff; }
    .video { position: absolute; inset: 0; }
    .video :is(video, div) { object-fit: cover !important; }
    .avatar { position: absolute; inset: 0; display: grid; place-items: center; font-size: 2rem; font-weight: 700; color: #fff;
      background: radial-gradient(120% 120% at 50% 0%, #2c3547, #171d28); }
    .hand { position: absolute; top: 8px; left: 8px; width: 28px; height: 28px; border-radius: 50%; display: grid; place-items: center;
      background: rgba(240,178,60,.92); color: #4a3200; }
    .hand ion-icon { font-size: 16px; }
    .label { position: absolute; left: 0; right: 0; bottom: 0; display: flex; align-items: center; gap: 6px; padding: 14px 10px 7px;
      font-size: .78rem; background: linear-gradient(180deg, transparent, rgba(0,0,0,.62)); }
    .label .name { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .label .muted { color: #e0665f; flex: none; }
    .label .host { flex: none; font-size: .58rem; font-weight: 700; text-transform: uppercase; padding: 2px 6px; border-radius: 999px; background: #2f6fed; color: #fff; }

    .sheet { position: absolute; left: 0; right: 0; bottom: 78px; top: 30%; z-index: 5; background: #121722;
      border-top: 1px solid #232b3a; border-radius: 18px 18px 0 0; display: flex; flex-direction: column; }
    .sheet-tabs { display: flex; align-items: center; flex: none; border-bottom: 1px solid #1c2230; }
    .sheet-tabs button { flex: 1; padding: 13px; border: 0; background: transparent; color: #9aa4b4; font-weight: 600; border-bottom: 2px solid transparent; }
    .sheet-tabs button.on { color: #fff; border-bottom-color: #2f6fed; }
    .sheet-tabs .close { flex: none; width: 48px; color: #9aa4b4; }
    .log { flex: 1; overflow-y: auto; padding: 12px; display: flex; flex-direction: column; gap: 8px; }
    .hint { color: #6b7688; font-size: .85rem; text-align: center; margin: auto 0; }
    .msg { max-width: 88%; padding: 7px 11px; border-radius: 12px; background: #1c2431; align-self: flex-start; font-size: .88rem; line-height: 1.4; word-break: break-word; }
    .msg.mine { align-self: flex-end; background: #2f6fed; color: #fff; }
    .msg-name { display: block; font-size: .7rem; font-weight: 700; color: #7f8ba0; margin-bottom: 1px; }
    .input { flex: none; display: flex; gap: 8px; padding: 10px calc(10px + env(safe-area-inset-right)) 10px calc(10px + env(safe-area-inset-left)); border-top: 1px solid #1c2230; }
    .input input { flex: 1; background: #0e1116; border: 1px solid #2a3342; color: #e9edf3; border-radius: 999px; padding: 10px 14px; outline: 0; font-size: .95rem; }
    .input button { flex: none; width: 42px; border: 0; border-radius: 50%; background: #2f6fed; color: #fff; }
    .input button:disabled { opacity: .5; }
    .plist { flex: 1; overflow-y: auto; list-style: none; margin: 0; padding: 8px; }
    .plist li { display: flex; align-items: center; gap: 10px; padding: 10px 8px; }
    .pav { flex: none; width: 34px; height: 34px; border-radius: 50%; display: grid; place-items: center; background: #2c3547; font-weight: 700; font-size: .85rem; }
    .pname { flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .pname em { font-style: normal; font-size: .62rem; font-weight: 700; text-transform: uppercase; color: #6f9bff; margin-left: 6px; }
    .ph { color: #f0b23c; }
    .pm { color: #6b7688; } .pm.off { color: #e0665f; }
    .lower { flex: none; margin: 10px; padding: 10px; border: 0; border-radius: 10px; background: rgba(240,178,60,.16); color: #f0b23c; font-weight: 600; }

    .controls { flex: none; display: flex; justify-content: center; gap: 10px; padding: 12px 12px calc(12px + env(safe-area-inset-bottom)); border-top: 1px solid #1c2230; }
    .controls button { position: relative; width: 50px; height: 50px; border: 0; border-radius: 50%; background: #1c2431; color: #e9edf3; display: grid; place-items: center; }
    .controls button ion-icon { font-size: 22px; }
    .controls button.off ion-icon { color: #e0665f; }
    .controls button.on { background: #2f6fed; color: #fff; }
    .controls button:disabled { opacity: .5; }
    .controls .leave { background: #e0665f; color: #fff; }
    .badge { position: absolute; top: -2px; right: -2px; min-width: 18px; height: 18px; padding: 0 4px; border-radius: 999px;
      background: #e0665f; color: #fff; font-size: .62rem; font-weight: 700; display: grid; place-items: center; }
  `],
})
export class LiveRoomPage implements OnInit, OnDestroy {
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly live = inject(LiveService);
  private readonly i18n = inject(I18nService);

  readonly phase = signal<'loading' | 'live' | 'left' | 'error'>('loading');
  readonly errorMsg = signal('');
  readonly sessionTitle = signal(this.i18n.t('live.default_title'));
  readonly isHost = signal(false);
  readonly micOn = signal(true);
  readonly camOn = signal(true);
  readonly sharing = signal(false);
  readonly handUp = signal(false);
  // Whether we captured a local mic / camera. Either can be false when the
  // device is missing or permission was denied — the class then runs view-only
  // rather than dropping the learner out of it.
  readonly hasMic = signal(true);
  readonly hasCam = signal(true);
  readonly viewOnly = computed(() => !this.hasMic() && !this.hasCam());
  readonly panel = signal<'chat' | 'people' | null>(null);
  readonly participants = signal<Participant[]>([]);
  readonly messages = signal<ChatMessage[]>([]);
  readonly unread = signal(0);
  readonly chatReady = signal(false);
  draft = '';

  readonly people = computed(() => this.participants());
  readonly count = computed(() => this.participants().length);
  readonly handsUp = computed(() => this.participants().filter((p) => p.handUp).length);

  private sessionId = '';
  private token: RoomToken | null = null;
  private rtc: any = null;
  private client: any = null;
  private micTrack: any = null;
  private camTrack: any = null;
  private screenTrack: any = null;
  private rtm: any = null;
  private msgSeq = 0;
  private renewTimer: ReturnType<typeof setTimeout> | null = null;

  ngOnInit(): void {
    this.sessionId = this.route.snapshot.paramMap.get('sessionId') ?? '';
    if (!this.sessionId) { this.fail(this.i18n.t('live.err_missing_session')); return; }
    void this.join();
  }

  ngOnDestroy(): void {
    void this.teardown();
  }

  private async join(): Promise<void> {
    let token: RoomToken;
    try {
      token = await firstValueFrom(this.live.roomToken(this.sessionId));
    } catch (e: unknown) {
      this.fail(this.reasonFor(e));
      return;
    }
    this.token = token;
    this.sessionTitle.set(token.sessionTitle || this.i18n.t('live.default_title'));
    this.isHost.set(token.isHost);

    try {
      const [{ default: AgoraRTC }, rtmMod] = await Promise.all([
        import('agora-rtc-sdk-ng'),
        import('agora-rtm'),
      ]);
      this.rtc = AgoraRTC;
      await this.startRtc(AgoraRTC, token);
      this.startRtm((rtmMod as any).default, token).catch(() => this.chatReady.set(false));
      this.scheduleTokenRenewal();
      this.phase.set('live');
    } catch (e: unknown) {
      this.fail(this.i18n.t('live.err_no_video'));
      // eslint-disable-next-line no-console
      console.error('[live-room] rtc start failed', e);
      await this.teardown();
    }
  }

  private async startRtc(AgoraRTC: any, token: RoomToken): Promise<void> {
    const client = AgoraRTC.createClient({ mode: 'rtc', codec: 'vp8' });
    this.client = client;

    client.on('user-published', async (user: any, mediaType: string) => {
      try { await client.subscribe(user, mediaType); } catch { return; }
      if (mediaType === 'audio') { user.audioTrack?.play(); }
      this.upsertRemote(user);
      if (mediaType === 'video') { this.playInto(String(user.uid), user.videoTrack); }
    });
    client.on('user-unpublished', (user: any, mediaType: string) => {
      this.patch(String(user.uid), mediaType === 'video' ? { hasVideo: false } : { hasAudio: false });
    });
    client.on('user-left', (user: any) => {
      this.participants.update((list) => list.filter((p) => p.uid !== String(user.uid)));
    });
    client.on('token-privilege-will-expire', () => { void this.renewTokens(); });

    // A failed join is a real error (bad token, no network) — let it throw.
    await client.join(token.appId, token.channel, token.rtcToken, token.uid);

    // Mic and camera are best-effort: a learner who denies permission or has no
    // device still joins to watch, listen and use chat rather than being
    // ejected. Capture each independently and publish whatever we got.
    const toPublish: any[] = [];
    try {
      this.micTrack = await AgoraRTC.createMicrophoneAudioTrack();
      toPublish.push(this.micTrack);
    } catch {
      this.micTrack = null; this.hasMic.set(false); this.micOn.set(false);
    }
    try {
      this.camTrack = await AgoraRTC.createCameraVideoTrack();
      toPublish.push(this.camTrack);
    } catch {
      this.camTrack = null; this.hasCam.set(false); this.camOn.set(false);
    }
    if (toPublish.length > 0) {
      try { await client.publish(toPublish); } catch { /* stay connected, view-only */ }
    }

    this.participants.set([{
      uid: token.uid, name: `${token.displayName} (${this.i18n.t('live.you')})`, isLocal: true, isHost: token.isHost,
      hasVideo: !!this.camTrack, hasAudio: !!this.micTrack, handUp: false,
    }]);
    if (this.camTrack) { this.playInto('local', this.camTrack); }
  }

  private async startRtm(AgoraRTM: any, token: RoomToken): Promise<void> {
    const rtm = new AgoraRTM.RTM(token.appId, token.uid);
    this.rtm = rtm;
    rtm.addEventListener('message', (e: any) => this.onMessage(e));
    rtm.addEventListener('presence', (e: any) => this.onPresence(e));
    // agora-rtm 2.x ignores a constructor token; it must go to login(), else the
    // server rejects it (DYNAMIC_ENABLED_BUT_STATIC_KEY) and chat never connects.
    await rtm.login({ token: token.rtmToken });
    await rtm.subscribe(token.channel, { withMessage: true, withPresence: true });
    try { await rtm.presence.setState(token.channel, 'MESSAGE', { name: token.displayName, hand: 'false' }); } catch { /* */ }
    this.chatReady.set(true);
  }

  // --- controls ---
  async toggleMic(): Promise<void> {
    if (!this.micTrack) { return; }
    const on = !this.micOn();
    await this.micTrack.setEnabled(on); this.micOn.set(on);
    this.patch(this.localUid(), { hasAudio: on });
  }

  async toggleCam(): Promise<void> {
    if (!this.camTrack || this.sharing()) { return; }
    const on = !this.camOn();
    await this.camTrack.setEnabled(on); this.camOn.set(on);
    this.patch(this.localUid(), { hasVideo: on });
  }

  async toggleShare(): Promise<void> {
    if (!this.client || !this.rtc) { return; }
    if (this.sharing()) { await this.stopShare(); return; }
    try {
      const screen = await this.rtc.createScreenVideoTrack({}, 'disable');
      const track = Array.isArray(screen) ? screen[0] : screen;
      if (this.camTrack) { await this.client.unpublish(this.camTrack); }
      this.screenTrack = track;
      track.on?.('track-ended', () => void this.stopShare());
      await this.client.publish(track);
      this.sharing.set(true);
      this.playInto('local', track);
    } catch { /* cancelled / unsupported */ }
  }

  private async stopShare(): Promise<void> {
    if (this.screenTrack) {
      try { await this.client.unpublish(this.screenTrack); } catch { /* */ }
      this.screenTrack.stop?.(); this.screenTrack.close?.(); this.screenTrack = null;
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
      try { await this.rtm.presence.setState(this.token.channel, 'MESSAGE', { hand: up ? 'true' : 'false' }); } catch { /* */ }
    }
  }

  async lowerAllHands(): Promise<void> {
    if (this.rtm && this.token) {
      try { await this.rtm.publish(this.token.channel, JSON.stringify({ t: 'lowerHands' }), { channelType: 'MESSAGE' }); } catch { /* */ }
    }
    this.handUp.set(false);
    this.participants.update((list) => list.map((p) => ({ ...p, handUp: false })));
  }

  openPanel(which: 'chat' | 'people'): void {
    this.panel.set(this.panel() === which ? null : which);
    if (which === 'chat') { this.unread.set(0); }
  }

  async send(): Promise<void> {
    const text = this.draft.trim();
    if (!text || !this.rtm || !this.token) { return; }
    this.draft = '';
    this.append(this.token.displayName, text, true);
    try { await this.rtm.publish(this.token.channel, JSON.stringify({ t: 'chat', text, name: this.token.displayName }), { channelType: 'MESSAGE' }); } catch { /* echoed locally */ }
  }

  async leave(): Promise<void> {
    await this.teardown();
    this.phase.set('left');
  }

  back(): void {
    void this.router.navigateByUrl('/tabs/home');
  }

  // --- signalling ---
  private onMessage(e: any): void {
    let p: any;
    try { p = JSON.parse(String(e?.message ?? '')); } catch { return; }
    if (p?.t === 'chat' && typeof p.text === 'string') {
      this.append(p.name || String(e?.publisher ?? ''), p.text, false);
    } else if (p?.t === 'lowerHands') {
      this.handUp.set(false);
      this.participants.update((list) => list.map((x) => ({ ...x, handUp: false })));
    }
  }

  private onPresence(e: any): void {
    const apply = (uid: string, state: any) => {
      if (!uid || uid === this.localUid()) { return; }
      const patch: Partial<Participant> = {};
      if (state?.name) { patch.name = String(state.name); }
      if (state?.hand !== undefined) { patch.handUp = String(state.hand) === 'true'; }
      this.patch(uid, patch);
    };
    try {
      const snap = e?.snapshot ?? e?.states;
      if (Array.isArray(snap)) {
        for (const s of snap) { apply(String(s?.userId ?? s?.publisher ?? ''), s?.states ?? s?.stateChanged); }
      }
      if (e?.publisher) { apply(String(e.publisher), e?.stateChanged ?? e?.states); }
    } catch { /* presence optional */ }
  }

  // --- helpers ---
  private upsertRemote(user: any): void {
    const uid = String(user.uid);
    if (this.participants().some((p) => p.uid === uid)) {
      this.patch(uid, { hasVideo: !!user.videoTrack, hasAudio: !!user.audioTrack });
      return;
    }
    this.participants.update((list) => [...list, {
      uid, name: uid, isLocal: false, isHost: false,
      hasVideo: !!user.videoTrack, hasAudio: !!user.audioTrack, handUp: false,
    }]);
  }

  private patch(uid: string, patch: Partial<Participant>): void {
    this.participants.update((list) => list.map((p) => (p.uid === uid ? { ...p, ...patch } : p)));
  }

  private append(name: string, text: string, mine: boolean): void {
    this.messages.update((list) => [...list, { id: ++this.msgSeq, name, text, mine }]);
    if (!mine && this.panel() !== 'chat') { this.unread.update((n) => n + 1); }
  }

  private playInto(uid: string, track: any): void {
    if (!track) { return; }
    setTimeout(() => { try { track.play('tile-' + uid); } catch { /* not ready */ } }, 60);
  }

  private localUid(): string { return this.token?.uid ?? 'local'; }

  tileId(p: Participant): string { return 'tile-' + (p.isLocal ? 'local' : p.uid); }

  initial(name: string): string { return (name.trim()[0] ?? '?').toUpperCase(); }

  private fail(message: string): void {
    this.errorMsg.set(message);
    this.phase.set('error');
  }

  private reasonFor(e: unknown): string {
    const err = e as { code?: string; message?: string; status?: number };
    if (err?.code === 'agora_unconfigured') {
      return this.i18n.t('live.err_unconfigured');
    }
    if (err?.code === 'not_in_app_session') { return this.i18n.t('live.err_not_in_app'); }
    if (err?.status === 403) { return this.i18n.t('live.err_not_enrolled'); }
    if (err?.status === 404) { return this.i18n.t('live.err_not_found'); }
    return err?.message || this.i18n.t('live.err_join_generic');
  }

  /** Renew the RTC + RTM tokens shortly before expiry so nobody is dropped. */
  private scheduleTokenRenewal(): void {
    if (this.renewTimer) { clearTimeout(this.renewTimer); this.renewTimer = null; }
    const ttl = this.token?.expiresInSeconds ?? 0;
    if (ttl <= 0) { return; }
    this.renewTimer = setTimeout(() => { void this.renewTokens(); }, Math.max(10, ttl - 60) * 1000);
  }

  private async renewTokens(): Promise<void> {
    let fresh: RoomToken;
    try {
      fresh = await firstValueFrom(this.live.roomToken(this.sessionId));
    } catch {
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
    for (const t of [this.micTrack, this.camTrack]) { try { t?.stop?.(); t?.close?.(); } catch { /* */ } }
    this.micTrack = this.camTrack = null;
    try { await this.client?.leave(); } catch { /* */ }
    this.client = null;
    try { await this.rtm?.logout(); } catch { /* */ }
    this.rtm = null;
  }
}
