export class ScormAPI {
  private data: Record<string, string> = {};
  private startTime: number = Date.now();

  // ===== SCORM 1.2 =====
  LMSInitialize = () => 'true';
  LMSFinish = () => {
    this.saveTime();
    return 'true';
  };

  LMSGetValue = (key: string) => this.data[key] || '';
  LMSSetValue = (key: string, value: string) => {
    this.data[key] = value;
    return 'true';
  };

  LMSCommit = () => {
    this.syncToBackend();
    return 'true';
  };

  LMSGetLastError = () => '0';
  LMSGetErrorString = () => 'No error';
  LMSGetDiagnostic = () => 'OK';

  // ===== SCORM 2004 =====
  Initialize = () => 'true';
  Terminate = () => {
    this.saveTime();
    return 'true';
  };
  GetValue = (key: string) => this.data[key] || '';
  SetValue = (key: string, value: string) => {
    this.data[key] = value;
    return 'true';
  };
  Commit = () => {
    this.syncToBackend();
    return 'true';
  };
  GetLastError = () => '0';
  GetErrorString = () => 'No error';
  GetDiagnostic = () => 'OK';

  // ===== Tracking helpers =====
  private saveTime() {
    const elapsedSeconds = Math.floor((Date.now() - this.startTime) / 1000);
    this.data['cmi.core.session_time'] = elapsedSeconds.toString();
  }

  private syncToBackend() {
    // Replace with your API endpoint
    fetch('https://api.centrallms.com/scorm-sync', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        tenant_id: 1,
        user_id: 123,
        course_id: 456,
        data: this.data,
      }),
    }).catch(console.error);
  }
}
