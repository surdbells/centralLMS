// course-player/services/scorm-adapter.service.ts

import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Subject, BehaviorSubject } from 'rxjs';
import { debounceTime } from 'rxjs/operators';
import {GlobalComponent} from '../../global-component';

/**
 * SCORM 1.2 and SCORM 2004 API Adapter
 * Provides communication between SCORM content and the LMS
 */

export interface ScormData {
  [key: string]: string | number | boolean;
}

export interface ScormCommitResult {
  success: boolean;
  error?: string;
}

@Injectable({
  providedIn: 'root'
})
export class ScormAdapterService {
  private apiUrl = GlobalComponent.coursePlayer;
  session_data: any = ""
  user_session = {
    id: 0,
    tenant_id: 0,
    tenant: {
      id: 0,
      name: "",
      status: "",
    },
    token: "",
    first_name: "",
    last_name: "",
    email: "",
    phone: "",
    avatar: "",
    role: "superadmin",
    is_2fa: false,
    is_active: false,
    account_locked: false,
    is_admin: false,
    is_content: false,
    is_instructor: false,
    is_learner: false,
  };
  // SCORM Data Store
  private cmiData: ScormData = {};
  private isDirty = false;

  // Context
  private enrollmentId = 0;
  private lessonId = 0;
  private userId = 0;
  private userName =" ";
  private tenantId = 0;

  // Auto-save
  private saveSubject = new Subject<void>();
  private isInitialized = false;

  // Status
  private statusSubject = new BehaviorSubject<'not_initialized' | 'initialized' | 'terminated'>('not_initialized');
  status$ = this.statusSubject.asObservable();

  // Events
  private completionSubject = new Subject<{ score?: number; status: string }>();
  completion$ = this.completionSubject.asObservable();

  constructor(private http: HttpClient) {
    // Auto-save debounced
    this.saveSubject.pipe(
      debounceTime(2000)
    ).subscribe(() => this.commitData());

    // Expose API immediately so SCORM content can find it when iframe loads
    this.exposeAPI();
    this.session_data = sessionStorage.getItem("SESSION");
    this.user_session = JSON.parse(atob(this.session_data));
    console.log('[SCORM] API adapter initialized and exposed to window');
  }

  /**
   * Initialize SCORM session for a specific lesson
   */
  initialize(enrollmentId: number, lessonId: number, existingData?: ScormData): boolean {
    console.log('[SCORM] Initializing session for enrollment:', enrollmentId, 'lesson:', lessonId);



    this.enrollmentId = enrollmentId;
    this.lessonId = lessonId;
    this.userId = this.user_session.id;
    this.tenantId = this.user_session.tenant_id;
    this.userName = this.user_session.first_name + " " +  this.user_session.last_name;

    // Initialize with default values
    this.cmiData = {
      'cmi.core.lesson_status': 'not attempted',
      'cmi.core.lesson_location': '',
      'cmi.core.score.raw': '',
      'cmi.core.score.min': '0',
      'cmi.core.score.max': '100',
      'cmi.core.session_time': '0000:00:00',
      'cmi.core.total_time': '0000:00:00',
      'cmi.suspend_data': '',
      'cmi.core.exit': '',
      'cmi.core.entry': 'ab-initio',
      'cmi.student_id': String(this.userId),
      'cmi.student_name': this.userName,
      'cmi.core.credit': 'credit',
      'cmi.core.lesson_mode': 'normal',
      ...existingData
    };

    // If resuming, set entry to resume
    if (existingData &&(existingData['cmi.core.lesson_location'] || existingData['cmi.suspend_data'])
    ) {
      this.cmiData['cmi.core.entry'] = 'resume';
    }

    this.isInitialized = true;
    this.statusSubject.next('initialized');

    console.log('[SCORM] Session initialized successfully');

    return true;
  }

  /**
   * Terminate SCORM session
   */
  terminate(): boolean {
    if (!this.isInitialized) return true;
    this.cmiData['cmi.core.exit'] = 'suspend';
    this.commitData();
    this.isInitialized = false;
    this.statusSubject.next('terminated');
    this.cmiData = {};
    this.enrollmentId = 0;
    this.lessonId = 0;
    return true;
  }

