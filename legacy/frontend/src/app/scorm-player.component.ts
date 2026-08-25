import {Component, EventEmitter, Input, OnDestroy, OnInit, Output} from '@angular/core';
import {DomSanitizer, SafeResourceUrl} from '@angular/platform-browser';

export interface ScormPlayerConfig {
  url: string;
  tenantId: number;
  userId: number;
  courseId: number;
  token: string; // <-- Added token
}

export interface ScormProgress {
  completionStatus: string;
  successStatus: string;
  score: number;
  suspendData: string;
  sessionTime: number;
}

class ScormAPI {
  private data: Record<string, string> = {};
  private startTime = Date.now();
  private backendUrl = 'https://api.centrallms.com/scorm-sync';
  private tenantId: number;
  private userId: number;
  private courseId: number;
  private token: string;

  constructor(tenantId: number, userId: number, courseId: number, token: string) {
    this.tenantId = tenantId;
    this.userId = userId;
    this.courseId = courseId;
    this.token = token;
  }

  /* ---------------- SCORM 1.2 ---------------- */
  LMSInitialize = () => 'true';
  LMSFinish = () => { this.saveTime(); return 'true'; };
  LMSGetValue = (key: string) => this.data[key] || '';
  LMSSetValue = (key: string, value: string) => { this.data[key] = value; return 'true'; };
  LMSCommit = () => { this.syncToBackend(); return 'true'; };

  /* ---------------- SCORM 2004 ---------------- */
  Initialize = () => 'true';
  Terminate = () => { this.saveTime(); return 'true'; };
  GetValue = (key: string) => this.data[key] || '';
  SetValue = (key: string, value: string) => { this.data[key] = value; return 'true'; };
  Commit = () => { this.syncToBackend(); return 'true'; };

  /* ---------------- Tracking & Helpers ---------------- */
  public restoreSuspendData(suspendData?: string) {
    if (suspendData) this.data['cmi.suspend_data'] = suspendData;
  }

  public getSuspendData(): string {
    return this.data['cmi.suspend_data'] || '';
  }

  public markComplete(passed: boolean = true) {
    this.data['cmi.core.lesson_status'] = passed ? 'completed' : 'incomplete';
    this.data['cmi.completion_status'] = passed ? 'completed' : 'incomplete';
    this.data['cmi.success_status'] = passed ? 'passed' : 'failed';
  }

  public setScore(score: number) {
    this.data['cmi.core.score.raw'] = score.toString();
    this.data['cmi.score.raw'] = score.toString();
  }

  private saveTime() {
    const elapsed = Math.floor((Date.now() - this.startTime) / 1000);
    this.data['cmi.core.session_time'] = elapsed.toString();
    this.data['cmi.session_time'] = elapsed.toString();
  }

  private syncToBackend() {
    fetch(this.backendUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        tenant_id: this.tenantId,
        user_id: this.userId,
        course_id: this.courseId,
        token: this.token,
        data: this.data
      })
    }).catch(console.error);
  }
}

@Component({
  selector: 'app-scorm-player',
  template: `
    <div class="scorm-container">
      <iframe [src]="safeUrl" width="100%" height="100%" allowfullscreen></iframe>
      <div class="controls">
        <button (click)="toggleFullscreen()">Fullscreen</button>
        <div class="progress-bar">
          <div class="progress" [style.width.%]="progress"></div>
        </div>
      </div>
    </div>
  `,
  standalone: true,
  styles: [`
    :host {
      display: block;
      width: 100%;
      height: 70vh;
      position: relative;
    }

    iframe {
      border: none;
      width: 100%;
      height: 100%;
    }

    .controls {
      position: absolute;
      bottom: 5px;
      left: 5px;
      width: 200px;
    }

    .progress-bar {
      width: 100%;
      height: 8px;
      background: #eee;
      margin-top: 5px;
    }

    .progress {
      height: 100%;
      background: #10b981;
      width: 0;
      transition: width 0.3s;
    }
  `]
})
export class ScormPlayerComponent implements OnInit, OnDestroy {

  @Input() config!: ScormPlayerConfig;
  @Output() progressChange = new EventEmitter<ScormProgress>();

  safeUrl!: SafeResourceUrl;
  private api!: ScormAPI;
  progress = 0;

  constructor(private sanitizer: DomSanitizer) {}

  async ngOnInit() {
    // Initialize SCORM API
    this.api = new ScormAPI(this.config.tenantId, this.config.userId, this.config.courseId, this.config.token);
    (window as any).API = this.api;
    (window as any).API_1484_11 = this.api;

    // Preload suspend data from backend
    const suspendData = await this.fetchSuspendData();
    this.api.restoreSuspendData(suspendData);

    // Trust iframe URL
    this.safeUrl = this.sanitizer.bypassSecurityTrustResourceUrl(this.config.url);

    // Auto-commit on unload
    window.addEventListener('beforeunload', this.handleUnload);

    // Start tracking progress
    this.startProgressTracking();
  }

  ngOnDestroy() {
    this.handleUnload();
    window.removeEventListener('beforeunload', this.handleUnload);
  }

  private handleUnload = () => {
    if (this.api) this.api.LMSCommit?.();
    if (this.api) this.api.Commit?.();
  };

  private async fetchSuspendData(): Promise<string> {
    try {
      const res = await fetch('https://api.centrallms.com/scorm-get', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          tenant_id: this.config.tenantId,
          user_id: this.config.userId,
          course_id: this.config.courseId,
          token: this.config.token
        })
      });
      const json = await res.json();
      return json.suspend_data || '';
    } catch {
      return '';
    }
  }

  private startProgressTracking() {
    setInterval(() => {
      this.progress = this.api ? (this.api['data']['cmi.core.lesson_status'] === 'completed' ? 100 : 0) : 0;
      this.progressChange.emit({
        completionStatus: this.api['data']['cmi.core.lesson_status'] || 'incomplete',
        successStatus: this.api['data']['cmi.success_status'] || 'unknown',
        score: Number(this.api['data']['cmi.core.score.raw'] || 0),
        suspendData: this.api.getSuspendData(),
        sessionTime: Number(this.api['data']['cmi.core.session_time'] || 0)
      });
    }, 2000);
  }

  toggleFullscreen() {
    const iframe = document.querySelector('iframe') as HTMLElement;
    if (!iframe) return;
    if (iframe.requestFullscreen) iframe.requestFullscreen().then(r => console.log(r));
    else if ((iframe as any).webkitRequestFullscreen) (iframe as any).webkitRequestFullscreen();
    else if ((iframe as any).msRequestFullscreen) (iframe as any).msRequestFullscreen();
  }

  public markComplete(passed: boolean = true) { this.api.markComplete(passed); }
  public setScore(score: number) { this.api.setScore(score); }
  public getSuspendData(): string { return this.api.getSuspendData(); }
}