  /**
   * Get SCORM value
   */
  getValue(element: string): string {
    console.log('[SCORM] GetValue:', element);

    // Handle read-only elements even if not initialized
    if (element === 'cmi.learner._children') {
      return 'student_id,student_name,lesson_location,credit,lesson_status,entry,score,total_time,exit,session_time,lesson_mode';
    }

    if (element === 'cmi.learner.score._children') {
      return 'raw,min,max';
    }

    if (!this.isInitialized) {
      console.warn('[SCORM] GetValue called but not initialized, returning empty');
      return '';
    }

    const value = String(this.cmiData[element] ?? '');
    console.log('[SCORM] GetValue result:', value);
    return value;
  }

  /**
   * Set SCORM value
   */
  setValue(element: string, value: string): boolean {
    console.log('[SCORM] SetValue:', element, '=', value);

    if (!this.isInitialized) {
      console.warn('[SCORM] SetValue called but not initialized');
      // Still accept the value, will be committed when initialized
      this.cmiData[element] = value;
      return true;
    }

    // Check for read-only elements
    const readOnlyElements = ['cmi.learner._children', 'cmi.student_id', 'cmi.student_name', 'cmi.core.credit', 'cmi.core.entry', 'cmi.core.lesson_mode', 'cmi.core.total_time'];
    if (readOnlyElements.includes(element)) {
      console.error(`[SCORM] Element ${element} is read-only`);
      return false;
    }

    // Validate and set value
    this.cmiData[element] = value;
    this.isDirty = true;

    // Check for completion
    if (element === 'cmi.core.lesson_status') {
      this.handleStatusChange(value);
    }

    // Trigger auto-save
    this.saveSubject.next();

    return true;
  }

  /**
   * Commit data to server
   */
  commit(): boolean {
    console.log('[SCORM] Commit called');
    this.commitData();
    return true;
  }

  /**
   * Get last error
   */
  getLastError(): string {
    return '0'; // No error
  }

  /**
   * Get error string
   */
  getErrorString(errorCode: string): string {
    const errors: Record<string, string> = {
      '0': 'No Error',
      '101': 'General Exception',
      '201': 'Invalid Argument Error',
      '202': 'Element Cannot Have Children',
      '203': 'Element Not An Array',
      '301': 'Not Initialized',
      '401': 'Not Implemented Error',
      '402': 'Invalid Set Value',
      '403': 'Element Is Read Only',
      '404': 'Element Is Write Only',
      '405': 'Incorrect Data Type'
    };

    return errors[errorCode] || 'Unknown Error';
  }

  /**
   * Get diagnostic
   */
  getDiagnostic(errorCode: string): string {
    return this.getErrorString(errorCode);
  }

  // ==========================================
  // PRIVATE METHODS
  // ==========================================

  /**
   * Commit data to server
   */
  private commitData(): void {
    if (!this.isDirty || !this.isInitialized || !this.enrollmentId || !this.lessonId) {
      return;
    }

    console.log('[SCORM] Committing data to server');

    const auth = this.getAuthParams();

    this.http.post<any>(`${this.apiUrl}?action=scorm-commit`, {
      ...auth,
      enrollment_id: this.enrollmentId,
      lesson_id: this.lessonId,
      scorm_data: this.cmiData
    }).subscribe({
      next: () => {
        console.log('[SCORM] Data committed successfully');
        this.isDirty = false;
      },
      error: (err) => {
        console.error('[SCORM] Commit failed:', err);
      }
    });
  }

  /**
   * Handle lesson status change
   */
  private handleStatusChange(status: string): void {
    console.log('[SCORM] Status changed to:', status);

    const completedStatuses = ['completed', 'passed'];

    if (completedStatuses.includes(status.toLowerCase())) {
      const score = this.cmiData['cmi.core.score.raw'];
      this.completionSubject.next({
        status: status,
        score: score ? parseFloat(String(score)) : undefined
      });
    }
  }

  /**
   * Get auth parameters
   */
  private getAuthParams(): { token: string; user_id: number; tenant_id: number } {
    return {
      token: localStorage.getItem('token') || '',
      user_id: this.userId || parseInt(localStorage.getItem('user_id') || '0'),
      tenant_id: this.tenantId || parseInt(localStorage.getItem('tenant_id') || '0')
    };
  }

  /**
   * Expose SCORM API to window for content access
   * This is called in constructor so API is available before any SCORM content loads
   */
  private exposeAPI(): void {
    const self = this;

    // SCORM 1.2 API
    const API = {
      LMSInitialize: function(param?: string): string {
        console.log('[SCORM 1.2] LMSInitialize called');
        // Always return true - the content can start, we track internally
        return 'true';
      },
      LMSFinish: function(param?: string): string {
        console.log('[SCORM 1.2] LMSFinish called');
        self.terminate();
        return 'true';
      },
      LMSGetValue: function(element: string): string {
        return self.getValue(element);
      },
      LMSSetValue: function(element: string, value: string): string {
        return self.setValue(element, value) ? 'true' : 'false';
      },
      LMSCommit: function(param?: string): string {
        return self.commit() ? 'true' : 'false';
      },
      LMSGetLastError: function(): string {
        return self.getLastError();
      },
      LMSGetErrorString: function(code: string): string {
        return self.getErrorString(code);
      },
      LMSGetDiagnostic: function(code: string): string {
        return self.getDiagnostic(code);
      }
    };

    // SCORM 2004 API
    const API_1484_11 = {
      Initialize: function(param?: string): string {
        console.log('[SCORM 2004] Initialize called');
        return 'true';
      },
      Terminate: function(param?: string): string {
        console.log('[SCORM 2004] Terminate called');
        self.terminate();
        return 'true';
      },
      GetValue: function(element: string): string {
        return self.getValue(self.convert2004To12(element));
      },
      SetValue: function(element: string, value: string): string {
        return self.setValue(self.convert2004To12(element), value) ? 'true' : 'false';
      },
      Commit: function(param?: string): string {
        return self.commit() ? 'true' : 'false';
      },
      GetLastError: function(): string {
        return self.getLastError();
      },
      GetErrorString: function(code: string): string {
        return self.getErrorString(code);
      },
      GetDiagnostic: function(code: string): string {
        return self.getDiagnostic(code);
      }
    };

    // Expose to window object - this is where SCORM content looks for the API
    (window as any).API = API;
    (window as any).API_1484_11 = API_1484_11;

    console.log('[SCORM] API objects exposed:', {
      'window.API': typeof (window as any).API,
      'window.API_1484_11': typeof (window as any).API_1484_11
    });

  }

  /**
   * Convert SCORM 2004 element names to SCORM 1.2
   */
  private convert2004To12(element: string): string {
    const mappings: Record<string, string> = {
      'cmi.completion_status': 'cmi.core.lesson_status',
      'cmi.success_status': 'cmi.core.lesson_status',
      'cmi.location': 'cmi.core.lesson_location',
      'cmi.score.raw': 'cmi.core.score.raw',
      'cmi.score.min': 'cmi.core.score.min',
      'cmi.score.max': 'cmi.core.score.max',
      'cmi.session_time': 'cmi.core.session_time',
      'cmi.total_time': 'cmi.core.total_time',
      'cmi.exit': 'cmi.core.exit',
      'cmi.entry': 'cmi.core.entry',
      'cmi.learner_id': 'cmi.student_id',
      'cmi.learner_name': 'cmi.student_name',
      'cmi.credit': 'cmi.core.credit',
      'cmi.mode': 'cmi.core.lesson_mode'
    };

    return mappings[element] || element;
  }

  /**
   * Calculate session time in SCORM format
   */
  calculateSessionTime(startTime: Date): string {
    const now = new Date();
    const diffMs = now.getTime() - startTime.getTime();

    const hours = Math.floor(diffMs / 3600000);
    const minutes = Math.floor((diffMs % 3600000) / 60000);
    const seconds = Math.floor((diffMs % 60000) / 1000);

    return `${hours.toString().padStart(4, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
  }

  /**
   * Get current data
   */
  getData(): ScormData {
    return { ...this.cmiData };
  }

  /**
   * Check if content is completed
   */
  isCompleted(): boolean {
    const status = String(this.cmiData['cmi.core.lesson_status'] || '').toLowerCase();
    return ['completed', 'passed'].includes(status);
  }

  /**
   * Get score
   */
  getScore(): number | null {
    const raw = this.cmiData['cmi.core.score.raw'];
    return raw ? parseFloat(String(raw)) : null;
  }
}
